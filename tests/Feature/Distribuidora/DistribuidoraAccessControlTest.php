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

function crearDistribuidoraConDueños(Sucursal $sucursal, User $coordinador, string $estado = 'ACTIVO'): Distribuidora
{
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $roleDistribuidora = Role::firstOrCreate(['name' => 'Distribuidora']);
    $usuarioDistribuidora = User::factory()->create(['role_id' => $roleDistribuidora->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $usuarioDistribuidora->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => $estado, 'sucursal_id' => $sucursal->id,
        'coordinador_id' => $coordinador->id,
    ]);
}

it('un coordinador no puede ver el detalle de una distribuidora que no coordina', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Sucursal', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $roleCoordinador = Role::firstOrCreate(['name' => 'Coordinador']);
    $coordinadorDueño = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $otroCoordinador = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $distribuidora = crearDistribuidoraConDueños($sucursal, $coordinadorDueño);

    Sanctum::actingAs($otroCoordinador);

    $this->getJson("/api/v1/distribuidoras/{$distribuidora->id}")->assertStatus(403);
});

it('un coordinador no puede editar (ni reasignarse) una distribuidora que no coordina', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Sucursal', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $roleCoordinador = Role::firstOrCreate(['name' => 'Coordinador']);
    $coordinadorDueño = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $otroCoordinador = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $distribuidora = crearDistribuidoraConDueños($sucursal, $coordinadorDueño, 'EN_CAPTURA');

    Sanctum::actingAs($otroCoordinador);

    $this->putJson("/api/v1/distribuidoras/{$distribuidora->id}", ['coordinador_id' => $otroCoordinador->id])
        ->assertStatus(403);

    expect($distribuidora->fresh()->coordinador_id)->toBe($coordinadorDueño->id);
});

it('un coordinador dueño ya no puede editar su distribuidora una vez que está ACTIVO', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Sucursal', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $roleCoordinador = Role::firstOrCreate(['name' => 'Coordinador']);
    $coordinador = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $distribuidora = crearDistribuidoraConDueños($sucursal, $coordinador, 'ACTIVO');

    Sanctum::actingAs($coordinador);

    $this->putJson("/api/v1/distribuidoras/{$distribuidora->id}", ['nombre' => 'Nuevo Nombre SA'])
        ->assertStatus(403);
});

it('un gerente de sucursal no puede editar una distribuidora de otra sucursal', function (): void {
    $sucursalA = Sucursal::create(['nombre' => 'Sucursal A', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $sucursalB = Sucursal::create(['nombre' => 'Sucursal B', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $roleCoordinador = Role::firstOrCreate(['name' => 'Coordinador']);
    $coordinador = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursalA->id, 'is_active' => true]);
    $distribuidora = crearDistribuidoraConDueños($sucursalA, $coordinador);

    $roleGerente = Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    $gerenteB = User::factory()->create(['role_id' => $roleGerente->id, 'sucursal_id' => $sucursalB->id, 'is_active' => true]);

    Sanctum::actingAs($gerenteB);

    $this->putJson("/api/v1/distribuidoras/{$distribuidora->id}", ['nombre' => 'Nuevo Nombre SA'])
        ->assertStatus(403);
});

it('el gerente general si puede editar y eliminar (desactivar) cualquier distribuidora', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Sucursal', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $roleCoordinador = Role::firstOrCreate(['name' => 'Coordinador']);
    $coordinador = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $distribuidora = crearDistribuidoraConDueños($sucursal, $coordinador, 'ACTIVO');

    $roleGerenteGeneral = Role::firstOrCreate(['name' => 'Gerente General']);
    $gerenteGeneral = User::factory()->create(['role_id' => $roleGerenteGeneral->id, 'is_active' => true]);

    Sanctum::actingAs($gerenteGeneral);

    $this->putJson("/api/v1/distribuidoras/{$distribuidora->id}", ['nombre' => 'Nuevo Nombre SA'])
        ->assertStatus(200);

    $this->deleteJson("/api/v1/distribuidoras/{$distribuidora->id}")->assertStatus(200);

    expect($distribuidora->fresh()->estado)->toBe('INACTIVO');
});
