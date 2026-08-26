<?php

declare(strict_types=1);

use App\Models\AbonoConciliacion;
use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\PuntoMovimiento;
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

function crearExcelBanco(array $filas, ?array $encabezado = null): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray($encabezado ?? ['item', 'Concepto', 'Referencia', 'Pago', 'Folio de pago', 'Fecha de pago', 'Hora', 'tipo de pago'], null, 'A1');
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

it('si ya existe un corte en la fecha indicada, genera el siguiente con la fecha corrida un día', function (): void {
    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8);

    $service = app(RelacionCalculoService::class);
    $primeraRelacion = $service->generarParaDistribuidora($distribuidora, '2026-02-15');
    $segundaRelacion = $service->generarParaDistribuidora($distribuidora, '2026-02-15');

    expect($primeraRelacion->fecha_corte->toDateString())->toBe('2026-02-15')
        ->and($segundaRelacion->fecha_corte->toDateString())->toBe('2026-02-16')
        ->and($segundaRelacion->referencia_pago)->not->toBe($primeraRelacion->referencia_pago)
        ->and($segundaRelacion->detalles->first()->cuota_numero)->toBe(2);
});

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

it('NO liquida una relación con saldo pendiente real, aunque caiga dentro de un margen de tolerancia de conciliación grande', function (): void {
    // margen_tolerancia_conciliacion sigue existiendo para decidir si un EXCEDENTE amerita
    // avisar a alguien -- pero ya NO decide si algo cuenta como "pagado" (ver
    // ConciliacionBancariaService::EPSILON_LIQUIDACION). Antes de ese fix, un margen grande
    // como este ($300, el mismo default real) dejaba "Liquidada" una relación con un saldo
    // real pendiente por debajo de esa cantidad.
    Configuracion::create([
        'clave' => 'margen_tolerancia_conciliacion', 'valor' => '300', 'tipo_dato' => 'decimal',
        'vigente_desde' => '2025-01-01', 'modificado_por' => User::factory()->create()->id,
    ]);

    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8, '2026-02-01');

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    // Total a pagar real: 2712 (igual que el resto de tests de este archivo). Paga 2460,
    // dejando 252 pendientes -- por debajo del margen de $300, pero un saldo real de todos modos.
    $archivo = crearExcelBanco([
        [1, 'Pago de distribuidora', $relacion->referencia_pago, 2460, 'F900', '13/2/2026', '14:00', 'Transferencia'],
    ]);

    $resumen = app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect($resumen['conciliadas'])->toBe(1);

    $relacion->refresh();
    expect($relacion->estado)->toBe('parcial')
        ->and((float) $relacion->total_a_pagar - (float) $relacion->total_abonado)->toBe(252.0);
});

it('concilia aunque el banco exporte la referencia como número y pierda los ceros a la izquierda', function (): void {
    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8, '2026-02-01');

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    // (int) tira los ceros a la izquierda -- exactamente lo que hace Excel/el banco cuando
    // exporta la columna como número en vez de texto.
    $archivo = crearExcelBanco([
        [1, 'Pago de distribuidora', (int) $relacion->referencia_pago, 2712, 'F950', '13/2/2026', '14:00', 'Transferencia'],
    ]);

    $resumen = app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect($resumen['conciliadas'])->toBe(1)
        ->and($resumen['sin_coincidencia'])->toBe(0);

    expect($relacion->fresh()->estado)->toBe('liquidada');
});

it('reimportar el mismo excel del banco no duplica el abono (mismo folio de pago)', function (): void {
    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8, '2026-02-01');

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    $archivo = crearExcelBanco([
        [1, 'Pago de distribuidora', $relacion->referencia_pago, 2712, 'F001', '13/2/2026', '14:00', 'Transferencia'],
    ]);

    $primerResumen = app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);
    expect($primerResumen['conciliadas'])->toBe(1)
        ->and($primerResumen['duplicados'])->toBe(0);

    // El banco vuelve a exportar el mismo rango de fechas (o la cajera sube el mismo archivo
    // por error): mismo folio de pago "F001" -- no debe volver a aplicarse el abono.
    $archivoRepetido = crearExcelBanco([
        [1, 'Pago de distribuidora', $relacion->referencia_pago, 2712, 'F001', '13/2/2026', '14:00', 'Transferencia'],
    ]);
    $segundoResumen = app(ConciliacionBancariaService::class)->importarArchivo($archivoRepetido, null, $cajera);

    expect($segundoResumen['procesadas'])->toBe(0)
        ->and($segundoResumen['duplicados'])->toBe(1);

    expect((float) $relacion->fresh()->total_abonado)->toBe(2712.0);
    expect(AbonoConciliacion::query()->where('folio_pago', 'F001')->count())->toBe(1);
});

