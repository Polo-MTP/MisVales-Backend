<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\Notificacion;
use App\Models\Role;
use App\Models\SeguroTabla;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use App\Services\AltaProveedor\SolicitudProveedorService;
use App\Services\Distribuidora\DistribuidoraService;
use App\Services\Notificacion\NotificacionService;
use App\Services\Relacion\ConciliacionBancariaService;
use App\Services\Relacion\RelacionCalculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function seedConfiguracionBaseTriggers(): void
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

function crearSucursalTriggers(): Sucursal
{
    return Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
}

function crearDistribuidoraTriggers(Sucursal $sucursal, array $overrides = []): Distribuidora
{
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora'], ['factor_count' => 2]);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create(array_merge([
        'usuario_id' => $user->id,
        'numero_distribuidora' => 'DIST-'.uniqid(),
        'limite_credito' => 20000,
        'categoria_id' => $categoria->id,
        'puntos_acumulados' => 0,
        'estado' => 'ACTIVO',
        'sucursal_id' => $sucursal->id,
    ], $overrides));
}

function crearValeTriggers(Distribuidora $distribuidora, float $monto, int $quincenas, ?string $fechaAutorizacion = null): Vale
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

function crearExcelBancoTriggers(array $filas): UploadedFile
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
    seedConfiguracionBaseTriggers();
    SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);
});

it('generar el corte de una distribuidora le crea una notificación de corte_listo', function (): void {
    $sucursal = crearSucursalTriggers();
    $distribuidora = crearDistribuidoraTriggers($sucursal);
    crearValeTriggers($distribuidora, 15000, 8);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    $notificacion = Notificacion::where('destinatario_id', $distribuidora->usuario_id)
        ->where('accion', 'corte_listo')
        ->first();

    expect($notificacion)->not->toBeNull()
        ->and($notificacion->recurso)->toBe('Relación '.$relacion->referencia_pago);
});

it('un pago anticipado que genera puntos notifica a la distribuidora', function (): void {
    // totalProductosOtorgadosEnCorte suma vales desde distribuidora->created_at cuando no hay
    // corte anterior -- hay que viajar antes de crear la distribuidora para que el vale
    // (fecha_autorizacion 2026-02-01) quede dentro de la ventana, igual que en producción.
    $this->travelTo('2026-01-15');

    $sucursal = crearSucursalTriggers();
    $distribuidora = crearDistribuidoraTriggers($sucursal);
    crearValeTriggers($distribuidora, 15000, 8, '2026-02-01');

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    $archivo = crearExcelBancoTriggers([
        [1, 'Pago de distribuidora', $relacion->referencia_pago, 2712, 'F001', '13/2/2026', '14:00', 'Transferencia'],
    ]);
    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, User::factory()->create());

    $notificacion = Notificacion::where('destinatario_id', $distribuidora->usuario_id)
        ->where('accion', 'puntos_generados')
        ->first();

    expect($notificacion)->not->toBeNull();
});

it('un pago fuera de tiempo que penaliza puntos notifica a la distribuidora', function (): void {
    $sucursal = crearSucursalTriggers();
    $distribuidora = crearDistribuidoraTriggers($sucursal, ['puntos_acumulados' => 100]);
    crearValeTriggers($distribuidora, 15000, 8, '2026-02-01');

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    // fecha_limite_pago es 2026-02-16; este pago llega después.
    $archivo = crearExcelBancoTriggers([
        [1, 'Pago tardío', $relacion->referencia_pago, 2712, 'F002', '20/2/2026', '14:00', 'Transferencia'],
    ]);
    app(ConciliacionBancariaService::class)->importarArchivo($archivo, null, User::factory()->create());

    $notificacion = Notificacion::where('destinatario_id', $distribuidora->usuario_id)
        ->where('accion', 'puntos_penalizados')
        ->first();

    expect($notificacion)->not->toBeNull();
});

it('asignar crédito por primera vez notifica credito_asignado y una segunda asignación notifica credito_incrementado', function (): void {
    $sucursal = crearSucursalTriggers();
    $categoria = CategoriaDistribuidora::create(['nombre' => 'COBRE-'.uniqid(), 'porcentaje_comision' => 4, 'activo' => true]);
    $distribuidora = crearDistribuidoraTriggers($sucursal, ['estado' => 'PENDIENTE_APROBACION', 'limite_credito' => 0]);

    $service = app(DistribuidoraService::class);
    $service->asignarCredito($distribuidora, 20000, $categoria->id);

    expect(Notificacion::where('destinatario_id', $distribuidora->usuario_id)->where('accion', 'credito_asignado')->exists())->toBeTrue();

    $service->asignarCredito($distribuidora->fresh(), 30000, $categoria->id);

    expect(Notificacion::where('destinatario_id', $distribuidora->usuario_id)->where('accion', 'credito_incrementado')->exists())->toBeTrue();
});

