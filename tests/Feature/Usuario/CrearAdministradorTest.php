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
    Role::firstOrCreate(['name' => 'Administrador'], ['factor_count' => 3]);
    Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-MATRIZ', 'es_matriz' => true, 'is_active' => true]);
    Mail::fake();
});

function crearGerenteGeneralAdmin(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente General']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

function crearGerenteSucursalAdmin(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    $sucursal = Sucursal::create(['nombre' => 'Otra', 'codigo' => 'SUC-OTRA', 'es_matriz' => false, 'is_active' => true]);

    return User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
}

it('el Gerente General puede dar de alta un Administrador, con contraseña generada y enviada por correo', function (): void {
    Sanctum::actingAs(crearGerenteGeneralAdmin());

    $response = $this->postJson('/api/v1/usuarios/administrador', [
        'name' => 'Nuevo Admin',
        'email' => 'nuevo.admin@example.com',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Nuevo Admin')
        ->assertJsonPath('data.role.name', 'Administrador')
        ->assertJsonMissingPath('data.password');

    $creado = User::where('email', 'nuevo.admin@example.com')->first();
    expect($creado)->not->toBeNull()
        ->and($creado->is_active)->toBeTrue()
        ->and($creado->email_verified_at)->not->toBeNull()
        ->and($creado->role->name)->toBe('Administrador')
        ->and($creado->sucursal->es_matriz)->toBeTrue();

    Mail::assertSent(PersonalCredencialesMail::class, fn ($mail) => $mail->hasTo('nuevo.admin@example.com') && strlen($mail->password) >= 22);
});

it('el Gerente de Sucursal NO puede dar de alta un Administrador -- escalaría su alcance más allá de su sucursal', function (): void {
    Sanctum::actingAs(crearGerenteSucursalAdmin());

    $response = $this->postJson('/api/v1/usuarios/administrador', [
        'name' => 'Admin Desde Sucursal',
        'email' => 'admin.sucursal@example.com',
    ]);

    $response->assertStatus(403);
    expect(User::where('email', 'admin.sucursal@example.com')->exists())->toBeFalse();
});

it('no se puede colar otro rol -- el endpoint siempre crea Administrador, ignora cualquier role_id que manden', function (): void {
    Sanctum::actingAs(crearGerenteGeneralAdmin());

    $response = $this->postJson('/api/v1/usuarios/administrador', [
        'name' => 'Intento Coordinador',
        'email' => 'intento@example.com',
        'role_id' => 999,
        'role' => 'Coordinador',
    ]);

    $response->assertStatus(201);
    expect(User::where('email', 'intento@example.com')->first()->role->name)->toBe('Administrador');
});

it('ningún otro rol puede dar de alta un Administrador', function (): void {
    $role = Role::firstOrCreate(['name' => 'Coordinador']);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

    $response = $this->postJson('/api/v1/usuarios/administrador', [
        'name' => 'Nuevo Admin',
        'email' => 'otro@example.com',
    ]);

    $response->assertStatus(403);
    expect(User::where('email', 'otro@example.com')->exists())->toBeFalse();
});

it('permite dar de alta un Administrador desde la red pública -- crear cuentas de staff no exige VPN', function (): void {
    config(['security.vpn_host' => 'vpn.misvales.test']);
    Sanctum::actingAs(crearGerenteGeneralAdmin());

    $response = $this->postJson('http://api.misvales.test/api/v1/usuarios/administrador', [
        'name' => 'Nuevo Admin',
        'email' => 'desde.publica@example.com',
    ]);

    $response->assertStatus(201);
    expect(User::where('email', 'desde.publica@example.com')->exists())->toBeTrue();
});
