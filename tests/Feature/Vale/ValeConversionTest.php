<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\SeguroTabla;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use App\Services\Relacion\ConciliacionBancariaService;
use App\Services\Relacion\RelacionCalculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function seedConfiguracionBaseVale(): void
{
    $admin = User::factory()->create();

    foreach ([
        'comision_base_pct' => '10',
        'interes_pct_quincena' => '5',
        'multa_no_pago' => '300',
        'puntos_divisor' => '1200',
        'puntos_multiplicador' => '3',
        'puntos_penalizacion_pct' => '20',
        'margen_tolerancia_conciliacion' => '1',
    ] as $clave => $valor) {
        Configuracion::create([
            'clave' => $clave, 'valor' => $valor, 'tipo_dato' => 'decimal',
            'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id,
        ]);
    }
}

function crearDistribuidoraVale(): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA', 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::create(['name' => 'Distribuidora', 'factor_count' => 2]);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-TEST', 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearValePendiente(Distribuidora $distribuidora, float $monto, int $quincenas, string $tipo = 'pre-vale'): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Nuevo', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => $monto, 'quincenas' => $quincenas,
        'tipo' => $tipo, 'estado' => 'autorizado', 'fecha_autorizacion' => '2026-02-01',
    ]);
}

function crearExcelBancoVale(array $filas): UploadedFile
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
    seedConfiguracionBaseVale();
    SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);
});

it('convierte un pre-vale en vale-digital y lo marca pagado al liquidar su única/última cuota', function (): void {
    $distribuidora = crearDistribuidoraVale();
    $vale = crearValePendiente($distribuidora, 15000, 1, 'pre-vale');

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    $archivo = crearExcelBancoVale([
        [1, 'Pago', $relacion->referencia_pago, (float) $relacion->total_a_pagar, 'F001', '13/2/2026', '14:00', 'Transferencia'],
    ]);

    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    $vale->refresh();
    expect($vale->tipo)->toBe('vale-digital')
        ->and($vale->estado)->toBe('pagado');
});

it('no convierte ni marca pagado un vale mientras le falten cuotas por liquidar', function (): void {
    $distribuidora = crearDistribuidoraVale();
    $vale = crearValePendiente($distribuidora, 15000, 2, 'pre-vale');

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    $archivo = crearExcelBancoVale([
        [1, 'Pago', $relacion->referencia_pago, (float) $relacion->total_a_pagar, 'F002', '13/2/2026', '14:00', 'Transferencia'],
    ]);

    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    $relacion->refresh();
    $vale->refresh();

    expect($relacion->estado)->toBe('liquidada')
        ->and($vale->tipo)->toBe('pre-vale')
        ->and($vale->estado)->toBe('autorizado');
});

it('un vale que nació vale-digital (no es el primero del cliente) no cambia de tipo al pagarse', function (): void {
    $distribuidora = crearDistribuidoraVale();
    $vale = crearValePendiente($distribuidora, 15000, 1, 'vale-digital');

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    $archivo = crearExcelBancoVale([
        [1, 'Pago', $relacion->referencia_pago, (float) $relacion->total_a_pagar, 'F003', '13/2/2026', '14:00', 'Transferencia'],
    ]);

    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    $vale->refresh();
    expect($vale->tipo)->toBe('vale-digital')
        ->and($vale->estado)->toBe('pagado');
});
