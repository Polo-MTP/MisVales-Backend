<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\RelacionDetalle;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use App\Services\Vale\ValeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearSucursalListado(string $codigo): Sucursal
{
    return Sucursal::create(['nombre' => 'Sucursal '.$codigo, 'codigo' => $codigo, 'es_matriz' => false, 'is_active' => true]);
}

function crearDistribuidoraValeListado(Sucursal $sucursal, string $numero): Distribuidora
{
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => $numero, 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearValeListado(Distribuidora $distribuidora, float $monto = 5000): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => $monto, 'quincenas' => 4,
        'tipo' => 'vale-digital', 'estado' => 'solicitado', 'fecha_solicitud' => now(),
    ]);
}

function crearUsuarioDeSucursal(string $rol, Sucursal $sucursal): User
{
    $role = Role::firstOrCreate(['name' => $rol]);

    return User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
}

it('una cajera solo ve vales de distribuidoras de su propia sucursal', function (): void {
    $sucursalA = crearSucursalListado('SUC-A');
    $sucursalB = crearSucursalListado('SUC-B');
    $distA = crearDistribuidoraValeListado($sucursalA, 'DIST-A');
    $distB = crearDistribuidoraValeListado($sucursalB, 'DIST-B');
    crearValeListado($distA);
    crearValeListado($distB);

    $cajera = crearUsuarioDeSucursal('Cajera', $sucursalA);

    $vales = app(ValeService::class)->listar($cajera);

    expect($vales->total())->toBe(1)
        ->and($vales->first()->distribuidora_id)->toBe($distA->id);
});

it('un gerente de sucursal solo ve vales de distribuidoras de su propia sucursal', function (): void {
    $sucursalA = crearSucursalListado('SUC-A');
    $sucursalB = crearSucursalListado('SUC-B');
    $distA = crearDistribuidoraValeListado($sucursalA, 'DIST-A');
    $distB = crearDistribuidoraValeListado($sucursalB, 'DIST-B');
    crearValeListado($distA);
    crearValeListado($distB);

    $gerente = crearUsuarioDeSucursal('Gerente de Sucursal', $sucursalA);

    $vales = app(ValeService::class)->listar($gerente);

    expect($vales->total())->toBe(1)
        ->and($vales->first()->distribuidora_id)->toBe($distA->id);
});

it('un coordinador sigue viendo vales de todas las sucursales', function (): void {
    $sucursalA = crearSucursalListado('SUC-A');
    $sucursalB = crearSucursalListado('SUC-B');
    $distA = crearDistribuidoraValeListado($sucursalA, 'DIST-A');
    $distB = crearDistribuidoraValeListado($sucursalB, 'DIST-B');
    crearValeListado($distA);
    crearValeListado($distB);

    $coordinador = crearUsuarioDeSucursal('Coordinador', $sucursalA);

    $vales = app(ValeService::class)->listar($coordinador);

    expect($vales->total())->toBe(2);
});

it('la cajera puede llegar por HTTP a GET /vales (antes solo Distribuidora/Coordinador/Gerentes)', function (): void {
    $sucursal = crearSucursalListado('SUC-A');
    $distribuidora = crearDistribuidoraValeListado($sucursal, 'DIST-A');
    crearValeListado($distribuidora);

    $cajera = crearUsuarioDeSucursal('Cajera', $sucursal);
    Sanctum::actingAs($cajera);

    $this->getJson('/api/v1/vales')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.data');
});

it('un vale sin corte todavía trae el pago quincenal estimado', function (): void {
    $sucursal = crearSucursalListado('SUC-A');
    $distribuidora = crearDistribuidoraValeListado($sucursal, 'DIST-A');
    crearValeListado($distribuidora, 5000);

    $cajera = crearUsuarioDeSucursal('Cajera', $sucursal);
    Sanctum::actingAs($cajera);

    $response = $this->getJson('/api/v1/vales');

    $response->assertStatus(200);
    $vale = $response->json('data.data.0');

    expect($vale['cortes'])->toBe([])
        ->and($vale['estimacion'])->not->toBeNull();

    // categoria PLATA 6%, monto 5000, 4 quincenas, comisión/interés por defecto (10%/5%,
    // sin config sembrada), sin seguro (sin SeguroTabla sembrada):
    // capital=1250, comisión=125, interés=250, seguro=0, categoría=75 -> piso(1550)=1550
    expect((float) $vale['estimacion']['pago_quincenal'])->toBe(1550.0)
        ->and((float) $vale['estimacion']['total_estimado_plazo'])->toBe(6200.0);
});

