<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Configuracion;
use App\Models\Distribuidora;
use App\Models\Producto;
use App\Models\Role;
use App\Models\SeguroTabla;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Relacion\RelacionCalculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedConfigSimulacion(): void
{
    $admin = User::factory()->create();
    foreach (['comision_base_pct' => '10', 'interes_pct_quincena' => '5'] as $clave => $valor) {
        Configuracion::create([
            'clave' => $clave, 'valor' => $valor, 'tipo_dato' => 'decimal',
            'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id,
        ]);
    }
}

function crearDistribuidoraSimulacion(string $categoriaNombre, float $porcentajeCategoria): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => $categoriaNombre.'-'.uniqid(), 'porcentaje_comision' => $porcentajeCategoria, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 50000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

beforeEach(function (): void {
    seedConfigSimulacion();
    SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);
});

it('calcula el pago quincenal estimado con la categoría de la distribuidora, igual que un corte real', function (): void {
    $distribuidora = crearDistribuidoraSimulacion('PLATA', 6);

    $resultado = app(RelacionCalculoService::class)->simularPagoQuincenal(15000, 8, $distribuidora);

    expect($resultado['capital'])->toBe(1875.0)
        ->and($resultado['comision'])->toBe(187.5)
        ->and($resultado['interes'])->toBe(750.0)
        ->and($resultado['seguro'])->toBe(12.5)
        ->and($resultado['categoria'])->toBe(112.5)
        ->and($resultado['pago_quincenal'])->toBe(2712.0)
        ->and($resultado['total_estimado_plazo'])->toBe(21696.0);
});

it('sin distribuidora (sin descuento de categoría) coincide exacto con el ejemplo del documento fuente: $22,600 entre 8', function (): void {
    $resultado = app(RelacionCalculoService::class)->simularPagoQuincenal(15000, 8, null);

    expect($resultado['categoria'])->toBe(0.0)
        ->and($resultado['pago_quincenal'])->toBe(2825.0)
        ->and($resultado['total_estimado_plazo'])->toBe(22600.0);
});

it('la Distribuidora puede previsualizar un producto usando su propia categoría, vía HTTP', function (): void {
    $distribuidora = crearDistribuidoraSimulacion('ORO', 10);
    $producto = Producto::create(['monto' => 8000, 'quincenas' => 4, 'activo' => true, 'created_by' => User::factory()->create()->id]);
    Sanctum::actingAs($distribuidora->usuario);

    $response = $this->getJson('/api/v1/productos/'.$producto->id.'/simulacion');

    $response->assertStatus(200)
        ->assertJsonPath('producto_id', $producto->id)
        ->assertJsonPath('monto', 8000)
        ->assertJsonPath('quincenas', 4);

    // capital=2000, comision=200, interes=400, seguro=25, categoria=(8000*.10)/4=200 -> piso(2425)=2425
    expect((float) $response->json('pago_quincenal'))->toBe(2425.0)
        ->and((float) $response->json('total_estimado_plazo'))->toBe(9700.0);
});

it('el staff puede previsualizar para una distribuidora específica con ?distribuidora_id=', function (): void {
    $distribuidora = crearDistribuidoraSimulacion('ORO', 10);
    $producto = Producto::create(['monto' => 8000, 'quincenas' => 4, 'activo' => true, 'created_by' => User::factory()->create()->id]);

    $role = Role::firstOrCreate(['name' => 'Cajera']);
    $cajera = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    Sanctum::actingAs($cajera);

    $response = $this->getJson('/api/v1/productos/'.$producto->id.'/simulacion?distribuidora_id='.$distribuidora->id);

    $response->assertStatus(200);
    expect((float) $response->json('pago_quincenal'))->toBe(2425.0);
});

it('sin distribuidora asociada ni distribuidora_id, la simulación no aplica descuento de categoría', function (): void {
    $producto = Producto::create(['monto' => 8000, 'quincenas' => 4, 'activo' => true, 'created_by' => User::factory()->create()->id]);

    $role = Role::firstOrCreate(['name' => 'Cajera']);
    $cajera = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    Sanctum::actingAs($cajera);

    $response = $this->getJson('/api/v1/productos/'.$producto->id.'/simulacion');

    $response->assertStatus(200);
    expect((float) $response->json('categoria'))->toBe(0.0);
});
