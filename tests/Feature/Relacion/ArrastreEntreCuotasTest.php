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
use App\Services\Relacion\EstadoCuentaService;
use App\Services\Relacion\RelacionCalculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/**
 * "El pago de vales acumulados debe ser de uno, no vale por vale": si una quincena no se paga
 * y se genera la siguiente, su saldo (ya con multa) se absorbe dentro de la nueva -- deja de
 * existir como algo pagable por separado. Un solo pago del monto acumulado liquida ambas.
 */
function crearDistribuidoraArrastre(): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    // porcentaje_comision en 0 para que el descuento de categoría no complique la aritmética
    // del ejemplo (capital puro: $500/quincena, sin nada más que sumar o restar).
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 0, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearValeArrastre(Distribuidora $distribuidora, float $monto, int $quincenas): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => $monto, 'quincenas' => $quincenas,
        'tipo' => 'vale-digital', 'estado' => 'autorizado', 'fecha_autorizacion' => now(),
    ]);
}

function crearExcelBancoArrastre(array $filas): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['item', 'Concepto', 'Referencia', 'Pago', 'Folio de pago', 'Fecha de pago', 'Hora', 'tipo de pago'], null, 'A1');
    $sheet->fromArray($filas, null, 'A2');
    $ruta = sys_get_temp_dir().'/test_banco_arrastre_'.uniqid().'.xlsx';
    (new Xlsx($spreadsheet))->save($ruta);

    return new UploadedFile($ruta, 'banco.xlsx', null, null, true);
}

beforeEach(function (): void {
    $admin = User::factory()->create();
    foreach (['comision_base_pct' => '0', 'interes_pct_quincena' => '0', 'multa_no_pago' => '300'] as $clave => $valor) {
        Configuracion::create(['clave' => $clave, 'valor' => $valor, 'tipo_dato' => 'decimal', 'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id]);
    }
    SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 0, 'activo' => true]);
});

it('REGRESION: la quincena 2 absorbe el saldo de la 1 (con multa) y queda como una sola deuda acumulada', function (): void {
    $distribuidora = crearDistribuidoraArrastre();
    // comisión/interés/seguro en 0 arriba para que capital sea el único componente: $500/quincena, matemática exacta al ejemplo del enunciado.
    $vale = crearValeArrastre($distribuidora, 1000, 2);
    $calculoService = app(RelacionCalculoService::class);

    $corte1 = $calculoService->generarParaDistribuidora($distribuidora, '2026-01-15');
    expect((float) $corte1->total_a_pagar)->toBe(500.0);

    // Nadie paga la quincena 1. Se genera la quincena 2 -- absorbe los $500 + $300 de multa = $800.
    $corte2 = $calculoService->generarParaDistribuidora($distribuidora, '2026-01-31');

    expect((float) $corte2->total_a_pagar)->toBe(1300.0)
        ->and((float) $corte2->detalles->first()->arrastre)->toBe(800.0)
        ->and($corte2->detalles->first()->cuota_numero)->toBe(2);

    $corte1->refresh();
    expect($corte1->detalles->first()->estado)->toBe('arrastrada')
        ->and((float) $corte1->detalles->first()->total)->toBe(0.0)
        ->and((float) $corte1->saldo_pendiente)->toBe(0.0)
        ->and($corte1->detalles->first()->absorbida_en_detalle_id)->toBe($corte2->detalles->first()->id);

    // El estado de cuenta solo debe mostrar los $1,300 acumulados una vez, no 800 + 1300.
    $estadoCuenta = app(EstadoCuentaService::class)->obtenerPorDistribuidora($distribuidora);
    expect($estadoCuenta['total_pendiente'])->toBe(1300.0)
        ->and($estadoCuenta['clientes'])->toHaveCount(1)
        ->and($estadoCuenta['clientes']->first()['cuotas'])->toHaveCount(1);
});

it('REGRESION: un solo pago del monto acumulado liquida ambas quincenas de un golpe', function (): void {
    $distribuidora = crearDistribuidoraArrastre();
    $vale = crearValeArrastre($distribuidora, 1000, 2);
    $calculoService = app(RelacionCalculoService::class);

    $corte1 = $calculoService->generarParaDistribuidora($distribuidora, '2026-01-15');
    $corte2 = $calculoService->generarParaDistribuidora($distribuidora, '2026-01-31');

    expect((float) $corte2->total_a_pagar)->toBe(1300.0);

    $cajera = User::factory()->create();
    $archivo = crearExcelBancoArrastre([
        [1, 'Pago acumulado', $corte2->referencia_pago, 1300, 'F-ARR', '31/1/2026', '10:00', 'Transferencia'],
    ]);
    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    $corte2->refresh();
    $corte1->refresh();

    expect($corte2->estado)->toBe('liquidada')
        ->and((float) $corte2->total_abonado)->toBe(1300.0)
        ->and($corte2->detalles->first()->estado)->toBe('pagado')
        // La quincena 1 ya estaba en 0 desde que se absorbió -- este pago no la vuelve a tocar,
        // pero de cualquier forma sigue en 0 (nunca se le puede volver a cobrar por separado).
        ->and((float) $corte1->saldo_pendiente)->toBe(0.0);

    $estadoCuenta = app(EstadoCuentaService::class)->obtenerPorDistribuidora($distribuidora);
    expect($estadoCuenta['total_pendiente'])->toBe(0.0);
});
