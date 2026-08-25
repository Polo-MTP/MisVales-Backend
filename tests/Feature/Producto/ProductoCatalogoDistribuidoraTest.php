<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Configuracion;
use App\Models\Distribuidora;
use App\Models\Producto;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearDistribuidoraCatalogo(float $limiteCredito = 10000): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'BRONCE-'.uniqid(), 'porcentaje_comision' => 2, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => $limiteCredito,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearProductoCatalogo(float $monto): Producto
{
    return Producto::create(['monto' => $monto, 'quincenas' => 4, 'activo' => true, 'created_by' => User::factory()->create()->id]);
}

beforeEach(function (): void {
    $admin = User::factory()->create();
    Configuracion::create(['clave' => 'regla_50_pct', 'valor' => '50', 'tipo_dato' => 'decimal', 'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id]);
});

it('a una distribuidora nueva (primer vale) solo se le muestran productos dentro del 50% de su límite + el margen', function (): void {
    // Tope esperado: 10000 * 50% + margen (default $500) = 5500.
    crearProductoCatalogo(4000);
    crearProductoCatalogo(5000);
    crearProductoCatalogo(5500);
    crearProductoCatalogo(5501);
    crearProductoCatalogo(8000);

    $distribuidora = crearDistribuidoraCatalogo(10000);
    Sanctum::actingAs($distribuidora->usuario);

    $response = $this->getJson('/api/v1/productos');

    $response->assertStatus(200);
    $montos = collect($response->json())->pluck('monto')->map(fn ($m) => (float) $m)->sort()->values();
    expect($montos->all())->toBe([4000.0, 5000.0, 5500.0]);
});

it('el Gerente General sigue viendo el catálogo completo sin filtrar', function (): void {
    crearProductoCatalogo(4000);
    crearProductoCatalogo(9999);

    $role = Role::firstOrCreate(['name' => 'Gerente General']);
    $gerente = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    Sanctum::actingAs($gerente);

    $response = $this->getJson('/api/v1/productos');

    $response->assertStatus(200);
    expect($response->json())->toHaveCount(2);
});
