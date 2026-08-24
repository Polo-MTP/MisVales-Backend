<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

function crearDistribuidoraMorosaEnSucursal(Sucursal $sucursal): Distribuidora
{
    $categoria = CategoriaDistribuidora::firstOrCreate(
        ['nombre' => 'PLATA-'.uniqid()],
        ['porcentaje_comision' => 6, 'activo' => true]
    );
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $usuario = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id]);

    return Distribuidora::create([
        'usuario_id' => $usuario->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'MOROSO', 'sucursal_id' => $sucursal->id,
    ]);
}

it('una Cajera solo ve distribuidoras morosas de su propia sucursal, no las de otra', function (): void {
    $sucursalGomezPalacio = Sucursal::create(['nombre' => 'Gómez Palacio', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $sucursalDurango = Sucursal::create(['nombre' => 'Durango', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);

    $distribuidoraPropia = crearDistribuidoraMorosaEnSucursal($sucursalGomezPalacio);
    $distribuidoraAjena = crearDistribuidoraMorosaEnSucursal($sucursalDurango);

    $rolCajera = Role::firstOrCreate(['name' => 'Cajera']);
    $cajera = User::factory()->create(['role_id' => $rolCajera->id, 'sucursal_id' => $sucursalGomezPalacio->id, 'is_active' => true]);

    Sanctum::actingAs($cajera);

    $response = getJson('/api/v1/reportes/morosos');

    $response->assertStatus(200);

    $idsVistos = collect($response->json('data'))->pluck('distribuidora_id');

    expect($idsVistos)->toContain($distribuidoraPropia->id)
        ->and($idsVistos)->not->toContain($distribuidoraAjena->id);
});
