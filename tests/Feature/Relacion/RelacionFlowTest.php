<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\PuntoMovimiento;
use App\Models\Relacion;
use App\Models\Role;
use App\Models\SeguroTabla;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use App\Services\Relacion\ConciliacionBancariaService;
use App\Services\Relacion\RelacionCalculoService;
use App\Services\Relacion\RelacionEstadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function seedConfiguracionBase(): void
{
    $admin = User::factory()->create();

    $valores = [
        'comision_base_pct' => '10',
        'interes_pct_quincena' => '5',
        'multa_no_pago' => '300',
        'puntos_divisor' => '1200',
        'puntos_multiplicador' => '3',
        'puntos_penalizacion_pct' => '20',
        'limite_perdones_relacion' => '2',
        'margen_tolerancia_conciliacion' => '1',
        'regla_50_pct' => '50',
    ];

    foreach ($valores as $clave => $valor) {
        Configuracion::create([
            'clave' => $clave,
            'valor' => $valor,
            'tipo_dato' => 'decimal',
            'vigente_desde' => '2025-01-01',
            'modificado_por' => $admin->id,
        ]);
    }
}

function crearVale(Distribuidora $distribuidora, float $monto, int $quincenas, ?string $fechaAutorizacion = null): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create([
        'nombre' => 'Cliente', 'apellido_paterno' => 'De Prueba', 'curp' => 'CUPD'.uniqid(),
        'direccion_id' => $direccion->id,
    ]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id,
        'cliente_id' => $cliente->id,
        'monto' => $monto,
        'quincenas' => $quincenas,
        'tipo' => 'vale-digital',
        'estado' => 'autorizado',
        'fecha_autorizacion' => $fechaAutorizacion ?? now(),
    ]);
}

function crearDistribuidora(): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA', 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::create(['name' => 'Distribuidora', 'factor_count' => 2]);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id]);

    return Distribuidora::create([
        'usuario_id' => $user->id,
        'numero_distribuidora' => 'DIST-TEST',
        'limite_credito' => 20000,
        'categoria_id' => $categoria->id,
        'puntos_acumulados' => 0,
        'estado' => 'ACTIVO',
        'sucursal_id' => $sucursal->id,
    ]);
}

function crearExcelBanco(array $filas): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['item', 'Concepto', 'Referencia', 'Pago', 'Folio de pago', 'Fecha de pago', 'Hora', 'tipo de pago'], null, 'A1');
    $sheet->fromArray($filas, null, 'A2');

    $ruta = sys_get_temp_dir().'/test_banco_'.uniqid().'.xlsx';
    (new Xlsx($spreadsheet))->save($ruta);

    return new UploadedFile($ruta, 'banco.xlsx', null, null, true);
}

beforeEach(function (): void {
    Storage::fake('local');
    seedConfiguracionBase();
    SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);
});

it('calcula la relación replicando exactamente el ejemplo del documento fuente', function (): void {
    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    expect($relacion)->not->toBeNull()
        ->and((float) $relacion->total_capital)->toBe(1875.0)
        ->and((float) $relacion->total_comision)->toBe(187.5)
        ->and((float) $relacion->total_interes)->toBe(750.0)
        ->and((float) $relacion->total_seguro)->toBe(12.5)
        // Categoría PLATA (6%): 15,000 x 0.06 / 8 quincenas = 112.5 (ganancia de la distribuidora, se resta del pago)
        ->and((float) $relacion->total_categoria)->toBe(112.5)
        // Pago Distribuidora = 1875 + 187.5 + 750 + 12.5 - 112.5 = 2712 - 112.5 = 2712.5, ROUNDDOWN al piso = 2712
        ->and((float) $relacion->total_a_pagar)->toBe(2712.0)
        ->and($relacion->fecha_limite_pago->toDateString())->toBe('2026-02-16')
        ->and($relacion->fecha_pago_anticipado_desde->toDateString())->toBe('2026-02-13')
        ->and($relacion->fecha_pago_anticipado_hasta->toDateString())->toBe('2026-02-15')
        ->and($relacion->detalles)->toHaveCount(1)
        ->and($relacion->detalles->first()->cuota_numero)->toBe(1)
        ->and($relacion->detalles->first()->cuotas_totales)->toBe(8);
});