it('un vale que ya entró a un corte NO trae estimación (ya tiene el desglose real en "cortes")', function (): void {
    $sucursal = crearSucursalListado('SUC-A');
    $distribuidora = crearDistribuidoraValeListado($sucursal, 'DIST-A');
    $vale = crearValeListado($distribuidora, 5000);

    $relacion = Relacion::create([
        'distribuidora_id' => $distribuidora->id, 'sucursal_id' => $sucursal->id,
        'referencia_pago' => 'REF-'.uniqid(), 'fecha_corte' => '2026-02-15', 'fecha_limite_pago' => '2026-02-16',
        'limite_credito_snapshot' => 20000, 'estado' => 'pendiente',
    ]);

    RelacionDetalle::create([
        'relacion_id' => $relacion->id, 'vale_id' => $vale->id, 'concepto' => sprintf('%05d%04d', $vale->id, 1),
        'cliente_id' => $vale->cliente_id,
        'cuota_numero' => 1, 'cuotas_totales' => 4, 'capital' => 1250, 'comision' => 125,
        'interes' => 250, 'seguro' => 0, 'categoria' => 75, 'recargo' => 0, 'pago' => 0,
        'total' => 1550, 'estado' => 'pendiente',
    ]);

    $cajera = crearUsuarioDeSucursal('Cajera', $sucursal);
    Sanctum::actingAs($cajera);

    $response = $this->getJson('/api/v1/vales');

    $response->assertStatus(200);
    $valeJson = $response->json('data.data.0');

    expect($valeJson['cortes'])->toHaveCount(1)
        ->and($valeJson['estimacion'])->toBeNull();
});

it('trae el total acumulado a pagar y pagado sumando todas las cuotas del vale que ya entraron a un corte', function (): void {
    $sucursal = crearSucursalListado('SUC-A');
    $distribuidora = crearDistribuidoraValeListado($sucursal, 'DIST-A');
    $vale = crearValeListado($distribuidora, 5000);

    $relacion1 = Relacion::create([
        'distribuidora_id' => $distribuidora->id, 'sucursal_id' => $sucursal->id,
        'referencia_pago' => 'REF-'.uniqid(), 'fecha_corte' => '2026-02-15', 'fecha_limite_pago' => '2026-02-16',
        'limite_credito_snapshot' => 20000, 'estado' => 'parcial',
    ]);
    RelacionDetalle::create([
        'relacion_id' => $relacion1->id, 'vale_id' => $vale->id, 'concepto' => sprintf('%05d%04d', $vale->id, 1),
        'cliente_id' => $vale->cliente_id,
        'cuota_numero' => 1, 'cuotas_totales' => 4, 'capital' => 1250, 'comision' => 125,
        'interes' => 250, 'seguro' => 0, 'categoria' => 75, 'recargo' => 0, 'pago' => 1000,
        'total' => 1550, 'estado' => 'parcial',
    ]);

    $relacion2 = Relacion::create([
        'distribuidora_id' => $distribuidora->id, 'sucursal_id' => $sucursal->id,
        'referencia_pago' => 'REF-'.uniqid(), 'fecha_corte' => '2026-03-15', 'fecha_limite_pago' => '2026-03-16',
        'limite_credito_snapshot' => 20000, 'estado' => 'pendiente',
    ]);
    RelacionDetalle::create([
        'relacion_id' => $relacion2->id, 'vale_id' => $vale->id, 'concepto' => sprintf('%05d%04d', $vale->id, 2),
        'cliente_id' => $vale->cliente_id,
        'cuota_numero' => 2, 'cuotas_totales' => 4, 'capital' => 1250, 'comision' => 125,
        'interes' => 250, 'seguro' => 0, 'categoria' => 75, 'recargo' => 0, 'pago' => 0,
        'total' => 1550, 'estado' => 'pendiente',
    ]);

    $cajera = crearUsuarioDeSucursal('Cajera', $sucursal);
    Sanctum::actingAs($cajera);

    $valeJson = $this->getJson('/api/v1/vales')->json('data.data.0');

    expect((float) $valeJson['total_acumulado_a_pagar'])->toBe(3100.0)
        ->and((float) $valeJson['total_acumulado_pagado'])->toBe(1000.0)
        ->and((float) $valeJson['cortes'][0]['pago'])->toBe(1000.0)
        ->and((float) $valeJson['cortes'][1]['pago'])->toBe(0.0);
});
