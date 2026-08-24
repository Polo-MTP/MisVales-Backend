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
use App\Services\Distribuidora\SolicitudTransferenciaClienteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{sucursal: Sucursal, coordinador: User, origen: Distribuidora, usuarioOrigen: User, destino: Distribuidora, usuarioDestino: User, cliente: Cliente}
 */
function crearEscenarioTransferencia(): array
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $roleCoordinador = Role::firstOrCreate(['name' => 'Coordinador']);
    $roleDistribuidora = Role::firstOrCreate(['name' => 'Distribuidora']);

    $coordinador = User::factory()->create(['role_id' => $roleCoordinador->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $usuarioOrigen = User::factory()->create(['role_id' => $roleDistribuidora->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $origen = Distribuidora::create([
        'usuario_id' => $usuarioOrigen->id, 'numero_distribuidora' => 'DIST-O-'.uniqid(),
        'limite_credito' => 10000, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
        'coordinador_id' => $coordinador->id,
    ]);

    $usuarioDestino = User::factory()->create(['role_id' => $roleDistribuidora->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $destino = Distribuidora::create([
        'usuario_id' => $usuarioDestino->id, 'numero_distribuidora' => 'DIST-D-'.uniqid(),
        'limite_credito' => 10000, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
        'coordinador_id' => $coordinador->id,
    ]);

    $direccion = Direccion::create(['calle' => 'Calle 1', 'colonia' => 'Centro', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CURP'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    HistorialClienteDistr::create([
        'distribuidor_id' => $origen->id, 'cliente_id' => $cliente->id, 'fecha_inicio' => now(), 'fecha_fin' => null,
    ]);

    return compact('sucursal', 'coordinador', 'origen', 'usuarioOrigen', 'destino', 'usuarioDestino', 'cliente');
}

it('flujo completo por HTTP: solicitar, autorizar y aceptar mueve al cliente de distribuidora', function (): void {
    ['coordinador' => $coordinador, 'destino' => $destino, 'usuarioDestino' => $usuarioDestino, 'origen' => $origen, 'cliente' => $cliente] = crearEscenarioTransferencia();

    Sanctum::actingAs($usuarioDestino);
    $response = $this->postJson("/api/v1/distribuidora/clientes/{$cliente->id}/solicitar-transferencia", [
        'motivo' => 'El cliente ahora compra conmigo.',
    ]);
    $response->assertStatus(201)->assertJsonPath('data.estado', 'pendiente_autorizacion');
    $solicitudId = $response->json('data.id');

    Sanctum::actingAs($coordinador);
    $response = $this->putJson("/api/v1/distribuidora/clientes/transferencias/{$solicitudId}/decidir", [
        'decision' => 'autorizada',
    ]);
    $response->assertStatus(200)->assertJsonPath('data.estado', 'autorizada');

    Sanctum::actingAs($usuarioDestino);
    $response = $this->putJson("/api/v1/distribuidora/clientes/transferencias/{$solicitudId}/aceptar", [
        'decision' => 'aceptada',
    ]);
    $response->assertStatus(200)->assertJsonPath('data.estado', 'aceptada');

    expect(
        HistorialClienteDistr::query()->where('cliente_id', $cliente->id)->where('distribuidor_id', $destino->id)->whereNull('fecha_fin')->exists()
    )->toBeTrue();
    expect(
        HistorialClienteDistr::query()->where('cliente_id', $cliente->id)->where('distribuidor_id', $origen->id)->whereNull('fecha_fin')->exists()
    )->toBeFalse();
});

it('un coordinador que no coordina la distribuidora origen no puede autorizar', function (): void {
    ['cliente' => $cliente, 'usuarioDestino' => $usuarioDestino] = crearEscenarioTransferencia();
    $otroCoordinador = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Coordinador'])->id, 'is_active' => true]);

    $solicitud = app(SolicitudTransferenciaClienteService::class)->solicitar($cliente, $usuarioDestino, 'motivo');

    expect(fn () => app(SolicitudTransferenciaClienteService::class)->decidir($solicitud, 'autorizada', null, $otroCoordinador))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, 'No tienes autoridad sobre la distribuidora de origen de este cliente.');
});

it('la distribuidora destino puede declinar tras la autorización, sin mover al cliente', function (): void {
    ['coordinador' => $coordinador, 'cliente' => $cliente, 'usuarioDestino' => $usuarioDestino, 'origen' => $origen] = crearEscenarioTransferencia();

    $service = app(SolicitudTransferenciaClienteService::class);
    $solicitud = $service->solicitar($cliente, $usuarioDestino, 'motivo');
    $solicitud = $service->decidir($solicitud, 'autorizada', null, $coordinador);
    $solicitud = $service->decidirAceptacion($solicitud, 'rechazada', $usuarioDestino);

    expect($solicitud->estado)->toBe('rechazada');
    expect(
        HistorialClienteDistr::query()->where('cliente_id', $cliente->id)->where('distribuidor_id', $origen->id)->whereNull('fecha_fin')->exists()
    )->toBeTrue();
});

it('no se puede solicitar transferencia de un cliente que ya es tuyo', function (): void {
    ['usuarioOrigen' => $usuarioOrigen, 'cliente' => $cliente] = crearEscenarioTransferencia();

    expect(fn () => app(SolicitudTransferenciaClienteService::class)->solicitar($cliente, $usuarioOrigen, 'motivo'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, 'Este cliente ya pertenece a tu distribuidora.');
});

it('el listado por HTTP de clientes/transferencias no lo tapa el wildcard clientes/{id}', function (): void {
    ['coordinador' => $coordinador, 'cliente' => $cliente, 'usuarioDestino' => $usuarioDestino] = crearEscenarioTransferencia();

    app(SolicitudTransferenciaClienteService::class)->solicitar($cliente, $usuarioDestino, 'motivo');

    Sanctum::actingAs($coordinador);

    $this->getJson('/api/v1/distribuidora/clientes/transferencias')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.estado', 'pendiente_autorizacion');
});

/**
 * La distribuidora ORIGEN solo veía la solicitud si ella la había pedido: al dueño actual del
 * cliente la transferencia le era invisible de principio a fin y el cliente simplemente
 * desaparecía de su cartera, sin listado ni notificación que lo explicara.
 */
it('la distribuidora origen ve la transferencia de su propio cliente y queda marcada como no-destino', function (): void {
    $e = crearEscenarioTransferencia();

    Sanctum::actingAs($e['usuarioDestino']);
    $this->postJson("/api/v1/distribuidora/clientes/{$e['cliente']->id}/solicitar-transferencia", [
        'motivo' => 'El cliente ahora compra conmigo.',
    ])->assertStatus(201);

    Sanctum::actingAs($e['usuarioOrigen']);
    $this->getJson('/api/v1/distribuidora/clientes/transferencias')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.soy_destino', false);

    Sanctum::actingAs($e['usuarioDestino']);
    $this->getJson('/api/v1/distribuidora/clientes/transferencias')
        ->assertJsonPath('data.data.0.soy_destino', true);
});

it('avisa por notificación a la distribuidora origen y a quien debe autorizar', function (): void {
    $e = crearEscenarioTransferencia();

    Sanctum::actingAs($e['usuarioDestino']);
    $this->postJson("/api/v1/distribuidora/clientes/{$e['cliente']->id}/solicitar-transferencia", [
        'motivo' => 'El cliente ahora compra conmigo.',
    ])->assertStatus(201);

    $this->assertDatabaseHas('notificaciones', [
        'destinatario_id' => $e['usuarioOrigen']->id,
        'accion' => 'transferencia_cliente_solicitada',
    ]);
    $this->assertDatabaseHas('notificaciones', [
        'destinatario_id' => $e['coordinador']->id,
        'accion' => 'transferencia_cliente_por_autorizar',
    ]);
});