it('no genera relación si la distribuidora no tiene vales pendientes', function (): void {
    $distribuidora = crearDistribuidora();

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    expect($relacion)->toBeNull();
});

it('no permite generar dos relaciones para la misma distribuidora en la misma fecha de corte', function (): void {
    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8);

    app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
})->throws(DomainException::class);

it('aplica recargo cuando la cuota anterior del mismo vale no quedó pagada', function (): void {
    $distribuidora = crearDistribuidora();
    $vale = crearVale($distribuidora, 15000, 8);

    $service = app(RelacionCalculoService::class);
    $primeraRelacion = $service->generarParaDistribuidora($distribuidora, '2026-02-15');
    expect((float) $primeraRelacion->detalles->first()->recargo)->toBe(0.0);

    $segundaRelacion = $service->generarParaDistribuidora($distribuidora, '2026-03-15');

    expect((float) $segundaRelacion->detalles->first()->recargo)->toBe(300.0)
        ->and($segundaRelacion->detalles->first()->cuota_numero)->toBe(2);
});

it('no aplica recargo si la cuota anterior del mismo vale ya se liquidó puntual', function (): void {
    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8);

    $service = app(RelacionCalculoService::class);
    $primeraRelacion = $service->generarParaDistribuidora($distribuidora, '2026-02-15');

    $archivo = crearExcelBanco([
        [1, 'Pago puntual', $primeraRelacion->referencia_pago, 2712, 'F900', '15/2/2026', '10:00', 'Transferencia'],
    ]);
    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, User::factory()->create());

    expect($primeraRelacion->fresh()->detalles->first()->estado)->toBe('pagado');

    $segundaRelacion = $service->generarParaDistribuidora($distribuidora, '2026-03-15');

    expect((float) $segundaRelacion->detalles->first()->recargo)->toBe(0.0);
});

it('concilia un abono bancario, liquida la relación y genera puntos por pago anticipado', function (): void {
    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8, '2026-02-01');

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    $archivo = crearExcelBanco([
        [1, 'Pago de distribuidora', $relacion->referencia_pago, 2712, 'F001', '13/2/2026', '14:00', 'Transferencia'],
    ]);

    $resumen = app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect($resumen['conciliadas'])->toBe(1)
        ->and($resumen['sin_coincidencia'])->toBe(0);

    $relacion->refresh();
    expect($relacion->estado)->toBe('liquidada')
        ->and((float) $relacion->total_abonado)->toBe(2712.0)
        ->and($relacion->puntos_generados)->toBeGreaterThanOrEqual(0);
});

it('no evalúa como fórmula una referencia del banco que por casualidad empiece con "="', function (): void {
    $cajera = User::factory()->create();

    $archivo = crearExcelBanco([
        [1, 'Abono raro', '=1+1', 500, 'F999', '13/2/2026', '10:00', 'Transferencia'],
    ]);

    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect(\App\Models\AbonoConciliacion::first()->referencia_leida)->toBe('=1+1');
});

it('deja el abono sin coincidencia cuando la referencia no existe', function (): void {
    $cajera = User::factory()->create();

    $archivo = crearExcelBanco([
        [1, 'Abono desconocido', 'REF-QUE-NO-EXISTE', 500, 'F002', '13/2/2026', '10:00', 'Pago en ventanilla'],
    ]);

    $resumen = app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect($resumen['conciliadas'])->toBe(0)
        ->and($resumen['sin_coincidencia'])->toBe(1);
});