it('reimportar el mismo excel SIN folio de pago tampoco duplica el abono (respaldo por referencia+monto+fecha+hora)', function (): void {
    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8, '2026-02-01');

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    // Fila sin Folio de pago (celda vacía) -- pasa igual, ver mapearFila().
    $archivo = crearExcelBanco([
        [1, 'Pago de distribuidora', $relacion->referencia_pago, 2712, '', '13/2/2026', '14:00', 'Transferencia'],
    ]);

    $primerResumen = app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);
    expect($primerResumen['conciliadas'])->toBe(1)
        ->and($primerResumen['duplicados'])->toBe(0);

    $archivoRepetido = crearExcelBanco([
        [1, 'Pago de distribuidora', $relacion->referencia_pago, 2712, '', '13/2/2026', '14:00', 'Transferencia'],
    ]);
    $segundoResumen = app(ConciliacionBancariaService::class)->importarArchivo($archivoRepetido, null, $cajera);

    expect($segundoResumen['procesadas'])->toBe(0)
        ->and($segundoResumen['duplicados'])->toBe(1);

    expect((float) $relacion->fresh()->total_abonado)->toBe(2712.0)
        ->and(AbonoConciliacion::query()->whereNull('folio_pago')->count())->toBe(1);
});

it('dos pagos reales distintos sin folio, con referencia/monto/fecha/hora distintos, NO se tratan como duplicados', function (): void {
    $distribuidoraA = crearDistribuidora();
    crearVale($distribuidoraA, 15000, 8, '2026-02-01');
    $relacionA = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidoraA, '2026-02-15');

    $sucursalB = Sucursal::create(['nombre' => 'Sucursal B', 'codigo' => 'SUC-B-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $categoriaB = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $roleDistribuidora = Role::query()->where('name', 'Distribuidora')->firstOrFail();
    $userB = User::factory()->create(['role_id' => $roleDistribuidora->id, 'sucursal_id' => $sucursalB->id]);
    $distribuidoraB = Distribuidora::create([
        'usuario_id' => $userB->id, 'numero_distribuidora' => 'DIST-B-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoriaB->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursalB->id,
    ]);
    crearVale($distribuidoraB, 15000, 8, '2026-02-01');
    $relacionB = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidoraB, '2026-02-15');

    $cajera = User::factory()->create();

    $archivo = crearExcelBanco([
        [1, 'Pago A', $relacionA->referencia_pago, 2712, '', '13/2/2026', '14:00', 'Transferencia'],
        [2, 'Pago B', $relacionB->referencia_pago, 2712, '', '13/2/2026', '15:00', 'Transferencia'],
    ]);

    $resumen = app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect($resumen['conciliadas'])->toBe(2)
        ->and($resumen['duplicados'])->toBe(0);
    expect((float) $relacionA->fresh()->total_abonado)->toBe(2712.0)
        ->and((float) $relacionB->fresh()->total_abonado)->toBe(2712.0);
});

it('rechaza el archivo si le falta alguna de las columnas esperadas', function (): void {
    $cajera = User::factory()->create();

    // Sin la columna "Folio de pago".
    $archivo = crearExcelBanco(
        [[1, 'Pago', '000000005020260215', 2712, '13/2/2026', '14:00', 'Transferencia']],
        ['item', 'Concepto', 'Referencia', 'Pago', 'Fecha de pago', 'Hora', 'tipo de pago']
    );

    expect(fn () => app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera))
        ->toThrow(DomainException::class, 'El archivo no tiene las columnas esperadas: folio de pago.');
});

it('no evalúa como fórmula una referencia del banco que por casualidad empiece con "="', function (): void {
    $cajera = User::factory()->create();

    $archivo = crearExcelBanco([
        [1, 'Abono raro', '=1+1', 500, 'F999', '13/2/2026', '10:00', 'Transferencia'],
    ]);

    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect(AbonoConciliacion::first()->referencia_leida)->toBe('=1+1');
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

    expect(AbonoConciliacion::first()->tipo_pago)->toBe('transferencia');
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
        ->and(App\Models\RelacionPerdon::where('distribuidora_id', $distribuidora->id)->count())->toBe(2);
});

