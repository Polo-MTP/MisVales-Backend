<?php

declare(strict_types=1);

use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\User;
use App\Services\Distribuidora\ClienteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * obtenerOAsegurarDistribuidora() creaba el registro con 'estado' => true (booleano) --
 * resto de un tiempo en que la columna 'estado' de distribuidoras SÍ era boolean, antes de la
 * migración que la cambió a string (ACTIVO/INACTIVO/MOROSO/etc., ver
 * 2026_08_04_064043_add_fields_to_distribuidoras_table.php). Al guardarse en la columna string
 * quedaba literalmente el texto "1", que no coincide con ningún in_array(['ACTIVO', ...]) del
 * resto del sistema -- la distribuidora quedaba con crédito, pero sin poder pedir vales, sin
 * aparecer en el corte del día (generarCortesDelDia() filtra where('estado', 'ACTIVO')), etc.
 */
it('crea la distribuidora con estado ACTIVO, no con el booleano heredado de la columna vieja', function (): void {
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

    $distribuidora = app(ClienteService::class)->obtenerOAsegurarDistribuidora($user);

    expect($distribuidora->fresh()->estado)->toBe('ACTIVO');
});

it('GET /distribuidora/perfil crea la distribuidora la primera vez con estado ACTIVO', function (): void {
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/distribuidora/perfil')->assertStatus(200);

    expect(Distribuidora::where('usuario_id', $user->id)->first()?->estado)->toBe('ACTIVO');
});
