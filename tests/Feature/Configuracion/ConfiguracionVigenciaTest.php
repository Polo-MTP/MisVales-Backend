<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Configuracion\ConfiguracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * vigente_desde se guarda con hora ("2026-08-24 00:00:00") pero se compara contra fechas de
 * solo día ("2026-08-24"). Comparando esos strings tal cual (where() en vez de whereDate()),
 * "2026-08-24 00:00:00" <= "2026-08-24" da FALSE -- un cambio marcado "vigente desde hoy"
 * nunca se activaba el mismo día en que se guardaba, solo hasta el día siguiente. Encontrado
 * al intentar generar un corte el mismo día en que se cambió el día de corte de una sucursal.
 */
function crearGerenteGeneralParaVigencia(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente General']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

it('un valor de configuración marcado vigente desde hoy ya aplica hoy mismo, no hasta mañana', function (): void {
    $gerente = crearGerenteGeneralParaVigencia();
    $servicio = app(ConfiguracionService::class);

    $servicio->cambiarValor('comision_base_pct', '12', 'decimal', $gerente);

    expect((float) $servicio->obtenerValorVigente('comision_base_pct'))->toBe(12.0);
});

it('una regla de fechas de corte marcada vigente desde hoy ya aplica hoy mismo para esa sucursal', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Sucursal', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $gerente = crearGerenteGeneralParaVigencia();
    $servicio = app(ConfiguracionService::class);

    $servicio->cambiarFechas($sucursal->id, 24, 26, 2, $gerente);

    expect($servicio->obtenerFechasVigentes($sucursal->id)->dia_corte)->toBe(24);
});
