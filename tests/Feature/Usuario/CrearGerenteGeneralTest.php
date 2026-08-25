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
    Role::firstOrCreate(['name' => 'Gerente General']);
    Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-MATRIZ', 'es_matriz' => true, 'is_active' => true]);
    Mail::fake();
});

function crearAdministradorActor(): User
{
    $role = Role::firstOrCreate(['name' => 'Administrador'], ['factor_count' => 3]);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

function crearGerenteGeneralExistente(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente General']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

/** Ver nota en CrearGerenteSucursalTest.php -- RFC/CURP exactos porque estos tests SÍ pegan por HTTP. */
function datosPersonalesValidosGG(array $overrides = []): array
{
    static $contador = 0;
    $contador++;

    return array_merge([
        'rfc' => 'RFC'.str_pad((string) (300000000 + $contador), 10, '0', STR_PAD_LEFT),
        'nombre' => 'Prueba',
        'apellido_paterno' => 'Apellido',
        'apellido_materno' => 'Materno',
        'curp' => 'CURP'.str_pad((string) (30000000000000 + $contador), 14, '0', STR_PAD_LEFT),
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

it('el Administrador puede dar de alta un Gerente General, con contraseña generada y enviada por correo', function (): void {
    Sanctum::actingAs(crearAdministradorActor());

    $response = $this->postJson('/api/v1/usuarios/gerente-general', datosPersonalesValidosGG([
        'nombre' => 'Nuevo', 'apellido_paterno' => 'GG', 'apellido_materno' => null,
        'email' => 'nuevo.gg@example.com',
    ]));

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Nuevo GG')
        ->assertJsonPath('data.role.name', 'Gerente General')
        ->assertJsonMissingPath('data.password');

    $creado = User::where('email', 'nuevo.gg@example.com')->first();
    expect($creado)->not->toBeNull()
        ->and($creado->is_active)->toBeTrue()
        ->and($creado->email_verified_at)->not->toBeNull()
        ->and($creado->role->name)->toBe('Gerente General')
        ->and($creado->sucursal->es_matriz)->toBeTrue()
        ->and($creado->datos_id)->not->toBeNull()
        ->and($creado->rfc)->not->toBeNull();

    Mail::assertSent(PersonalCredencialesMail::class, fn ($mail) => $mail->hasTo('nuevo.gg@example.com') && strlen($mail->password) >= 22);
});

it('el Gerente General NO puede dar de alta otro Gerente General -- solo Administrador, para que la cadena de mando no se auto-perpetúe', function (): void {
    Sanctum::actingAs(crearGerenteGeneralExistente());

    $response = $this->postJson('/api/v1/usuarios/gerente-general', datosPersonalesValidosGG([
        'nombre' => 'Otro', 'apellido_paterno' => 'GG', 'apellido_materno' => null,
        'email' => 'otro.gg@example.com',
    ]));

    $response->assertStatus(403);
    expect(User::where('email', 'otro.gg@example.com')->exists())->toBeFalse();
});

it('el Administrador NO puede dar de alta un segundo Gerente General si ya existe uno (activo o no)', function (): void {
    crearGerenteGeneralExistente();
    Sanctum::actingAs(crearAdministradorActor());

    $response = $this->postJson('/api/v1/usuarios/gerente-general', datosPersonalesValidosGG([
        'nombre' => 'Segundo', 'apellido_paterno' => 'GG', 'apellido_materno' => null,
        'email' => 'segundo.gg@example.com',
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors('role');
    expect(User::where('email', 'segundo.gg@example.com')->exists())->toBeFalse();
});

it('el Administrador NO puede dar de alta un segundo Gerente General ni aunque el existente esté desactivado', function (): void {
    $rolGG = Role::firstOrCreate(['name' => 'Gerente General']);
    User::factory()->create(['role_id' => $rolGG->id, 'is_active' => false]);
    Sanctum::actingAs(crearAdministradorActor());

    $response = $this->postJson('/api/v1/usuarios/gerente-general', datosPersonalesValidosGG([
        'nombre' => 'Segundo', 'apellido_paterno' => 'GG', 'apellido_materno' => null,
        'email' => 'segundo.gg.b@example.com',
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors('role');
    expect(User::where('email', 'segundo.gg.b@example.com')->exists())->toBeFalse();
});

it('no se puede colar otro rol -- el endpoint siempre crea Gerente General, ignora cualquier role_id que manden', function (): void {
    Sanctum::actingAs(crearAdministradorActor());

    $response = $this->postJson('/api/v1/usuarios/gerente-general', datosPersonalesValidosGG([
        'nombre' => 'Intento', 'apellido_paterno' => 'Otro', 'apellido_materno' => null,
        'email' => 'intento@example.com',
        'role_id' => 999,
        'role' => 'Gerente de Sucursal',
    ]));

    $response->assertStatus(201);
    expect(User::where('email', 'intento@example.com')->first()->role->name)->toBe('Gerente General');
});

it('el Gerente de Sucursal NO puede dar de alta un Gerente General -- escalaría su alcance', function (): void {
    $role = Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    $sucursal = Sucursal::create(['nombre' => 'Otra', 'codigo' => 'SUC-OTRA', 'es_matriz' => false, 'is_active' => true]);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]));

    $response = $this->postJson('/api/v1/usuarios/gerente-general', datosPersonalesValidosGG([
        'nombre' => 'Nuevo', 'apellido_paterno' => 'GG', 'apellido_materno' => null,
        'email' => 'gs.intento@example.com',
    ]));

    $response->assertStatus(403);
    expect(User::where('email', 'gs.intento@example.com')->exists())->toBeFalse();
});

it('ningún otro rol puede dar de alta un Gerente General', function (): void {
    $role = Role::firstOrCreate(['name' => 'Coordinador']);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

    $response = $this->postJson('/api/v1/usuarios/gerente-general', datosPersonalesValidosGG([
        'nombre' => 'Nuevo', 'apellido_paterno' => 'GG', 'apellido_materno' => null,
        'email' => 'otro.rol@example.com',
    ]));

    $response->assertStatus(403);
    expect(User::where('email', 'otro.rol@example.com')->exists())->toBeFalse();
});

it('permite dar de alta un Gerente General desde la red pública -- crear cuentas de staff no exige VPN', function (): void {
    config(['security.vpn_host' => 'vpn.misvales.test']);
    Sanctum::actingAs(crearAdministradorActor());

    $response = $this->postJson('http://api.misvales.test/api/v1/usuarios/gerente-general', datosPersonalesValidosGG([
        'nombre' => 'Nuevo', 'apellido_paterno' => 'GG', 'apellido_materno' => null,
        'email' => 'desde.publica@example.com',
    ]));

    $response->assertStatus(201);
    expect(User::where('email', 'desde.publica@example.com')->exists())->toBeTrue();
});

it('no permite dar de alta un Gerente General si no existe ninguna sucursal matriz', function (): void {
    Sucursal::query()->where('es_matriz', true)->delete();
    Sanctum::actingAs(crearAdministradorActor());

    $response = $this->postJson('/api/v1/usuarios/gerente-general', datosPersonalesValidosGG([
        'nombre' => 'Nuevo', 'apellido_paterno' => 'GG', 'apellido_materno' => null,
        'email' => 'sin.matriz@example.com',
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors('sucursal_id');
    expect(User::where('email', 'sin.matriz@example.com')->exists())->toBeFalse();
});
