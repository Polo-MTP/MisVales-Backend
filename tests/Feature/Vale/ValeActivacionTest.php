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
use App\Services\Vale\ValeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function crearDistribuidoraActivacion(string $numero = 'DIST-A'): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => $numero, 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearValeAutorizado(Distribuidora $distribuidora, float $monto = 5000, int $quincenas = 4): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => $monto, 'quincenas' => $quincenas,
        'tipo' => 'vale-digital', 'estado' => 'autorizado', 'fecha_autorizacion' => now(),
    ]);
}

function crearValeSolicitado(Distribuidora $distribuidora, float $monto = 5000, int $quincenas = 4): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => $monto, 'quincenas' => $quincenas,
        'tipo' => 'vale-digital', 'estado' => 'solicitado', 'fecha_solicitud' => now(),
    ]);
}

beforeEach(function (): void {
    $admin = User::factory()->create();
    Configuracion::create(['clave' => 'regla_50_pct', 'valor' => '50', 'tipo_dato' => 'decimal', 'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id]);
    Configuracion::create(['clave' => 'comision_base_pct', 'valor' => '10', 'tipo_dato' => 'decimal', 'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id]);
    Configuracion::create(['clave' => 'interes_pct_quincena', 'valor' => '5', 'tipo_dato' => 'decimal', 'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id]);
    Configuracion::create(['clave' => 'multa_no_pago', 'valor' => '300', 'tipo_dato' => 'decimal', 'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id]);
});

it('la propia distribuidora desactiva y reactiva un vale solicitado (aún no autorizado) sin necesitar aprobación de nadie más', function (): void {
    $distribuidora = crearDistribuidoraActivacion();
    $vale = crearValeSolicitado($distribuidora);
    $usuarioDistribuidora = $distribuidora->usuario;

    $valeDesactivado = app(ValeService::class)->desactivar($vale, $usuarioDistribuidora);
    expect($valeDesactivado->activo)->toBeFalse();

    $valeActivado = app(ValeService::class)->activar($vale, $usuarioDistribuidora);
    expect($valeActivado->activo)->toBeTrue();
});

it('no permite desactivar un vale ya autorizado, para no poder liberar crédito artificialmente', function (): void {
    $distribuidora = crearDistribuidoraActivacion();
    $vale = crearValeAutorizado($distribuidora, 5000, 4);
    $usuarioDistribuidora = $distribuidora->usuario;

    expect(fn () => app(ValeService::class)->desactivar($vale, $usuarioDistribuidora))
        ->toThrow(DomainException::class);

    expect($vale->fresh()->activo)->toBeTrue();
    expect((float) $distribuidora->fresh()->credito_disponible)->toBe(15000.0);
});

it('un vale nace activo al crearse', function (): void {
    $distribuidora = crearDistribuidoraActivacion();
    $vale = crearValeAutorizado($distribuidora);

    expect($vale->fresh()->activo)->toBeTrue();
});

it('una distribuidora no puede desactivar el vale de otra distribuidora', function (): void {
    $distribuidoraA = crearDistribuidoraActivacion('DIST-A');
    $distribuidoraB = crearDistribuidoraActivacion('DIST-B');
    $vale = crearValeAutorizado($distribuidoraA);

    app(ValeService::class)->desactivar($vale, $distribuidoraB->usuario);
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);

it('un vale desactivado por la distribuidora ya no se puede validar ni autorizar', function (): void {
    $distribuidora = crearDistribuidoraActivacion();
    $vale = crearValeSolicitado($distribuidora, 5000, 4);
    $usuarioDistribuidora = $distribuidora->usuario;
    $svc = app(ValeService::class);

    $svc->desactivar($vale, $usuarioDistribuidora);
    expect($vale->fresh()->activo)->toBeFalse();

    // La distribuidora "canceló" este vale -- la cajera no debe poder seguir validándolo ni
    // autorizándolo, aunque el estado siga en 'solicitado' (desactivar() no lo cambia).
    expect(fn () => $svc->validar($vale->fresh(), $usuarioDistribuidora, '012345678901234567', true, true))
        ->toThrow(DomainException::class);

    expect($vale->fresh()->estado)->toBe('solicitado');
});

it('REGRESION: una segunda autorización casi simultánea del mismo vale no pisa la fecha_autorizacion en silencio', function (): void {
    $distribuidora = crearDistribuidoraActivacion();
    $vale = crearValeSolicitado($distribuidora, 5000, 4);
    $svc = app(ValeService::class);

    $vale->estado = 'validado';
    $vale->save();

    // Simula dos peticiones casi simultáneas: ambas leen el vale en 'validado' antes de que
    // cualquiera guarde (aquí, dos referencias PHP distintas al mismo estado inicial).
    $valeParaPrimeraLlamada = $vale->fresh();
    $valeParaSegundaLlamada = $vale->fresh();

    $svc->autorizar($valeParaPrimeraLlamada, $distribuidora->usuario);
    $primeraFecha = $vale->fresh()->fecha_autorizacion;

    expect(fn () => $svc->autorizar($valeParaSegundaLlamada, $distribuidora->usuario))
        ->toThrow(DomainException::class);

    // La fecha de la primera autorización debe seguir intacta -- la segunda no debe haber
    // alcanzado a pisarla.
    expect($vale->fresh()->fecha_autorizacion->eq($primeraFecha))->toBeTrue();
    expect($vale->fresh()->estado)->toBe('autorizado');
});

it('un vale solicitado desactivado no cuenta en el crédito disponible ni entra a un nuevo corte', function (): void {
    $distribuidora = crearDistribuidoraActivacion();
    $vale = crearValeSolicitado($distribuidora, 5000, 4);

    app(ValeService::class)->desactivar($vale, $distribuidora->usuario);
    $distribuidora->refresh();

    expect((float) $distribuidora->credito_disponible)->toBe(20000.0);

    $relacion = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    expect($relacion)->toBeNull();
});
