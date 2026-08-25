<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\ExcedenteMovimiento;
use App\Models\Notificacion;
use App\Models\PuntoMovimiento;
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
 * conciliacion) -- por debajo de ese margen, o si nadie le hacía caso a la notificación, el
 * dinero de más quedaba invisible para siempre. Ahora se registra siempre (saldo_excedente +
 * excedente_movimientos) y se descuenta solo del siguiente corte que se le genere a esa misma
 * distribuidora.
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

it('un pago de más se registra en el saldo a favor de la distribuidora aunque esté por debajo del margen de tolerancia', function (): void {
    $distribuidora = crearDistribuidoraParaExcedente();
    crearValeParaExcedente($distribuidora, 15000, 8);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');
    $cajera = User::factory()->create();

    // margen_tolerancia_conciliacion sin configurar en este test -- default 0, así que
    // CUALQUIER excedente > 0 debe registrarse (aunque sea de $5).
    $montoExcedente = (float) $relacion->total_a_pagar + 5;
    $archivo = crearExcelBancoExcedente([
        [1, 'Pago de más', $relacion->referencia_pago, $montoExcedente, 'F-CHICO', '13/2/2026', '10:00', 'Transferencia'],
    ]);
    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, $cajera);

    expect((float) $distribuidora->fresh()->saldo_excedente)->toBe(5.0);

    $movimiento = ExcedenteMovimiento::where('distribuidora_id', $distribuidora->id)->where('tipo', 'generado')->first();
    expect($movimiento)->not->toBeNull()
        ->and((float) $movimiento->monto)->toBe(5.0)
        ->and($movimiento->relacion_id)->toBe($relacion->id);

    expect(Notificacion::where('destinatario_id', $distribuidora->usuario_id)->where('accion', 'excedente_generado')->exists())->toBeTrue();
});

it('el excedente se descuenta automáticamente del siguiente corte que se le genera a la distribuidora', function (): void {
    $distribuidora = crearDistribuidoraParaExcedente();
    $distribuidora->update(['saldo_excedente' => 200]);

    crearValeParaExcedente($distribuidora, 15000, 8);
    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    expect((float) $relacion->total_abonado)->toBe(200.0)
        ->and($relacion->estado)->toBe('parcial')
        ->and((float) $distribuidora->fresh()->saldo_excedente)->toBe(0.0);

    $movimiento = ExcedenteMovimiento::where('distribuidora_id', $distribuidora->id)->where('tipo', 'aplicado')->first();
    expect($movimiento)->not->toBeNull()
        ->and((float) $movimiento->monto)->toBe(-200.0)
        ->and($movimiento->relacion_id)->toBe($relacion->id);
});

it('si el saldo a favor alcanza para cubrir el corte completo, lo liquida de inmediato y marca el vale como pagado', function (): void {
    $distribuidora = crearDistribuidoraParaExcedente();
    // Muy por encima de lo que puede costar un solo vale de 15000/8 quincenas -- sobra de
    // margen para no depender del cálculo exacto de capital+comisión+interés+seguro.
    $distribuidora->update(['saldo_excedente' => 999999]);

    crearValeParaExcedente($distribuidora, 15000, 8);
    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    expect($relacion->estado)->toBe('liquidada');

    $relacion->loadMissing('detalles.vale');
    expect($relacion->detalles->every(fn ($d) => $d->estado === 'pagado'))->toBeTrue();

    // El saldo restante (lo que sobró después de cubrir este corte) se queda disponible, no se pierde.
    $sobrante = (float) $distribuidora->fresh()->saldo_excedente;
    expect($sobrante)->toBeGreaterThan(0);
});

it('no toca el saldo de otra distribuidora que no tiene nada que ver', function (): void {
    $distribuidora = crearDistribuidoraParaExcedente();
    $otra = crearDistribuidoraParaExcedente();
    $otra->update(['saldo_excedente' => 999]);

    crearValeParaExcedente($distribuidora, 15000, 8);
    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    expect((float) $relacion->total_abonado)->toBe(0.0)
        ->and((float) $otra->fresh()->saldo_excedente)->toBe(999.0);
});

it('GET /distribuidoras/{id}/saldo-disponible incluye el saldo a favor', function (): void {
    $distribuidora = crearDistribuidoraParaExcedente();
    $distribuidora->update(['saldo_excedente' => 42.5]);

    $rolGG = Role::firstOrCreate(['name' => 'Gerente General'], ['factor_count' => 1]);
    Sanctum::actingAs(User::factory()->create(['role_id' => $rolGG->id, 'is_active' => true]));

    $this->getJson("/api/v1/distribuidoras/{$distribuidora->id}/saldo-disponible")
        ->assertStatus(200)
        ->assertJsonPath('saldo_excedente', 42.5);
});