it('una distribuidora que cae en MOROSO notifica a todas las cajeras de su sucursal, no a las de otra', function (): void {
    $sucursalA = crearSucursalTriggers();
    $sucursalB = crearSucursalTriggers();
    $distribuidora = crearDistribuidoraTriggers($sucursalA);

    $roleCajera = Role::firstOrCreate(['name' => 'Cajera']);
    $cajeraA1 = User::factory()->create(['role_id' => $roleCajera->id, 'sucursal_id' => $sucursalA->id, 'is_active' => true]);
    $cajeraA2 = User::factory()->create(['role_id' => $roleCajera->id, 'sucursal_id' => $sucursalA->id, 'is_active' => true]);
    $cajeraB = User::factory()->create(['role_id' => $roleCajera->id, 'sucursal_id' => $sucursalB->id, 'is_active' => true]);

    app(DistribuidoraService::class)->cambiarEstado($distribuidora, 'MOROSO', 'Falta de pago', User::factory()->create());

    expect(Notificacion::where('destinatario_id', $cajeraA1->id)->where('accion', 'distribuidora_morosa')->exists())->toBeTrue()
        ->and(Notificacion::where('destinatario_id', $cajeraA2->id)->where('accion', 'distribuidora_morosa')->exists())->toBeTrue()
        ->and(Notificacion::where('destinatario_id', $cajeraB->id)->where('accion', 'distribuidora_morosa')->exists())->toBeFalse();
});

it('el gerente de sucursal solo es notificado cuando el verificador marca cumple, no cuando rechaza', function (): void {
    $sucursal = crearSucursalTriggers();
    $roleCoordinador = Role::firstOrCreate(['name' => 'Coordinador']);
    $roleVerificador = Role::firstOrCreate(['name' => 'Verificador']);
    $roleGerente = Role::firstOrCreate(['name' => 'Gerente de Sucursal']);

    $coordinador = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $verificador = User::factory()->create(['role_id' => $roleVerificador->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $gerente = User::factory()->create(['role_id' => $roleGerente->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $service = app(SolicitudProveedorService::class);

    $data = [
        'calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón',
        'nombre' => 'Juan', 'apellido_paterno' => 'Pérez', 'curp' => 'CURP'.uniqid(),
        'razon_social' => 'Juan Pérez', 'rfc' => 'RFC'.uniqid(),
    ];

    $solicitudRechazada = $service->crearSolicitud($data, $coordinador);
    $service->verificarSolicitud($solicitudRechazada, ['cumple' => false, 'comentario_verificador' => 'No cumple'], $verificador);

    expect(Notificacion::where('destinatario_id', $coordinador->id)->where('accion', 'solicitud_rechazada_verificador')->exists())->toBeTrue()
        ->and(Notificacion::where('destinatario_id', $gerente->id)->where('accion', 'solicitud_lista_para_autorizar')->exists())->toBeFalse();

    $data['curp'] = 'CURP'.uniqid();
    $data['rfc'] = 'RFC'.uniqid();
    $solicitudAprobada = $service->crearSolicitud($data, $coordinador);
    $service->verificarSolicitud($solicitudAprobada, ['cumple' => true, 'comentario_verificador' => 'Todo en orden'], $verificador);

    expect(Notificacion::where('destinatario_id', $coordinador->id)->where('accion', 'solicitud_verificada')->exists())->toBeTrue()
        ->and(Notificacion::where('destinatario_id', $gerente->id)->where('accion', 'solicitud_lista_para_autorizar')->exists())->toBeTrue();
});

it('solo el propio destinatario puede marcar una notificación como leída', function (): void {
    $sucursal = crearSucursalTriggers();
    $distribuidora = crearDistribuidoraTriggers($sucursal);
    $otro = User::factory()->create();

    $notificacion = app(NotificacionService::class)->crear($distribuidora->usuario, 'corte_listo', 'Relación X');

    expect($notificacion->leido_at)->toBeNull();

    expect(fn () => app(NotificacionService::class)->marcarLeida($notificacion, $otro))
        ->toThrow(DomainException::class);

    $marcada = app(NotificacionService::class)->marcarLeida($notificacion, $distribuidora->usuario);
    expect($marcada->leido_at)->not->toBeNull();
});

it('una distribuidora solo ve por HTTP sus propias notificaciones, no las de otra distribuidora', function (): void {
    $sucursal = crearSucursalTriggers();
    $distribuidoraA = crearDistribuidoraTriggers($sucursal);
    $distribuidoraB = crearDistribuidoraTriggers($sucursal);

    app(NotificacionService::class)->crear($distribuidoraA->usuario, 'corte_listo', 'Relación A');
    app(NotificacionService::class)->crear($distribuidoraB->usuario, 'corte_listo', 'Relación B');

    Laravel\Sanctum\Sanctum::actingAs($distribuidoraA->usuario);

    $this->getJson('/api/v1/notificaciones')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.recurso', 'Relación A');
});
