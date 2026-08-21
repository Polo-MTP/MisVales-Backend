<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearGerenteGeneral(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente General']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

function crearGerenteDeSucursal(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente de Sucursal']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

it('el Gerente General puede crear una categoría de distribuidora', function (): void {
    Sanctum::actingAs(crearGerenteGeneral());

    $response = $this->postJson('/api/v1/categorias-distribuidoras', [
        'nombre' => 'DIAMANTE',
        'porcentaje_comision' => 5.5,
        'descripcion' => 'Categoría nueva',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.nombre', 'DIAMANTE')
        ->assertJsonPath('data.porcentaje_comision', '5.50');

    expect(CategoriaDistribuidora::where('nombre', 'DIAMANTE')->exists())->toBeTrue();
});

it('el Gerente de Sucursal no puede crear una categoría de distribuidora', function (): void {
    Sanctum::actingAs(crearGerenteDeSucursal());

    $response = $this->postJson('/api/v1/categorias-distribuidoras', [
        'nombre' => 'DIAMANTE',
        'porcentaje_comision' => 5.5,
    ]);

    $response->assertStatus(403);
});

it('no permite dos categorías con el mismo nombre', function (): void {
    Sanctum::actingAs(crearGerenteGeneral());
    CategoriaDistribuidora::create(['nombre' => 'ORO', 'porcentaje_comision' => 4, 'activo' => true]);

    $response = $this->postJson('/api/v1/categorias-distribuidoras', [
        'nombre' => 'ORO',
        'porcentaje_comision' => 10,
    ]);

    $response->assertStatus(422);
});

it('el Gerente General puede actualizar el porcentaje de una categoría', function (): void {
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA', 'porcentaje_comision' => 6, 'activo' => true]);
    Sanctum::actingAs(crearGerenteGeneral());

    $response = $this->putJson("/api/v1/categorias-distribuidoras/{$categoria->id}", [
        'nombre' => 'PLATA',
        'porcentaje_comision' => 7.25,
    ]);

    $response->assertStatus(200)->assertJsonPath('data.porcentaje_comision', '7.25');
    expect((float) $categoria->fresh()->porcentaje_comision)->toBe(7.25);
});

it('el Gerente General puede desactivar una categoría sin borrarla', function (): void {
    $categoria = CategoriaDistribuidora::create(['nombre' => 'BRONCE', 'porcentaje_comision' => 1.5, 'activo' => true]);
    Sanctum::actingAs(crearGerenteGeneral());

    $response = $this->deleteJson("/api/v1/categorias-distribuidoras/{$categoria->id}");

    $response->assertStatus(200);
    expect($categoria->fresh())
        ->activo->toBeFalse()
        ->and(CategoriaDistribuidora::find($categoria->id))->not->toBeNull();
});

it('el listado por defecto no incluye categorías desactivadas', function (): void {
    CategoriaDistribuidora::create(['nombre' => 'ACTIVA', 'porcentaje_comision' => 3, 'activo' => true]);
    CategoriaDistribuidora::create(['nombre' => 'INACTIVA', 'porcentaje_comision' => 3, 'activo' => false]);
    Sanctum::actingAs(crearGerenteGeneral());

    $response = $this->getJson('/api/v1/categorias-distribuidoras');

    $response->assertStatus(200)->assertJsonCount(1, 'data');
});
