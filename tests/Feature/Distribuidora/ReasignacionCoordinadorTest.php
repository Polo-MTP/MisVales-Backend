<?php

declare(strict_types=1);

use App\Models\Distribuidora;
use App\Models\HistorialCoordinador;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Distribuidora\DistribuidoraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{gerente: User, origen: User, destino: User, distribuidoras: array<int, Distribuidora>}
 */
function crearGerenteConDosCoordinadores(): array
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $roleGerente = Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    $roleCoordinador = Role::firstOrCreate(['name' => 'Coordinador']);

    $gerente = User::factory()->create(['role_id' => $roleGerente->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $origen = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $destino = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $distribuidoras = [];
    for ($i = 0; $i < 2; $i++) {
        $usuarioDist = User::factory()->create(['is_active' => true]);
        $distribuidoras[] = Distribuidora::create([
            'usuario_id' => $usuarioDist->id, 'numero_distribuidora' => 'DIST-'.uniqid(),
            'limite_credito' => 5000, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO',
            'sucursal_id' => $sucursal->id, 'coordinador_id' => $origen->id,
        ]);
        HistorialCoordinador::create([
            'distribuidor_id' => $usuarioDist->id, 'coordinador_id' => $origen->id,
            'fecha_inicio' => now()->subDays(10), 'fecha_fin' => null,
        ]);
    }

    return compact('gerente', 'origen', 'destino', 'distribuidoras');
}

it('el gerente reasigna por HTTP toda la cartera de un coordinador a otro de su sucursal', function (): void {
    ['gerente' => $gerente, 'origen' => $origen, 'destino' => $destino, 'distribuidoras' => $distribuidoras] = crearGerenteConDosCoordinadores();

    Sanctum::actingAs($gerente);

    $response = $this->postJson('/api/v1/distribuidoras/reasignar-coordinador', [
        'coordinador_origen_id' => $origen->id,
        'coordinador_destino_id' => $destino->id,
    ]);

    $response->assertStatus(200)->assertJsonPath('data.distribuidoras_reasignadas', 2);

    foreach ($distribuidoras as $distribuidora) {
        expect($distribuidora->fresh()->coordinador_id)->toBe($destino->id);

        expect(
            HistorialCoordinador::query()->where('distribuidor_id', $distribuidora->usuario_id)->where('coordinador_id', $destino->id)->whereNull('fecha_fin')->exists()
        )->toBeTrue();
        expect(
            HistorialCoordinador::query()->where('distribuidor_id', $distribuidora->usuario_id)->where('coordinador_id', $origen->id)->whereNull('fecha_fin')->exists()
        )->toBeFalse();
    }
});

it('un gerente de sucursal no puede reasignar coordinadores de otra sucursal', function (): void {
    ['gerente' => $gerente] = crearGerenteConDosCoordinadores();

    $otraSucursal = Sucursal::create(['nombre' => 'Otra', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $roleCoordinador = Role::firstOrCreate(['name' => 'Coordinador']);
    $origenAjeno = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $otraSucursal->id, 'is_active' => true]);
    $destinoAjeno = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $otraSucursal->id, 'is_active' => true]);

    expect(fn () => app(DistribuidoraService::class)->reasignarCoordinador($origenAjeno, $destinoAjeno, $gerente))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, 'Solo puedes reasignar coordinadores de tu propia sucursal.');
});
