<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\ExcedenteMovimiento;
use App\Models\Notificacion;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use App\Services\Relacion\ConciliacionBancariaService;
use App\Services\Relacion\RelacionCalculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/**
 * Antes, si el banco reportaba un pago de más en un corte, el excedente no se guardaba en
 * ningún lado más que una notificación opcional (y solo si superaba margen_tolerancia_
 * conciliacion). Ahora se registra siempre, POR VALE (no por distribuidora): el excedente de
 * un cliente nunca debe terminar pagando la deuda de otro cliente de la misma distribuidora.
 * Se descuenta solo de las cuotas futuras de ese mismo vale.
 */
function crearDistribuidoraParaExcedente(): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Suc-'.uniqid(), 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora'], ['factor_count' => 2]);
    $usuario = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $usuario->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearValeParaExcedente(Distribuidora $distribuidora, float $monto, int $quincenas, ?string $fechaAutorizacion = null): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'De Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => $monto,
        'quincenas' => $quincenas, 'tipo' => 'vale-digital', 'estado' => 'autorizado',
        'fecha_autorizacion' => $fechaAutorizacion ?? now(),
    ]);
}

function crearExcelBancoExcedente(array $filas): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['item', 'Concepto', 'Referencia', 'Pago', 'Folio de pago', 'Fecha de pago', 'Hora', 'tipo de pago'], null, 'A1');
    $sheet->fromArray($filas, null, 'A2');

    $ruta = sys_get_temp_dir().'/test_banco_excedente_'.uniqid().'.xlsx';
    (new Xlsx($spreadsheet))->save($ruta);

    return new UploadedFile($ruta, 'banco.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

it('un pago de más se registra en el saldo a favor del VALE (no de la distribuidora) aunque esté por debajo del margen de tolerancia', function (): void {
    $distribuidora = crearDistribuidoraParaExcedente();
    $vale = crearValeParaExcedente($distribuidora, 15000, 8);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    // margen_tolerancia_conciliacion sin configurar en este test -- default 0, así que
    // CUALQUIER excedente > 0 debe registrarse (aunque sea de $5).
    $montoExcedente = (float) $relacion->total_a_pagar + 5;
    $archivo = crearExcelBancoExcedente([
        [1, 'Pago de más', $relacion->referencia_pago, $montoExcedente, 'F-CHICO', '13/2/2026', '10:00', 'Transferencia'],
    ]);
    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect((float) $vale->fresh()->saldo_excedente)->toBe(5.0)
        ->and((float) $distribuidora->fresh()->saldo_excedente)->toBe(5.0);

    $movimiento = ExcedenteMovimiento::where('vale_id', $vale->id)->where('tipo', 'generado')->first();
    expect($movimiento)->not->toBeNull()
        ->and((float) $movimiento->monto)->toBe(5.0)
        ->and($movimiento->relacion_id)->toBe($relacion->id);

    expect(Notificacion::where('destinatario_id', $distribuidora->usuario_id)->where('accion', 'excedente_generado')->exists())->toBeTrue();
});

it('un pago de más con concepto identificado se atribuye al vale de ESA cuota, no a otro vale del mismo corte', function (): void {
    $distribuidora = crearDistribuidoraParaExcedente();
    $valeA = crearValeParaExcedente($distribuidora, 15000, 8);
    $valeB = crearValeParaExcedente($distribuidora, 10000, 8);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    $detalleA = $relacion->detalles->firstWhere('vale_id', $valeA->id);
    $montoConExcedente = (float) $detalleA->total + 20;

    $archivo = crearExcelBancoExcedente([
        [1, $detalleA->concepto, $relacion->referencia_pago, $montoConExcedente, 'F-A', '13/2/2026', '10:00', 'Transferencia'],
    ]);
    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect((float) $valeA->fresh()->saldo_excedente)->toBe(20.0)
        ->and((float) $valeB->fresh()->saldo_excedente)->toBe(0.0);
});

it('sin concepto y con varios vales en el corte, reparte el excedente proporcional al peso de cada vale', function (): void {
    $distribuidora = crearDistribuidoraParaExcedente();
    $valeA = crearValeParaExcedente($distribuidora, 15000, 8); // pesa más
    $valeB = crearValeParaExcedente($distribuidora, 5000, 8);  // pesa menos

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    $detalleA = $relacion->detalles->firstWhere('vale_id', $valeA->id);
    $detalleB = $relacion->detalles->firstWhere('vale_id', $valeB->id);
    $totalRelacion = (float) $relacion->total_a_pagar;

    $montoExcedente = 100.0;
    $archivo = crearExcelBancoExcedente([
        [1, 'Pago sin concepto', $relacion->referencia_pago, $totalRelacion + $montoExcedente, 'F-JUNTO', '13/2/2026', '10:00', 'Transferencia'],
    ]);
    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    $esperadoA = round($montoExcedente * ((float) $detalleA->total / $totalRelacion), 2);
    $esperadoB = round($montoExcedente - $esperadoA, 2);

    expect((float) $valeA->fresh()->saldo_excedente)->toBe($esperadoA)
        ->and((float) $valeB->fresh()->saldo_excedente)->toBe($esperadoB)
        ->and(round((float) $valeA->fresh()->saldo_excedente + (float) $valeB->fresh()->saldo_excedente, 2))->toBe($montoExcedente);
});

it('el excedente de un vale se descuenta automáticamente de las cuotas futuras de ESE MISMO vale', function (): void {
    $distribuidora = crearDistribuidoraParaExcedente();
    $vale = crearValeParaExcedente($distribuidora, 15000, 8);
    $vale->update(['saldo_excedente' => 200]);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    expect((float) $relacion->total_abonado)->toBe(200.0)
        ->and($relacion->estado)->toBe('parcial')
        ->and((float) $vale->fresh()->saldo_excedente)->toBe(0.0);

    $movimiento = ExcedenteMovimiento::where('vale_id', $vale->id)->where('tipo', 'aplicado')->first();
    expect($movimiento)->not->toBeNull()
        ->and((float) $movimiento->monto)->toBe(-200.0)
        ->and($movimiento->relacion_id)->toBe($relacion->id);
});

it('si el saldo a favor del vale alcanza para cubrir su propia cuota completa, la liquida de inmediato', function (): void {
    $distribuidora = crearDistribuidoraParaExcedente();
    $vale = crearValeParaExcedente($distribuidora, 15000, 8);
    $vale->update(['saldo_excedente' => 999999]);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    expect($relacion->estado)->toBe('liquidada');

    $relacion->loadMissing('detalles.vale');
    expect($relacion->detalles->every(fn ($d) => $d->estado === 'pagado'))->toBeTrue();

    // El saldo restante (lo que sobró después de cubrir la cuota) se queda en el vale, no se pierde.
    expect((float) $vale->fresh()->saldo_excedente)->toBeGreaterThan(0);
});

it('el saldo a favor de un vale NUNCA se aplica a la cuota de otro vale de la misma distribuidora', function (): void {
    $distribuidora = crearDistribuidoraParaExcedente();
    $valeConSaldo = crearValeParaExcedente($distribuidora, 15000, 8);
    $valeConSaldo->update(['saldo_excedente' => 500]);
    $otroVale = crearValeParaExcedente($distribuidora, 8000, 8);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $relacion->loadMissing('detalles');

    $detalleConSaldo = $relacion->detalles->firstWhere('vale_id', $valeConSaldo->id);
    $detalleOtro = $relacion->detalles->firstWhere('vale_id', $otroVale->id);

    expect((float) $detalleConSaldo->pago)->toBe(500.0)
        ->and((float) $detalleOtro->pago)->toBe(0.0);
});

it('no toca el saldo de un vale de otra distribuidora que no tiene nada que ver', function (): void {
    $distribuidora = crearDistribuidoraParaExcedente();
    $otra = crearDistribuidoraParaExcedente();
    $valeOtra = crearValeParaExcedente($otra, 15000, 8);
    $valeOtra->update(['saldo_excedente' => 999]);

    crearValeParaExcedente($distribuidora, 15000, 8);
    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    expect((float) $relacion->total_abonado)->toBe(0.0)
        ->and((float) $valeOtra->fresh()->saldo_excedente)->toBe(999.0);
});

it('GET /distribuidoras/{id}/saldo-disponible suma el saldo a favor de todos sus vales', function (): void {
    $distribuidora = crearDistribuidoraParaExcedente();
    $valeA = crearValeParaExcedente($distribuidora, 15000, 8);
    $valeA->update(['saldo_excedente' => 30]);
    $valeB = crearValeParaExcedente($distribuidora, 5000, 8);
    $valeB->update(['saldo_excedente' => 12.5]);

    $rolGG = Role::firstOrCreate(['name' => 'Gerente General'], ['factor_count' => 1]);
    Sanctum::actingAs(User::factory()->create(['role_id' => $rolGG->id, 'is_active' => true]));

    $this->getJson("/api/v1/distribuidoras/{$distribuidora->id}/saldo-disponible")
        ->assertStatus(200)
        ->assertJsonPath('saldo_excedente', 42.5);
});
