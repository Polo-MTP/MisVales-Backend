<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Distribuidora\DistribuidoraService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regresión: DistribuidoraService::listarPorRol() comparaba nombres de rol en minúsculas
 * ('coordinador', 'gerente-sucursal') contra los reales del seeder ('Coordinador',
 * 'Gerente de Sucursal'), por lo que el filtro nunca aplicaba y cualquier Coordinador o
 * Gerente de Sucursal veía TODAS las distribuidoras en vez de solo las suyas.
 */
it('un coordinador solo ve las distribuidoras que tiene asignadas', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA', 'porcentaje_comision' => 6, 'activo' => true]);
    $roleCoordinador = Role::create(['name' => 'Coordinador', 'factor_count' => 2]);
    $roleDistribuidora = Role::create(['name' => 'Distribuidora', 'factor_count' => 2]);

    $coordinadorA = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id]);
    $coordinadorB = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id]);

    $usuarioDistA = User::factory()->create(['role_id' => $roleDistribuidora->id, 'sucursal_id' => $sucursal->id]);
    $usuarioDistB = User::factory()->create(['role_id' => $roleDistribuidora->id, 'sucursal_id' => $sucursal->id]);

    Distribuidora::create([
        'usuario_id' => $usuarioDistA->id, 'numero_distribuidora' => 'DIST-A', 'limite_credito' => 1000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO',
        'sucursal_id' => $sucursal->id, 'coordinador_id' => $coordinadorA->id,
    ]);
    Distribuidora::create([
        'usuario_id' => $usuarioDistB->id, 'numero_distribuidora' => 'DIST-B', 'limite_credito' => 1000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO',
        'sucursal_id' => $sucursal->id, 'coordinador_id' => $coordinadorB->id,
    ]);

    auth()->login($coordinadorA);

    $visibles = app(DistribuidoraService::class)->listarPorRol();

    expect($visibles)->toHaveCount(1)
        ->and($visibles->first()->numero_distribuidora)->toBe('DIST-A');
});
