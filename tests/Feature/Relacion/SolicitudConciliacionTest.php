<?php

declare(strict_types=1);

use App\Models\AbonoConciliacion;
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
use App\Services\Relacion\RelacionCalculoService;
use App\Services\Relacion\SolicitudConciliacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedConfiguracionBaseConciliacion(): void
{
    $admin = User::factory()->create();

    foreach ([
        'comision_base_pct' => '10',
        'interes_pct_quincena' => '5',
        'multa_no_pago' => '300',
        'margen_tolerancia_conciliacion' => '1',
        'puntos_divisor' => '1200',
        'puntos_multiplicador' => '3',
        'puntos_penalizacion_pct' => '20',
    ] as $clave => $valor) {
        Configuracion::create([
            'clave' => $clave, 'valor' => $valor, 'tipo_dato' => 'decimal',
            'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id,
        ]);
    }
}

function crearSucursalConUsuarios(): array
{
    $sucursal = Sucursal::create(['nombre' => 'Sucursal 1', 'codigo' => 'SUC-001', 'es_matriz' => false, 'is_active' => true]);
    $roleCajera = Role::create(['name' => 'Cajera']);
    $roleCoordinador = Role::create(['name' => 'Coordinador']);
    $roleGerenteGeneral = Role::create(['name' => 'Gerente General']);

    $cajera = User::factory()->create(['role_id' => $roleCajera->id, 'sucursal_id' => $sucursal->id]);
    $coordinador = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id]);
    $gerenteGeneral = User::factory()->create(['role_id' => $roleGerenteGeneral->id, 'sucursal_id' => $sucursal->id]);

    $otraSucursal = Sucursal::create(['nombre' => 'Sucursal 2', 'codigo' => 'SUC-002', 'es_matriz' => false, 'is_active' => true]);
    $coordinadorOtraSucursal = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $otraSucursal->id]);

    return compact('sucursal', 'cajera', 'coordinador', 'gerenteGeneral', 'coordinadorOtraSucursal');
}

function crearDistribuidoraYAbonoSinCoincidencia(Sucursal $sucursal): array
{
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA', 'porcentaje_comision' => 6, 'activo' => true]);
    $roleDistribuidora = Role::firstOrCreate(['name' => 'Distribuidora']);
    $usuarioDistribuidora = User::factory()->create(['role_id' => $roleDistribuidora->id, 'sucursal_id' => $sucursal->id]);

    $distribuidora = Distribuidora::create([
        'usuario_id' => $usuarioDistribuidora->id, 'numero_distribuidora' => 'DIST-TEST', 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'De Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => 15000, 'quincenas' => 8,
        'tipo' => 'vale-digital', 'estado' => 'autorizado', 'fecha_autorizacion' => '2026-02-01',
    ]);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    $abono = AbonoConciliacion::create([
        'relacion_id' => null,
        'referencia_leida' => 'REF-MAL-ESCRITA',
        'monto' => (float) $relacion->total_a_pagar,
        'fecha_pago' => '2026-02-13',
        'tipo_pago' => 'transferencia',
        'estado' => 'sin_coincidencia',
        'lote_archivo' => 'test-lote',
        'subido_por' => $usuarioDistribuidora->id,
    ]);

    return compact('distribuidora', 'relacion', 'abono');
}

beforeEach(function (): void {
    SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);
    seedConfiguracionBaseConciliacion();
});

it('la cajera no puede ejecutar una conciliación manual sin una solicitud aprobada', function (): void {
    ['sucursal' => $sucursal, 'cajera' => $cajera] = crearSucursalConUsuarios();
    ['relacion' => $relacion, 'abono' => $abono] = crearDistribuidoraYAbonoSinCoincidencia($sucursal);

    $solicitud = app(SolicitudConciliacionService::class)->solicitar($abono, $relacion->id, 'Referencia mal escrita', $cajera);

    app(SolicitudConciliacionService::class)->ejecutar($solicitud, $cajera);
})->throws(DomainException::class);

it('un coordinador de otra sucursal no puede aprobar la solicitud de una cajera', function (): void {
    ['sucursal' => $sucursal, 'cajera' => $cajera, 'coordinadorOtraSucursal' => $coordinadorOtraSucursal] = crearSucursalConUsuarios();
    ['relacion' => $relacion, 'abono' => $abono] = crearDistribuidoraYAbonoSinCoincidencia($sucursal);

    $solicitud = app(SolicitudConciliacionService::class)->solicitar($abono, $relacion->id, 'Referencia mal escrita', $cajera);

    app(SolicitudConciliacionService::class)->decidir($solicitud, 'aprobada', null, $coordinadorOtraSucursal);
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);

it('tras aprobar, solo la cajera solicitante puede ejecutar y el abono queda conciliado_manual', function (): void {
    ['sucursal' => $sucursal, 'cajera' => $cajera, 'coordinador' => $coordinador] = crearSucursalConUsuarios();
    ['relacion' => $relacion, 'abono' => $abono] = crearDistribuidoraYAbonoSinCoincidencia($sucursal);

    $solicitud = app(SolicitudConciliacionService::class)->solicitar($abono, $relacion->id, 'Referencia mal escrita', $cajera);
    $solicitud = app(SolicitudConciliacionService::class)->decidir($solicitud, 'aprobada', 'Confirmado con la distribuidora', $coordinador);

    expect($solicitud->estado)->toBe('aprobada');

    $abonoConciliado = app(SolicitudConciliacionService::class)->ejecutar($solicitud, $cajera);

    expect($abonoConciliado->estado)->toBe('conciliado_manual')
        ->and($abonoConciliado->conciliado_por)->toBe($cajera->id)
        ->and($abonoConciliado->autorizado_por)->toBe($coordinador->id)
        ->and($solicitud->fresh()->estado)->toBe('aplicada');
});

it('otra cajera distinta a la solicitante no puede ejecutar la conciliación ya aprobada', function (): void {
    ['sucursal' => $sucursal, 'cajera' => $cajera, 'coordinador' => $coordinador] = crearSucursalConUsuarios();
    ['relacion' => $relacion, 'abono' => $abono] = crearDistribuidoraYAbonoSinCoincidencia($sucursal);

    $otraCajera = User::factory()->create(['role_id' => $cajera->role_id, 'sucursal_id' => $sucursal->id]);

    $solicitud = app(SolicitudConciliacionService::class)->solicitar($abono, $relacion->id, 'Referencia mal escrita', $cajera);
    $solicitud = app(SolicitudConciliacionService::class)->decidir($solicitud, 'aprobada', null, $coordinador);

    app(SolicitudConciliacionService::class)->ejecutar($solicitud, $otraCajera);
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);

it('el coordinador puede listar por HTTP las solicitudes de conciliación pendientes de su sucursal', function (): void {
    ['sucursal' => $sucursal, 'cajera' => $cajera, 'coordinador' => $coordinador, 'coordinadorOtraSucursal' => $coordinadorOtraSucursal] = crearSucursalConUsuarios();
    ['relacion' => $relacion, 'abono' => $abono] = crearDistribuidoraYAbonoSinCoincidencia($sucursal);

    app(SolicitudConciliacionService::class)->solicitar($abono, $relacion->id, 'Referencia mal escrita', $cajera);

    Sanctum::actingAs($coordinador->fresh());

    $response = $this->getJson('/api/v1/conciliaciones/autorizaciones');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.estado', 'pendiente');

    Sanctum::actingAs($coordinadorOtraSucursal->fresh());

    $this->getJson('/api/v1/conciliaciones/autorizaciones')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.data');
});
