<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearGerenteGeneralSuc(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente General']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

it('el Gerente General puede dar de alta una sucursal', function (): void {
    Sanctum::actingAs(crearGerenteGeneralSuc());

    $response = $this->postJson('/api/v1/sucursales', [
        'nombre' => 'Sucursal Norte',
        'codigo' => 'SUC-NORTE',
    ]);

    $response->assertStatus(201)->assertJsonPath('data.nombre', 'Sucursal Norte');
    expect(Sucursal::where('codigo', 'SUC-NORTE')->exists())->toBeTrue();
});

it('un Gerente de Sucursal no puede dar de alta ni editar sucursales', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $role = Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    $gerente = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    Sanctum::actingAs($gerente);

    $this->postJson('/api/v1/sucursales', ['nombre' => 'Otra', 'codigo' => 'SUC-OTRA'])->assertStatus(403);
    $this->putJson('/api/v1/sucursales/'.$sucursal->id, ['nombre' => 'Cambiada', 'codigo' => 'SUC-001'])->assertStatus(403);
});

it('no permite dos sucursales con el mismo código', function (): void {
    Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    Sanctum::actingAs(crearGerenteGeneralSuc());

    $response = $this->postJson('/api/v1/sucursales', ['nombre' => 'Otra', 'codigo' => 'SUC-001']);

    $response->assertStatus(422);
});

it('cualquier usuario autenticado puede listar sucursales activas', function (): void {
    Sucursal::create(['nombre' => 'Activa', 'codigo' => 'SUC-A', 'es_matriz' => false, 'is_active' => true]);
    Sucursal::create(['nombre' => 'Inactiva', 'codigo' => 'SUC-B', 'es_matriz' => false, 'is_active' => false]);

    $role = Role::firstOrCreate(['name' => 'Cajera']);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

    $response = $this->getJson('/api/v1/sucursales');

    $response->assertStatus(200)->assertJsonCount(1, 'data');
});

it('el Gerente General puede editar y desactivar una sucursal', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    Sanctum::actingAs(crearGerenteGeneralSuc());

    $response = $this->putJson('/api/v1/sucursales/'.$sucursal->id, [
        'nombre' => 'Matriz Renombrada',
        'codigo' => 'SUC-001',
        'is_active' => false,
    ]);

    $response->assertStatus(200);
    expect($sucursal->fresh())
        ->nombre->toBe('Matriz Renombrada')
        ->is_active->toBeFalse();
});
