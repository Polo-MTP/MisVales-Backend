<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use App\Services\Relacion\RelacionCalculoService;
use App\Services\Relacion\RelacionEstadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedConfiguracionMorosidad(): void
{
    $admin = User::factory()->create();

    foreach ([
        'comision_base_pct' => '10',
        'interes_pct_quincena' => '5',
        'multa_no_pago' => '300',
        'limite_perdones_relacion' => '2',
        'relaciones_impagas_para_morosidad' => '3',
    ] as $clave => $valor) {
        Configuracion::create(['clave' => $clave, 'valor' => $valor, 'tipo_dato' => 'decimal', 'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id]);
    }
}

function crearDistribuidoraMorosidad(): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 30000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearValeMorosidad(Distribuidora $distribuidora, string $fechaAutorizacion): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => 1000, 'quincenas' => 1,
        'tipo' => 'vale-digital', 'estado' => 'autorizado', 'fecha_autorizacion' => $fechaAutorizacion,
    ]);
}

it('pasa la distribuidora a MOROSO automáticamente al acumular 3 relaciones sin pagar', function (): void {
    seedConfiguracionMorosidad();
    $distribuidora = crearDistribuidoraMorosidad();

    foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $fecha) {
        crearValeMorosidad($distribuidora, $fecha);
        app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, $fecha);
    }

    app(RelacionEstadoService::class)->marcarVencidas('2026-04-01');

    expect($distribuidora->fresh()->estado)->toBe('MOROSO');

    $historial = $distribuidora->fresh()->historialEstados()->latest('id')->first();
    expect($historial->estado_nuevo)->toBe('MOROSO')
        ->and($historial->cambiado_por)->toBeNull(); // lo disparó el sistema, no una persona
});

it('no pasa a MOROSO con solo 2 relaciones vencidas (por debajo del límite configurado)', function (): void {
    seedConfiguracionMorosidad();
    $distribuidora = crearDistribuidoraMorosidad();

    foreach (['2026-01-01', '2026-02-01'] as $fecha) {
        crearValeMorosidad($distribuidora, $fecha);
        app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, $fecha);
    }

    app(RelacionEstadoService::class)->marcarVencidas('2026-04-01');

    expect($distribuidora->fresh()->estado)->toBe('ACTIVO');
});

it('no reactiva sola una distribuidora que ya estaba MOROSA/RECHAZADA por otra razón', function (): void {
    seedConfiguracionMorosidad();
    $distribuidora = crearDistribuidoraMorosidad();
    $distribuidora->update(['estado' => 'RECHAZADO']);

    foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $fecha) {
        crearValeMorosidad($distribuidora, $fecha);
        app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, $fecha);
    }

    app(RelacionEstadoService::class)->marcarVencidas('2026-04-01');

    // No la "sube" a MOROSO ni la toca: el chequeo automático solo aplica a distribuidoras
    // ACTIVO/EN_VERIFICACION, no reinterpreta un estado que ya fue decisión de alguien más.
    expect($distribuidora->fresh()->estado)->toBe('RECHAZADO');
});