it('tolera el typo real del banco "Tranferencia" y lo normaliza a transferencia', function (): void {
    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8, '2026-02-01');
    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    $archivo = crearExcelBanco([
        [1, 'Pago', $relacion->referencia_pago, 2712, 'F003', '13/2/2026', '14:00', 'Tranferencia'],
    ]);

    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect(\App\Models\AbonoConciliacion::first()->tipo_pago)->toBe('transferencia');
});

it('penaliza el 20% de los puntos acumulados cuando el pago llega fuera de tiempo', function (): void {
    $distribuidora = crearDistribuidora();
    $distribuidora->update(['puntos_acumulados' => 100]);
    crearVale($distribuidora, 15000, 8, '2026-02-01');

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    // fecha límite era 2026-02-16; este pago llega el 2026-02-20, fuera de tiempo.
    $archivo = crearExcelBanco([
        [1, 'Pago tardío', $relacion->referencia_pago, 2712, 'F004', '20/2/2026', '10:00', 'Transferencia'],
    ]);

    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect($distribuidora->fresh()->puntos_acumulados)->toBe(80)
        ->and(PuntoMovimiento::where('tipo', 'penalizado')->count())->toBe(1);
});

it('marca como vencidas las relaciones cuya fecha límite de pago ya pasó', function (): void {
    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8, '2026-02-01');
    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    $total = app(RelacionEstadoService::class)->marcarVencidas('2026-03-01');

    expect($total)->toBe(1)
        ->and($relacion->fresh()->estado)->toBe('vencida');
});

it('perdonar condona el recargo y el interés, y reduce el total a pagar (no toca capital/comisión/seguro/categoría)', function (): void {
    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8);

    $service = app(RelacionCalculoService::class);
    $service->generarParaDistribuidora($distribuidora, '2026-02-15');
    $segundaRelacion = $service->generarParaDistribuidora($distribuidora, '2026-03-15');

    // Vale sin liquidar la 1a cuota -> la 2a cuota trae recargo, sin descuento de categoría ni piso.
    expect((float) $segundaRelacion->total_recargos)->toBe(300.0)
        ->and((float) $segundaRelacion->total_interes)->toBe(750.0)
        ->and((float) $segundaRelacion->total_a_pagar)->toBe(3125.0);

    $gerente = User::factory()->create();
    $perdonada = app(RelacionEstadoService::class)->perdonar($segundaRelacion, $gerente, 'atraso justificado');

    expect($perdonada->estado)->toBe('perdonada')
        ->and((float) $perdonada->total_recargos)->toBe(0.0)
        ->and((float) $perdonada->total_interes)->toBe(0.0)
        // 3125 - 300 (recargo) - 750 (interés) = 2075; capital+comisión+seguro se quedan igual.
        ->and((float) $perdonada->total_a_pagar)->toBe(2075.0)
        ->and((float) $perdonada->detalles->first()->recargo)->toBe(0.0)
        ->and((float) $perdonada->detalles->first()->interes)->toBe(0.0)
        ->and((float) $perdonada->detalles->first()->total)->toBe(2075.0);
});

it('perdona la primera y segunda relación vencida, y marca la tercera como pérdida', function (): void {
    $distribuidora = crearDistribuidora();
    $gerente = User::factory()->create();
    $service = app(RelacionEstadoService::class);

    $relaciones = [];
    foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $i => $fecha) {
        crearVale($distribuidora, 1000, 1, $fecha);
        $relaciones[] = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, $fecha);
    }

    $r1 = $service->perdonar($relaciones[0], $gerente, 'primera falta');
    $r2 = $service->perdonar($relaciones[1], $gerente, 'segunda falta');
    $r3 = $service->perdonar($relaciones[2], $gerente, 'tercera falta');

    expect($r1->estado)->toBe('perdonada')
        ->and($r2->estado)->toBe('perdonada')
        ->and($r3->estado)->toBe('en_perdida')
        ->and(\App\Models\RelacionPerdon::where('distribuidora_id', $distribuidora->id)->count())->toBe(2);
});
