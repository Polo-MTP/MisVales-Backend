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

function crearAdministradorParaGG(): User
{
    $role = Role::firstOrCreate(['name' => 'Administrador'], ['factor_count' => 3]);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

function crearGerenteGeneralExistente(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente General']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

it('el Administrador puede dar de alta un Gerente General, con contraseña generada y enviada por correo', function (): void {
    Sanctum::actingAs(crearAdministradorParaGG());

    $response = $this->postJson('/api/v1/usuarios/gerente-general', [
        'name' => 'Nuevo GG',
        'email' => 'nuevo.gg@example.com',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Nuevo GG')
        ->assertJsonPath('data.role.name', 'Gerente General')
        ->assertJsonMissingPath('data.password');

    $creado = User::where('email', 'nuevo.gg@example.com')->first();
    expect($creado)->not->toBeNull()
        ->and($creado->is_active)->toBeTrue()
        ->and($creado->email_verified_at)->not->toBeNull()
        ->and($creado->role->name)->toBe('Gerente General')
        ->and($creado->sucursal->es_matriz)->toBeTrue();

    Mail::assertSent(PersonalCredencialesMail::class, fn ($mail) => $mail->hasTo('nuevo.gg@example.com') && strlen($mail->password) >= 22);
});

it('el Gerente General también puede dar de alta otro Gerente General', function (): void {
    Sanctum::actingAs(crearGerenteGeneralExistente());

    $response = $this->postJson('/api/v1/usuarios/gerente-general', [
        'name' => 'Otro GG',
        'email' => 'otro.gg@example.com',
    ]);

    $response->assertStatus(201)->assertJsonPath('data.role.name', 'Gerente General');
    expect(User::where('email', 'otro.gg@example.com')->exists())->toBeTrue();
});

it('no se puede colar otro rol -- el endpoint siempre crea Gerente General, ignora cualquier role_id que manden', function (): void {
    Sanctum::actingAs(crearAdministradorParaGG());

    $response = $this->postJson('/api/v1/usuarios/gerente-general', [
        'name' => 'Intento Admin',
        'email' => 'intento@example.com',
        'role_id' => 999,
        'role' => 'Administrador',
    ]);

    $response->assertStatus(201);
    expect(User::where('email', 'intento@example.com')->first()->role->name)->toBe('Gerente General');
});

it('el Gerente de Sucursal NO puede dar de alta un Gerente General -- escalaría su alcance', function (): void {
    $role = Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    $sucursal = Sucursal::create(['nombre' => 'Otra', 'codigo' => 'SUC-OTRA', 'es_matriz' => false, 'is_active' => true]);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]));

    $response = $this->postJson('/api/v1/usuarios/gerente-general', [
        'name' => 'Nuevo GG',
        'email' => 'gs.intento@example.com',
    ]);

    $response->assertStatus(403);
    expect(User::where('email', 'gs.intento@example.com')->exists())->toBeFalse();
});

it('ningún otro rol puede dar de alta un Gerente General', function (): void {
    $role = Role::firstOrCreate(['name' => 'Coordinador']);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

    $response = $this->postJson('/api/v1/usuarios/gerente-general', [
        'name' => 'Nuevo GG',
        'email' => 'otro.rol@example.com',
    ]);

    $response->assertStatus(403);
    expect(User::where('email', 'otro.rol@example.com')->exists())->toBeFalse();
});

it('permite dar de alta un Gerente General desde la red pública -- crear cuentas de staff no exige VPN', function (): void {
    config(['security.vpn_host' => 'vpn.misvales.test']);
    Sanctum::actingAs(crearAdministradorParaGG());

    $response = $this->postJson('http://api.misvales.test/api/v1/usuarios/gerente-general', [
        'name' => 'Nuevo GG',
        'email' => 'desde.publica@example.com',
    ]);

    $response->assertStatus(201);
    expect(User::where('email', 'desde.publica@example.com')->exists())->toBeTrue();
});
