<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
});

function crearGerenteGeneralUsr(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente General']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

it('el Gerente General puede dar de alta un Gerente de Sucursal', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    Sanctum::actingAs(crearGerenteGeneralUsr());

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', [
        'name' => 'Nuevo Gerente',
        'email' => 'nuevo.gerente@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'sucursal_id' => $sucursal->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Nuevo Gerente')
        ->assertJsonPath('data.role.name', 'Gerente de Sucursal')
        ->assertJsonPath('data.sucursal_id', $sucursal->id);

    $creado = User::where('email', 'nuevo.gerente@example.com')->first();
    expect($creado)->not->toBeNull()
        ->and($creado->is_active)->toBeTrue()
        ->and($creado->email_verified_at)->not->toBeNull()
        ->and($creado->role->name)->toBe('Gerente de Sucursal');
});

it('no se puede colar otro rol -- el endpoint siempre crea Gerente de Sucursal, ignora cualquier role_id que manden', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    Sanctum::actingAs(crearGerenteGeneralUsr());

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', [
        'name' => 'Intento Admin',
        'email' => 'intento@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'sucursal_id' => $sucursal->id,
        'role_id' => 999,
        'role' => 'Administrador',
    ]);

    $response->assertStatus(201);
    expect(User::where('email', 'intento@example.com')->first()->role->name)->toBe('Gerente de Sucursal');
});

it('ningún otro rol puede dar de alta un Gerente de Sucursal', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $role = Role::firstOrCreate(['name' => 'Coordinador']);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]));

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', [
        'name' => 'Nuevo Gerente',
        'email' => 'otro@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'sucursal_id' => $sucursal->id,
    ]);

    $response->assertStatus(403);
});

it('rechaza una contraseña que no cumple la política mínima', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    Sanctum::actingAs(crearGerenteGeneralUsr());

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', [
        'name' => 'Nuevo Gerente',
        'email' => 'debil@example.com',
        'password' => 'abc',
        'password_confirmation' => 'abc',
        'sucursal_id' => $sucursal->id,
    ]);

    $response->assertStatus(422);
});
