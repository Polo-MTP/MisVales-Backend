<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'Gerente General']);
    Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    Role::firstOrCreate(['name' => 'Coordinador']);
    Role::firstOrCreate(['name' => 'Verificador']);
    Role::firstOrCreate(['name' => 'Cajera']);
});

function crearSucursalPersonal(): Sucursal
{
    return Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
}

function crearGerenteGeneralPersonal(): User
{
    return User::factory()->create(['role_id' => Role::where('name', 'Gerente General')->first()->id, 'is_active' => true]);
}

function crearGerenteDeSucursalPersonal(Sucursal $sucursal): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'Gerente de Sucursal')->first()->id,
        'sucursal_id' => $sucursal->id,
        'is_active' => true,
    ]);
}

it('el Gerente General puede dar de alta Coordinador, Verificador o Cajera indicando sucursal y gerente', function (string $rol): void {
    $sucursal = crearSucursalPersonal();
    $gerente = crearGerenteDeSucursalPersonal($sucursal);
    Sanctum::actingAs(crearGerenteGeneralPersonal());

    $response = $this->postJson('/api/v1/usuarios/personal', [
        'rol' => $rol,
        'name' => 'Nuevo Personal',
        'email' => 'nuevo.personal.'.strtolower($rol).'@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'sucursal_id' => $sucursal->id,
        'gerente_id' => $gerente->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.role.name', $rol)
        ->assertJsonPath('data.sucursal_id', $sucursal->id)
        ->assertJsonPath('data.gerente_id', $gerente->id);
})->with(['Coordinador', 'Verificador', 'Cajera']);

it('el Gerente General no puede asignar un gerente que no es Gerente de Sucursal de esa sucursal', function (): void {
    $sucursal = crearSucursalPersonal();
    $otraSucursal = Sucursal::create(['nombre' => 'Sucursal 2', 'codigo' => 'SUC-002', 'es_matriz' => false, 'is_active' => true]);
    $gerenteDeOtraSucursal = crearGerenteDeSucursalPersonal($otraSucursal);
    Sanctum::actingAs(crearGerenteGeneralPersonal());

    $response = $this->postJson('/api/v1/usuarios/personal', [
        'rol' => 'Cajera',
        'name' => 'Nueva Cajera',
        'email' => 'cajera.mismatch@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'sucursal_id' => $sucursal->id,
        'gerente_id' => $gerenteDeOtraSucursal->id,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('gerente_id');
    expect(User::where('email', 'cajera.mismatch@example.com')->exists())->toBeFalse();
});

it('el Gerente de Sucursal da de alta personal relacionado automáticamente a sí mismo y a su sucursal, ignorando cualquier sucursal_id/gerente_id que mande', function (): void {
    $sucursal = crearSucursalPersonal();
    $otraSucursal = Sucursal::create(['nombre' => 'Sucursal 2', 'codigo' => 'SUC-002', 'es_matriz' => false, 'is_active' => true]);
    $gerente = crearGerenteDeSucursalPersonal($sucursal);
    $otroGerente = crearGerenteDeSucursalPersonal($otraSucursal);
    Sanctum::actingAs($gerente);

    $response = $this->postJson('/api/v1/usuarios/personal', [
        'rol' => 'Verificador',
        'name' => 'Nuevo Verificador',
        'email' => 'verificador.auto@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'sucursal_id' => $otraSucursal->id,
        'gerente_id' => $otroGerente->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.sucursal_id', $sucursal->id)
        ->assertJsonPath('data.gerente_id', $gerente->id);
});

it('rechaza un rol distinto a Coordinador, Verificador o Cajera', function (): void {
    $sucursal = crearSucursalPersonal();
    $gerente = crearGerenteDeSucursalPersonal($sucursal);
    Sanctum::actingAs(crearGerenteGeneralPersonal());

    $response = $this->postJson('/api/v1/usuarios/personal', [
        'rol' => 'Administrador',
        'name' => 'Intento Admin',
        'email' => 'intento.admin@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'sucursal_id' => $sucursal->id,
        'gerente_id' => $gerente->id,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('rol');
});

it('rechaza dar de alta personal en una sucursal deshabilitada', function (): void {
    $sucursalInactiva = Sucursal::create(['nombre' => 'Cerrada', 'codigo' => 'SUC-CERRADA', 'es_matriz' => false, 'is_active' => false]);
    $gerente = crearGerenteDeSucursalPersonal($sucursalInactiva);
    Sanctum::actingAs(crearGerenteGeneralPersonal());

    $response = $this->postJson('/api/v1/usuarios/personal', [
        'rol' => 'Cajera',
        'name' => 'Cajera Sucursal Cerrada',
        'email' => 'sucursal.cerrada@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'sucursal_id' => $sucursalInactiva->id,
        'gerente_id' => $gerente->id,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('sucursal_id');
    expect(User::where('email', 'sucursal.cerrada@example.com')->exists())->toBeFalse();
});

it('rechaza asignar personal a un Gerente de Sucursal deshabilitado', function (): void {
    $sucursal = crearSucursalPersonal();
    $gerenteInactivo = User::factory()->create([
        'role_id' => Role::where('name', 'Gerente de Sucursal')->first()->id,
        'sucursal_id' => $sucursal->id,
        'is_active' => false,
    ]);
    Sanctum::actingAs(crearGerenteGeneralPersonal());

    $response = $this->postJson('/api/v1/usuarios/personal', [
        'rol' => 'Cajera',
        'name' => 'Cajera Gerente Inactivo',
        'email' => 'gerente.inactivo@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'sucursal_id' => $sucursal->id,
        'gerente_id' => $gerenteInactivo->id,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('gerente_id');
    expect(User::where('email', 'gerente.inactivo@example.com')->exists())->toBeFalse();
});

it('ningún otro rol puede dar de alta personal de sucursal', function (): void {
    $sucursal = crearSucursalPersonal();
    Sanctum::actingAs(User::factory()->create([
        'role_id' => Role::where('name', 'Coordinador')->first()->id,
        'sucursal_id' => $sucursal->id,
        'is_active' => true,
    ]));

    $response = $this->postJson('/api/v1/usuarios/personal', [
        'rol' => 'Cajera',
        'name' => 'Nueva Cajera',
        'email' => 'no.autorizado@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'sucursal_id' => $sucursal->id,
    ]);

    $response->assertStatus(403);
});
