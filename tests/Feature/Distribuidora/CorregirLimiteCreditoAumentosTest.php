<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\SolicitudAumentoCredito;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Simula el estado que dejó el bug de SolicitudAumentoCreditoService::decidir() antes del fix
 * (sumaba monto_otorgado al límite ya vigente): crea la distribuidora directamente con el
 * limite_credito ya inflado ($100,000, como si $25,000 + $75,000 ya se hubiera guardado), y
 * su solicitud aprobada real con monto_otorgado=75000 (el límite correcto que debió quedar).
 */
function crearDistribuidoraConLimiteInflado(float $limiteInflado, float $montoOtorgadoReal, ?string $fechaDecision = null): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Suc-'.uniqid(), 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $usuario = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $distribuidora = Distribuidora::create([
        'usuario_id' => $usuario->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => $limiteInflado,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    SolicitudAumentoCredito::create([
        'distribuidora_id' => $distribuidora->id,
        'solicitado_por' => $usuario->id,
        'limite_credito_anterior' => 25000,
        'monto_solicitado' => $montoOtorgadoReal,
        'monto_otorgado' => $montoOtorgadoReal,
        'motivo' => 'Prueba',
        'estado' => 'aprobada',
        'decidido_por' => $usuario->id,
        'fecha_decision' => $fechaDecision ?? now(),
    ]);

    return $distribuidora;
}

it('el preview (sin --apply) reporta la inconsistencia pero no toca la base de datos', function (): void {
    $distribuidora = crearDistribuidoraConLimiteInflado(limiteInflado: 100000, montoOtorgadoReal: 75000);

    $this->artisan('distribuidoras:corregir-limite-credito')
        ->expectsOutputToContain('75,000.00')
        ->expectsOutputToContain('Este fue solo un preview')
        ->assertExitCode(0);

    expect((float) $distribuidora->fresh()->limite_credito)->toBe(100000.0);
});

it('--apply corrige el limite_credito al monto_otorgado de la última solicitud aprobada', function (): void {
    $distribuidora = crearDistribuidoraConLimiteInflado(limiteInflado: 100000, montoOtorgadoReal: 75000);

    $this->artisan('distribuidoras:corregir-limite-credito', ['--apply' => true])
        ->expectsOutputToContain('1 distribuidora(s) corregida(s).')
        ->assertExitCode(0);

    expect((float) $distribuidora->fresh()->limite_credito)->toBe(75000.0);
});

it('no toca una distribuidora cuyo limite_credito ya coincide con su última solicitud aprobada', function (): void {
    $distribuidora = crearDistribuidoraConLimiteInflado(limiteInflado: 75000, montoOtorgadoReal: 75000);

    $this->artisan('distribuidoras:corregir-limite-credito', ['--apply' => true])
        ->expectsOutputToContain('No se encontró ninguna distribuidora')
        ->assertExitCode(0);

    expect((float) $distribuidora->fresh()->limite_credito)->toBe(75000.0);
});

it('con varias solicitudes aprobadas, usa la más reciente por fecha_decision -- no la de mayor id', function (): void {
    $distribuidora = crearDistribuidoraConLimiteInflado(limiteInflado: 999999, montoOtorgadoReal: 50000, fechaDecision: now()->subDays(5)->toDateTimeString());

    // Segunda solicitud, aprobada DESPUÉS -- esta es la que debe ganar (100000), no la de arriba.
    SolicitudAumentoCredito::create([
        'distribuidora_id' => $distribuidora->id,
        'solicitado_por' => $distribuidora->usuario_id,
        'limite_credito_anterior' => 50000,
        'monto_solicitado' => 100000,
        'monto_otorgado' => 100000,
        'motivo' => 'Segunda solicitud',
        'estado' => 'aprobada',
        'decidido_por' => $distribuidora->usuario_id,
        'fecha_decision' => now(),
    ]);

    $this->artisan('distribuidoras:corregir-limite-credito', ['--apply' => true]);

    expect((float) $distribuidora->fresh()->limite_credito)->toBe(100000.0);
});

it('no toca distribuidoras sin ninguna solicitud aprobada', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Suc', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $usuario = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $distribuidora = Distribuidora::create([
        'usuario_id' => $usuario->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    $this->artisan('distribuidoras:corregir-limite-credito', ['--apply' => true])
        ->expectsOutputToContain('No se encontró ninguna distribuidora');

    expect((float) $distribuidora->fresh()->limite_credito)->toBe(20000.0);
});
