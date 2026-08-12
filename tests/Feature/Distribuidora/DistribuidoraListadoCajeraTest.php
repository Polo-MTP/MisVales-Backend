<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearSucursalConDistribuidora(): array
{
    $sucursal = Sucursal::create(['nombre' => 'Sucursal', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $roleDistribuidora = Role::firstOrCreate(['name' => 'Distribuidora']);
    $usuarioDistribuidora = User::factory()->create(['role_id' => $roleDistribuidora->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $distribuidora = Distribuidora::create([
        'usuario_id' => $usuarioDistribuidora->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    return [$sucursal, $distribuidora];
}

it('la cajera lista por HTTP solo las distribuidoras de su propia sucursal, para el canje de puntos', function (): void {
    [$sucursalA, $distribuidoraA] = crearSucursalConDistribuidora();
    [$sucursalB] = crearSucursalConDistribuidora();

    $roleCajera = Role::firstOrCreate(['name' => 'Cajera']);
    $cajera = User::factory()->create(['role_id' => $roleCajera->id, 'sucursal_id' => $sucursalA->id, 'is_active' => true]);

    Sanctum::actingAs($cajera);

    $response = $this->getJson('/api/v1/distribuidoras');

    $response->assertStatus(200)
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $distribuidoraA->id);
});