it('un corte con dos vales: cada uno se paga por separado usando su propio "Concepto"', function (): void {
    $distribuidora = crearDistribuidora();
    $valeA = crearVale($distribuidora, 15000, 8);
    $valeB = crearVale($distribuidora, 5000, 4);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $relacion->loadMissing('detalles');

    expect($relacion->detalles)->toHaveCount(2);

    $detalleA = $relacion->detalles->firstWhere('vale_id', $valeA->id);
    $detalleB = $relacion->detalles->firstWhere('vale_id', $valeB->id);

    // Los dos comparten la MISMA referencia (es el mismo corte), pero cada uno tiene su
    // propio concepto único.
    expect($detalleA->concepto)->not->toBe($detalleB->concepto);

    $cajera = User::factory()->create();

    // Primero paga solo el vale A, usando su concepto -- el corte NO debe liquidarse todavía,
    // solo el detalle de A.
    $archivo = crearExcelBanco([
        [1, $detalleA->concepto, $relacion->referencia_pago, (float) $detalleA->total, 'F-A', '13/2/2026', '10:00', 'Transferencia'],
    ]);
    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    $relacion->refresh();
    $detalleA->refresh();
    $detalleB->refresh();

    expect($detalleA->estado)->toBe('pagado')
        ->and($detalleB->estado)->toBe('pendiente')
        ->and($relacion->estado)->toBe('parcial');

    // Ahora paga el vale B, con su propio concepto -- ahí sí se liquida todo el corte.
    $archivo2 = crearExcelBanco([
        [1, $detalleB->concepto, $relacion->referencia_pago, (float) $detalleB->total, 'F-B', '13/2/2026', '11:00', 'Transferencia'],
    ]);
    app(ConciliacionBancariaService::class)->importarArchivo($archivo2, null, $cajera);

    $relacion->refresh();
    $detalleB->refresh();

    expect($detalleB->estado)->toBe('pagado')
        ->and($relacion->estado)->toBe('liquidada');
});

it('dos vales del mismo corte con igual monto, pagados sin folio a la misma fecha/hora, NO se confunden entre sí', function (): void {
    // Caso borde del respaldo de deduplicación sin folio (ver procesarFila()): si no
    // distinguiera por relacion_detalle_id, este segundo pago se leería como "duplicado" del
    // primero (misma referencia+monto+fecha+hora) y se perdería en silencio.
    $distribuidora = crearDistribuidora();
    $valeA = crearVale($distribuidora, 15000, 8);
    $valeB = crearVale($distribuidora, 15000, 8);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $relacion->loadMissing('detalles');

    $detalleA = $relacion->detalles->firstWhere('vale_id', $valeA->id);
    $detalleB = $relacion->detalles->firstWhere('vale_id', $valeB->id);

    expect((float) $detalleA->total)->toBe((float) $detalleB->total);

    $cajera = User::factory()->create();

    $archivo = crearExcelBanco([
        [1, $detalleA->concepto, $relacion->referencia_pago, (float) $detalleA->total, '', '13/2/2026', '10:00', 'Transferencia'],
        [2, $detalleB->concepto, $relacion->referencia_pago, (float) $detalleB->total, '', '13/2/2026', '10:00', 'Transferencia'],
    ]);

    $resumen = app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect($resumen['conciliadas'])->toBe(2)
        ->and($resumen['duplicados'])->toBe(0);

    expect($detalleA->fresh()->estado)->toBe('pagado')
        ->and($detalleB->fresh()->estado)->toBe('pagado')
        ->and($relacion->fresh()->estado)->toBe('liquidada');
});

it('sin concepto (o corte de un solo vale) el abono se sigue aplicando al corte completo, como antes', function (): void {
    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    $archivo = crearExcelBanco([
        [1, 'Pago sin concepto', $relacion->referencia_pago, (float) $relacion->total_a_pagar, 'F-SOLO', '13/2/2026', '10:00', 'Transferencia'],
    ]);
    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect($relacion->fresh()->estado)->toBe('liquidada');
});

it('si el banco reporta más de lo que se debía, avisa al Gerente de Sucursal y al Coordinador del excedente', function (): void {
    $distribuidora = crearDistribuidora();
    crearVale($distribuidora, 15000, 8);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    $rolGS = Role::firstOrCreate(['name' => 'Gerente de Sucursal'], ['factor_count' => 3]);
    $rolCoordinador = Role::firstOrCreate(['name' => 'Coordinador'], ['factor_count' => 2]);
    $gerente = User::factory()->create(['role_id' => $rolGS->id, 'sucursal_id' => $distribuidora->sucursal_id]);
    $coordinador = User::factory()->create(['role_id' => $rolCoordinador->id, 'sucursal_id' => $distribuidora->sucursal_id]);

    $montoExcedente = (float) $relacion->total_a_pagar + 500;
    $archivo = crearExcelBanco([
        [1, 'Pago de más', $relacion->referencia_pago, $montoExcedente, 'F-EXTRA', '13/2/2026', '10:00', 'Transferencia'],
    ]);
    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect($relacion->fresh()->estado)->toBe('liquidada');

    $notificacionGerente = \App\Models\Notificacion::where('destinatario_id', $gerente->id)->where('accion', 'abono_excedente')->first();
    $notificacionCoordinador = \App\Models\Notificacion::where('destinatario_id', $coordinador->id)->where('accion', 'abono_excedente')->first();

    expect($notificacionGerente)->not->toBeNull()
        ->and($notificacionGerente->recurso)->toContain('500.00')
        ->and($notificacionCoordinador)->not->toBeNull();
});
