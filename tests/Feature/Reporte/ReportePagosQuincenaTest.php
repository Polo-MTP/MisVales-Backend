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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

uses(RefreshDatabase::class);

function crearDistribuidoraParaReporte(): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearClienteParaReporte(string $nombre): Cliente
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => $nombre, 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);

    return Cliente::create(['datos_id' => $datos->id, 'estado' => true]);
}

function crearValeParaReporte(Distribuidora $distribuidora, Cliente $cliente): Vale
{
    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => 3000, 'quincenas' => 3,
        'tipo' => 'vale-digital', 'estado' => 'autorizado', 'fecha_autorizacion' => now(),
    ]);
}

function crearCorteConCuota(Distribuidora $distribuidora, string $fechaCorte, Vale $vale, int $cuotaNumero, int $cuotasTotales, string $estado, float $pago = 0): Relacion
{
    $relacion = Relacion::create([
        'distribuidora_id' => $distribuidora->id, 'sucursal_id' => $distribuidora->sucursal_id,
        'referencia_pago' => 'REF-'.uniqid(), 'fecha_corte' => $fechaCorte, 'fecha_limite_pago' => $fechaCorte,
        'limite_credito_snapshot' => 20000, 'estado' => 'pendiente',
    ]);

    RelacionDetalle::create([
        'relacion_id' => $relacion->id, 'vale_id' => $vale->id, 'concepto' => sprintf('%05d%04d', $vale->id, $cuotaNumero),
        'cliente_id' => $vale->cliente_id,
        'cuota_numero' => $cuotaNumero, 'cuotas_totales' => $cuotasTotales, 'capital' => 1000, 'comision' => 100,
        'interes' => 50, 'seguro' => 0, 'categoria' => 0, 'recargo' => $estado === 'vencida' ? 300 : 0, 'pago' => $pago,
        'total' => 1150, 'estado' => $estado,
    ]);

    return $relacion;
}

it('el Gerente General descarga el Excel con las cuotas pendientes, omitiendo las de en medio ya liquidadas', function (): void {
    $distribuidora = crearDistribuidoraParaReporte();
    $cliente = crearClienteParaReporte('Ana');
    $vale = crearValeParaReporte($distribuidora, $cliente);

    // Vale de 3 cuotas: 1 (pagada, primera -- debe aparecer), 2 (pagada, de en medio -- se
    // omite), 3 (pendiente, última -- debe aparecer).
    crearCorteConCuota($distribuidora, '2026-01-15', $vale, 1, 3, 'pagado', 1150);
    crearCorteConCuota($distribuidora, '2026-01-31', $vale, 2, 3, 'pagado', 1150);
    $ultimaRelacion = crearCorteConCuota($distribuidora, '2026-02-15', $vale, 3, 3, 'pendiente', 0);

    $gerente = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Gerente General'])->id, 'is_active' => true]);
    Sanctum::actingAs($gerente);

    $response = $this->get('/api/v1/reportes/pagos-quincena?'.http_build_query([
        'distribuidora_id' => $distribuidora->id,
        'hasta_relacion_id' => $ultimaRelacion->id,
    ]));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $archivoTemporal = sys_get_temp_dir().'/reporte_test_'.uniqid().'.xlsx';
    file_put_contents($archivoTemporal, $response->streamedContent());

    $sheet = (new Xlsx())->load($archivoTemporal)->getActiveSheet();
    $filas = $sheet->toArray();
    unlink($archivoTemporal);

    // Encabezado + cuota 1 + cuota 3 (la 2 se omitió) + fila en blanco + TOTALES = 5 filas.
    expect($filas[0])->toBe(['Concepto', 'Cliente', 'Producto', 'Cuota', 'Pago', 'Comisión', 'Recargo', 'Total'])
        ->and($filas[1][3])->toBe('1/3')
        ->and($filas[2][3])->toBe('3/3')
        ->and(count($filas))->toBe(5);
});

it('rechaza con 403 a quien no sea Gerente General', function (): void {
    $distribuidora = crearDistribuidoraParaReporte();
    $cliente = crearClienteParaReporte('Ana');
    $vale = crearValeParaReporte($distribuidora, $cliente);
    $relacion = crearCorteConCuota($distribuidora, '2026-01-15', $vale, 1, 1, 'pendiente');

    $gerenteSucursal = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Gerente de Sucursal'])->id]);
    Sanctum::actingAs($gerenteSucursal);

    $this->get('/api/v1/reportes/pagos-quincena?'.http_build_query([
        'distribuidora_id' => $distribuidora->id,
        'hasta_relacion_id' => $relacion->id,
    ]))->assertStatus(403);
});

it('rechaza con 404 un hasta_relacion_id que no pertenece a la distribuidora indicada', function (): void {
    $distribuidoraA = crearDistribuidoraParaReporte();
    $distribuidoraB = crearDistribuidoraParaReporte();
    $cliente = crearClienteParaReporte('Ana');
    $valeB = crearValeParaReporte($distribuidoraB, $cliente);
    $relacionDeB = crearCorteConCuota($distribuidoraB, '2026-01-15', $valeB, 1, 1, 'pendiente');

    $gerente = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Gerente General'])->id, 'is_active' => true]);
    Sanctum::actingAs($gerente);

    $this->get('/api/v1/reportes/pagos-quincena?'.http_build_query([
        'distribuidora_id' => $distribuidoraA->id,
        'hasta_relacion_id' => $relacionDeB->id,
    ]))->assertStatus(404);
});
