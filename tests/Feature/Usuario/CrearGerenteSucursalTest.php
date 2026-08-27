<?php

declare(strict_types=1);

use App\Mail\PersonalCredencialesMail;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    Mail::fake();
});

function crearGerenteGeneralUsr(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente General']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

function crearAdministradorUsrGS(): User
{
    $role = Role::firstOrCreate(['name' => 'Administrador'], ['factor_count' => 3]);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

/** Ver nota en CrearAdministradorTest.php -- RFC/CURP exactos porque estos tests SÍ pegan por HTTP. */
function datosPersonalesValidosGS(array $overrides = []): array
{
    static $contador = 0;
    $contador++;

    return array_merge([
        'rfc' => 'RFC'.str_pad((string) (200000000 + $contador), 10, '0', STR_PAD_LEFT),
        'nombre' => 'Prueba',
        'apellido_paterno' => 'Apellido',
        'apellido_materno' => 'Materno',
        'curp' => 'CURP'.str_pad((string) (20000000000000 + $contador), 14, '0', STR_PAD_LEFT),
        'fecha_nacimiento' => '1990-01-01',
        'lugar_nacimiento' => 'Torreón',
        'calle' => 'Calle de Prueba',
        'colonia' => 'Centro',
        'numero_ext' => '100',
        'codigo_postal' => '35000',
        'estado' => 'Durango',
        'ciudad' => 'Gómez Palacio',
        'referencia_laboral' => 'Referencia de prueba',
    ], $overrides);
}

it('el Gerente General puede dar de alta un Gerente de Sucursal, con contraseña generada y enviada por correo', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    Sanctum::actingAs(crearGerenteGeneralUsr());

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', datosPersonalesValidosGS([
        'nombre' => 'Nuevo', 'apellido_paterno' => 'Gerente', 'apellido_materno' => null,
        'email' => 'nuevo.gerente@example.com',
        'sucursal_id' => $sucursal->id,
    ]));

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Nuevo Gerente')
        ->assertJsonPath('data.role.name', 'Gerente de Sucursal')
        ->assertJsonPath('data.sucursal_id', $sucursal->id)
        ->assertJsonMissingPath('data.password');

    $creado = User::where('email', 'nuevo.gerente@example.com')->first();
    expect($creado)->not->toBeNull()
        ->and($creado->is_active)->toBeTrue()
        ->and($creado->email_verified_at)->not->toBeNull()
        ->and($creado->role->name)->toBe('Gerente de Sucursal')
        ->and($creado->datos_id)->not->toBeNull()
        ->and($creado->rfc)->not->toBeNull();

    Mail::assertSent(PersonalCredencialesMail::class, fn ($mail) => $mail->hasTo('nuevo.gerente@example.com') && strlen($mail->password) >= 22);
});

it('el Administrador puede dar de alta un Gerente de Sucursal, con contraseña generada y enviada por correo', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    Sanctum::actingAs(crearAdministradorUsrGS());

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', datosPersonalesValidosGS([
        'nombre' => 'Nuevo', 'apellido_paterno' => 'Gerente', 'apellido_materno' => null,
        'email' => 'nuevo.gerente.admin@example.com',
        'sucursal_id' => $sucursal->id,
    ]));

    $response->assertStatus(201)
        ->assertJsonPath('data.role.name', 'Gerente de Sucursal')
        ->assertJsonPath('data.sucursal_id', $sucursal->id);

    Mail::assertSent(PersonalCredencialesMail::class, fn ($mail) => $mail->hasTo('nuevo.gerente.admin@example.com'));
});

it('no se puede colar otro rol -- el endpoint siempre crea Gerente de Sucursal, ignora cualquier role_id que manden', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    Sanctum::actingAs(crearGerenteGeneralUsr());

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', datosPersonalesValidosGS([
        'nombre' => 'Intento', 'apellido_paterno' => 'Admin', 'apellido_materno' => null,
        'email' => 'intento@example.com',
        'sucursal_id' => $sucursal->id,
        'role_id' => 999,
        'role' => 'Administrador',
    ]));

    $response->assertStatus(201);
    expect(User::where('email', 'intento@example.com')->first()->role->name)->toBe('Gerente de Sucursal');
});

it('ningún otro rol puede dar de alta un Gerente de Sucursal', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $role = Role::firstOrCreate(['name' => 'Coordinador']);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]));

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', datosPersonalesValidosGS([
        'nombre' => 'Nuevo', 'apellido_paterno' => 'Gerente', 'apellido_materno' => null,
        'email' => 'otro@example.com',
        'sucursal_id' => $sucursal->id,
    ]));

    $response->assertStatus(403);
});

it('rechaza dar de alta un Gerente de Sucursal en una sucursal deshabilitada', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Cerrada', 'codigo' => 'SUC-CERRADA', 'es_matriz' => false, 'is_active' => false]);
    Sanctum::actingAs(crearGerenteGeneralUsr());

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', datosPersonalesValidosGS([
        'nombre' => 'Gerente', 'apellido_paterno' => 'Sucursal', 'apellido_materno' => 'Cerrada',
        'email' => 'cerrada@example.com',
        'sucursal_id' => $sucursal->id,
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors('sucursal_id');
    expect(User::where('email', 'cerrada@example.com')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('rechaza dar de alta un segundo Gerente de Sucursal activo en una sucursal que ya tiene uno', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $rolGS = Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    User::factory()->create(['role_id' => $rolGS->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    Sanctum::actingAs(crearGerenteGeneralUsr());

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', datosPersonalesValidosGS([
        'nombre' => 'Segundo', 'apellido_paterno' => 'Gerente', 'apellido_materno' => null,
        'email' => 'segundo.gerente@example.com',
        'sucursal_id' => $sucursal->id,
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors('sucursal_id');
    expect(User::where('email', 'segundo.gerente@example.com')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('sí permite dar de alta un Gerente de Sucursal si el anterior de esa sucursal ya está desactivado', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $rolGS = Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    User::factory()->create(['role_id' => $rolGS->id, 'sucursal_id' => $sucursal->id, 'is_active' => false]);
    Sanctum::actingAs(crearGerenteGeneralUsr());

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', datosPersonalesValidosGS([
        'nombre' => 'Reemplazo', 'apellido_paterno' => 'Gerente', 'apellido_materno' => null,
        'email' => 'reemplazo.gerente@example.com',
        'sucursal_id' => $sucursal->id,
    ]));

    $response->assertStatus(201);
    expect(User::where('email', 'reemplazo.gerente@example.com')->exists())->toBeTrue();
});
