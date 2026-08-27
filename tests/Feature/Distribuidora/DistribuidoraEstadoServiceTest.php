<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use App\Services\Distribuidora\DistribuidoraEstadoService;
use App\Services\Relacion\RelacionCalculoService;
use App\Services\Relacion\RelacionEstadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedConfigEstadoDist(): void
{
    $admin = User::factory()->create();
    foreach (['comision_base_pct' => '10', 'interes_pct_quincena' => '5', 'multa_no_pago' => '300', 'relaciones_impagas_para_morosidad' => '3'] as $clave => $valor) {
        Configuracion::create(['clave' => $clave, 'valor' => $valor, 'tipo_dato' => 'decimal', 'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id]);
    }
}

function crearDistribuidoraEstado(string $estado = 'ACTIVO'): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 30000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => $estado, 'sucursal_id' => $sucursal->id,
    ]);
}

function crearValeEstadoDist(Distribuidora $distribuidora, string $fechaAutorizacion): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => 1000, 'quincenas' => 1,
        'tipo' => 'vale-digital', 'estado' => 'autorizado', 'fecha_autorizacion' => $fechaAutorizacion,
    ]);
}

it('destroy() sincroniza usuario->is_active y deja rastro en el historial, no solo cambia el campo estado', function (): void {
    $distribuidora = crearDistribuidoraEstado('ACTIVO');
    $gerente = User::factory()->create();

    app(DistribuidoraEstadoService::class)->cambiarEstado($distribuidora, 'INACTIVO', 'Distribuidora desactivada por gerencia.', $gerente);

    expect($distribuidora->fresh()->estado)->toBe('INACTIVO')
        ->and($distribuidora->fresh()->usuario->is_active)->toBeFalse()
        ->and($distribuidora->fresh()->historialEstados()->latest('id')->first()->estado_nuevo)->toBe('INACTIVO');
});

it('no se puede reactivar una distribuidora MOROSA mientras tenga relaciones vencidas/en pérdida sin resolver', function (): void {
    seedConfigEstadoDist();
    $distribuidora = crearDistribuidoraEstado();

    foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $fecha) {
        crearValeEstadoDist($distribuidora, $fecha);
        app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, $fecha);
    }

    app(RelacionEstadoService::class)->marcarVencidas('2026-04-01');
    expect($distribuidora->fresh()->estado)->toBe('MOROSO');

    $gerente = User::factory()->create();
    expect(fn () => app(DistribuidoraEstadoService::class)->cambiarEstado($distribuidora->fresh(), 'ACTIVO', 'Reactivación manual', $gerente))
        ->toThrow(DomainException::class);

    expect($distribuidora->fresh()->estado)->toBe('MOROSO');
});

it('sí se puede reactivar una distribuidora MOROSA una vez que sus relaciones vencidas se resolvieron', function (): void {
    seedConfigEstadoDist();
    $distribuidora = crearDistribuidoraEstado();

    foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $fecha) {
        crearValeEstadoDist($distribuidora, $fecha);
        app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, $fecha);
    }

    app(RelacionEstadoService::class)->marcarVencidas('2026-04-01');
    expect($distribuidora->fresh()->estado)->toBe('MOROSO');

    // Se resuelven las relaciones directamente (liquidadas a mano, fuera de este service).
    Relacion::where('distribuidora_id', $distribuidora->id)->update(['estado' => 'liquidada']);

    $gerente = User::factory()->create();
    $reactivada = app(DistribuidoraEstadoService::class)->cambiarEstado($distribuidora->fresh(), 'ACTIVO', 'Reactivación tras liquidar adeudo', $gerente);

    expect($reactivada->estado)->toBe('ACTIVO');
});
