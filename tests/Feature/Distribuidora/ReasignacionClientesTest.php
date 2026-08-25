<?php

declare(strict_types=1);

use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\HistorialClienteDistr;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Distribuidora\ClienteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{coordinador: User, origen: Distribuidora, destino: Distribuidora}
 */
function crearCoordinadorConDosDistribuidoras(): array
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $roleCoordinador = Role::firstOrCreate(['name' => 'Coordinador']);
    $coordinador = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $origen = Distribuidora::create([
        'usuario_id' => User::factory()->create(['is_active' => true])->id, 'numero_distribuidora' => 'DIST-O-'.uniqid(),
        'limite_credito' => 10000, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
        'coordinador_id' => $coordinador->id,
    ]);

    $destino = Distribuidora::create([
        'usuario_id' => User::factory()->create(['is_active' => true])->id, 'numero_distribuidora' => 'DIST-D-'.uniqid(),
        'limite_credito' => 10000, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
        'coordinador_id' => $coordinador->id,
    ]);

    return compact('coordinador', 'origen', 'destino');
}

function crearClienteEnDistribuidora(Distribuidora $distribuidora): Cliente
{
    $direccion = Direccion::create(['calle' => 'Calle 1', 'colonia' => 'Centro', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CURP'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    HistorialClienteDistr::create([
        'distribuidor_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'fecha_inicio' => now(), 'fecha_fin' => null,
    ]);

    return $cliente;
}

it('el coordinador reasigna por HTTP todos los clientes de una distribuidora a otra suya', function (): void {
    ['coordinador' => $coordinador, 'origen' => $origen, 'destino' => $destino] = crearCoordinadorConDosDistribuidoras();
    $cliente1 = crearClienteEnDistribuidora($origen);
    $cliente2 = crearClienteEnDistribuidora($origen);

    Sanctum::actingAs($coordinador);

    $response = $this->postJson("/api/v1/distribuidoras/{$origen->id}/reasignar-clientes", [
        'distribuidora_destino_id' => $destino->id,
    ]);

    $response->assertStatus(200)->assertJsonPath('data.clientes_reasignados', 2);

    foreach ([$cliente1, $cliente2] as $cliente) {
        expect(
            HistorialClienteDistr::query()->where('cliente_id', $cliente->id)->where('distribuidor_id', $destino->id)->whereNull('fecha_fin')->exists()
        )->toBeTrue();
        expect(
            HistorialClienteDistr::query()->where('cliente_id', $cliente->id)->where('distribuidor_id', $origen->id)->whereNull('fecha_fin')->exists()
        )->toBeFalse();
    }
});

it('un coordinador no puede reasignar clientes de una distribuidora que no coordina', function (): void {
    ['origen' => $origen, 'destino' => $destino] = crearCoordinadorConDosDistribuidoras();
    ['coordinador' => $otroCoordinador] = crearCoordinadorConDosDistribuidoras();
    crearClienteEnDistribuidora($origen);

    expect(fn () => app(ClienteService::class)->reasignarTodos($origen, $destino, $otroCoordinador))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, 'Solo puedes reasignar clientes entre distribuidoras que tú coordinas.');
});

it('no se puede reasignar hacia una distribuidora que no está activa', function (): void {
    ['coordinador' => $coordinador, 'origen' => $origen, 'destino' => $destino] = crearCoordinadorConDosDistribuidoras();
    $destino->update(['estado' => 'RECHAZADO']);

    expect(fn () => app(ClienteService::class)->reasignarTodos($origen, $destino, $coordinador))
        ->toThrow(DomainException::class, 'La distribuidora destino no puede recibir clientes en su estado actual.');
});
