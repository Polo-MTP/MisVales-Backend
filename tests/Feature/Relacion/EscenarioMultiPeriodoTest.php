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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function seedConfigEscenario(): void
{
    $admin = User::factory()->create();
    foreach (['comision_base_pct' => '10', 'interes_pct_quincena' => '5', 'multa_no_pago' => '300'] as $clave => $valor) {
        Configuracion::create([
            'clave' => $clave, 'valor' => $valor, 'tipo_dato' => 'decimal',
            'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id,
        ]);
    }
}

function crearDistribuidoraEscenario(string $numero, string $categoriaNombre, float $porcentajeCategoria): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Suc-'.$numero, 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => $categoriaNombre.'-'.uniqid(), 'porcentaje_comision' => $porcentajeCategoria, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => $numero, 'limite_credito' => 50000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearValeEscenario(Distribuidora $distribuidora, float $monto, int $quincenas, string $fechaAutorizacion): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'De Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => $monto,
        'quincenas' => $quincenas, 'tipo' => 'vale-digital', 'estado' => 'autorizado', 'fecha_autorizacion' => $fechaAutorizacion,
    ]);
}

function crearExcelBancoEscenario(array $filas): UploadedFile
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
    seedConfigEscenario();
    SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);
});

it('escenario real: vale + corte + abono parcial + corte con recargo + segundo vale a otra distribuidora sin pagar', function (): void {
    $calculoService = app(RelacionCalculoService::class);
    $conciliacionService = app(ConciliacionBancariaService::class);
    $cajera = User::factory()->create();

    // --- Distribuidora 1 (PLATA 6%): vale de $10,000 a 8 quincenas ---
    $d1 = crearDistribuidoraEscenario('DIST-1', 'PLATA', 6);
    crearValeEscenario($d1, 10000, 8, '2026-02-01');

    // Corte 1/8: recién dado el vale, nadie ha pagado nada todavía.
    $corte1 = $calculoService->generarParaDistribuidora($d1, '2026-02-15');
    expect((float) $corte1->total_a_pagar)->toBe(1812.0)
        ->and((float) $corte1->total_categoria)->toBe(75.0)
        ->and($corte1->detalles->first()->cuota_numero)->toBe(1)
        ->and((float) $corte1->detalles->first()->recargo)->toBe(0.0);

    // Le abonan $1,000 -- NO cubre el total ($1,812) -> la relación queda 'parcial', y como el
    // corte es de un solo vale (sin ambigüedad posible sobre a cuál detalle pertenece, ver
    // ConciliacionBancariaService::detalleUnicoSiAplica()) el abono también se refleja en el
    // detalle: 'parcial', no 'pagado' -- eso es lo que sigue disparando el recargo del corte 2.
    $archivo1 = crearExcelBancoEscenario([[1, 'Abono parcial', $corte1->referencia_pago, 1000, 'F001', '14/2/2026', '10:00', 'Transferencia']]);
    $conciliacionService->importarArchivo($archivo1, null, $cajera);
    $corte1->refresh();
    expect($corte1->estado)->toBe('parcial')
        ->and((float) $corte1->total_abonado)->toBe(1000.0)
        ->and((float) $corte1->detalles->first()->pago)->toBe(1000.0)
        ->and($corte1->detalles->first()->estado)->toBe('parcial');

    // Corte 2/8: como la cuota 1 no quedó 'pagado' (el abono no la liquidó), esta trae recargo.
    $corte2 = $calculoService->generarParaDistribuidora($d1, '2026-03-15');
    expect((float) $corte2->total_a_pagar)->toBe(2187.5)
        ->and((float) $corte2->total_recargos)->toBe(300.0)
        ->and($corte2->detalles->first()->cuota_numero)->toBe(2);

    // --- Distribuidora 2 (ORO 10%): vale de $8,000 a 4 quincenas, no le abonan nada ---
    $d2 = crearDistribuidoraEscenario('DIST-2', 'ORO', 10);
    crearValeEscenario($d2, 8000, 4, '2026-02-01');

    $corteD2 = $calculoService->generarParaDistribuidora($d2, '2026-02-15');
    expect((float) $corteD2->total_a_pagar)->toBe(2425.0)
        ->and((float) $corteD2->total_recargos)->toBe(0.0)
        ->and($corteD2->detalles->first()->cuota_numero)->toBe(1);

    dump([
        'D1 - Vale $10,000 / 8 quincenas / categoría PLATA 6% / abono parcial $1,000 en el corte 1' => [
            'Corte 1 (cuota 1 de 8) - recién dado el vale' => [
                'a_pagar' => (float) $corte1->total_a_pagar,
                'recargo' => (float) $corte1->total_recargos,
                'abonado' => (float) $corte1->total_abonado,
                'estado' => $corte1->estado,
            ],
            'Corte 2 (cuota 2 de 8) - tras abono parcial en el corte anterior' => [
                'a_pagar' => (float) $corte2->total_a_pagar,
                'recargo' => (float) $corte2->total_recargos,
                'abonado' => (float) $corte2->total_abonado,
                'estado' => $corte2->estado,
            ],
        ],
        'D2 - Vale $8,000 / 4 quincenas / categoría ORO 10% / sin abonar' => [
            'Corte 1 (cuota 1 de 4) - recién dado el vale, sin pago' => [
                'a_pagar' => (float) $corteD2->total_a_pagar,
                'recargo' => (float) $corteD2->total_recargos,
                'abonado' => (float) $corteD2->total_abonado,
                'estado' => $corteD2->estado,
            ],
        ],
    ]);
});
