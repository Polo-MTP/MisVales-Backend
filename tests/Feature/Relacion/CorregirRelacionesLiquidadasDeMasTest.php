<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\PuntoMovimiento;
use App\Models\Relacion;
use App\Models\RelacionDetalle;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Simula el estado que dejó el bug de ConciliacionBancariaService::aplicarAbono() antes del
 * fix: una Relacion marcada 'liquidada' con total_abonado por debajo de total_a_pagar (el
 * saldo real que el margen de tolerancia dejaba pasar como "ya pagado").
 */
function crearRelacionLiquidadaDeMas(float $totalAPagar, float $totalAbonado, string $fechaLimitePago): Relacion
{
    $sucursal = Sucursal::create(['nombre' => 'Suc-'.uniqid(), 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $usuario = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $distribuidora = Distribuidora::create([
        'usuario_id' => $usuario->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    return Relacion::create([
        'distribuidora_id' => $distribuidora->id, 'sucursal_id' => $sucursal->id,
        'referencia_pago' => 'REF-'.uniqid(), 'fecha_corte' => '2026-02-15', 'fecha_limite_pago' => $fechaLimitePago,
        'limite_credito_snapshot' => 20000, 'total_a_pagar' => $totalAPagar, 'total_abonado' => $totalAbonado,
        'estado' => 'liquidada',
    ]);
}

it('el preview reporta relaciones liquidadas con saldo real pendiente, sin tocar la base de datos', function (): void {
    $relacion = crearRelacionLiquidadaDeMas(totalAPagar: 2912, totalAbonado: 2660, fechaLimitePago: '2026-09-10');

    $this->artisan('relaciones:corregir-liquidadas-de-mas')
        ->expectsOutputToContain('252.00')
        ->expectsOutputToContain('Este fue solo un preview')
        ->assertExitCode(0);

    expect($relacion->fresh()->estado)->toBe('liquidada');
});

it('--apply corrige a "parcial" cuando ya tiene algo abonado y la fecha límite no ha pasado', function (): void {
    $relacion = crearRelacionLiquidadaDeMas(totalAPagar: 2912, totalAbonado: 2660, fechaLimitePago: now()->addDays(10)->toDateString());

    $this->artisan('relaciones:corregir-liquidadas-de-mas', ['--apply' => true])->assertExitCode(0);

    expect($relacion->fresh()->estado)->toBe('parcial');
});

it('--apply corrige a "vencida" cuando la fecha límite de pago ya pasó', function (): void {
    $relacion = crearRelacionLiquidadaDeMas(totalAPagar: 2912, totalAbonado: 2660, fechaLimitePago: now()->subDays(5)->toDateString());

    $this->artisan('relaciones:corregir-liquidadas-de-mas', ['--apply' => true])->assertExitCode(0);

    expect($relacion->fresh()->estado)->toBe('vencida');
});

it('no toca una relación liquidada cuyo saldo ya está genuinamente en cero', function (): void {
    $relacion = crearRelacionLiquidadaDeMas(totalAPagar: 2912, totalAbonado: 2912, fechaLimitePago: '2026-09-10');

    $this->artisan('relaciones:corregir-liquidadas-de-mas', ['--apply' => true])
        ->expectsOutputToContain('No se encontró ninguna relación');

    expect($relacion->fresh()->estado)->toBe('liquidada');
});

it('avisa (sin tocarlos) cuando hay cuotas marcadas pagado o puntos ya otorgados en una relación corregida', function (): void {
    $relacion = crearRelacionLiquidadaDeMas(totalAPagar: 2912, totalAbonado: 2660, fechaLimitePago: '2026-09-10');

    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'De Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);
    $vale = Vale::create([
        'distribuidora_id' => $relacion->distribuidora_id, 'cliente_id' => $cliente->id, 'monto' => 15000,
        'quincenas' => 8, 'tipo' => 'vale-digital', 'estado' => 'autorizado', 'fecha_autorizacion' => now(),
    ]);

    RelacionDetalle::create([
        'relacion_id' => $relacion->id, 'vale_id' => $vale->id, 'cliente_id' => $cliente->id, 'concepto' => 'TEST-CONCEPTO-1',
        'cuota_numero' => 1, 'cuotas_totales' => 8,
        'capital' => 1850, 'comision' => 185, 'interes' => 925, 'seguro' => 0, 'categoria' => 0,
        'recargo' => 0, 'pago' => 2660, 'total' => 2912, 'estado' => 'pagado',
    ]);

    PuntoMovimiento::create([
        'distribuidora_id' => $relacion->distribuidora_id, 'relacion_id' => $relacion->id,
        'tipo' => 'generado', 'cantidad' => 50, 'motivo' => 'Prueba',
    ]);

    $this->artisan('relaciones:corregir-liquidadas-de-mas', ['--apply' => true])
        ->expectsOutputToContain('NO revierte automáticamente')
        ->expectsOutputToContain('1 cuota(s)')
        ->assertExitCode(0);

    expect($relacion->fresh()->estado)->toBe('parcial')
        ->and(RelacionDetalle::first()->estado)->toBe('pagado')
        ->and(PuntoMovimiento::count())->toBe(1);
});
