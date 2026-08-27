<?php

declare(strict_types=1);

use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Distribuidora\SolicitudEdicionClienteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearClienteParaEdicion(): Cliente
{
    $direccion = Direccion::create(['calle' => 'Calle Vieja', 'colonia' => 'Centro', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Juan', 'apellido_paterno' => 'Perez', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);

    return Cliente::create(['datos_id' => $datos->id, 'estado' => true]);
}

function crearUsuariosSucursalEdicion(): array
{
    $sucursal = Sucursal::create(['nombre' => 'Sucursal 1', 'codigo' => 'SUC-EDIT', 'es_matriz' => false, 'is_active' => true]);
    $roleCajera = Role::create(['name' => 'Cajera']);
    $roleGerenteSucursal = Role::create(['name' => 'Gerente de Sucursal']);

    $cajera = User::factory()->create(['role_id' => $roleCajera->id, 'sucursal_id' => $sucursal->id]);
    $gerenteSucursal = User::factory()->create(['role_id' => $roleGerenteSucursal->id, 'sucursal_id' => $sucursal->id]);

    return compact('sucursal', 'cajera', 'gerenteSucursal');
}

it('la cajera no puede editar los datos del cliente sin autorización previa', function (): void {
    ['cajera' => $cajera] = crearUsuariosSucursalEdicion();
    $cliente = crearClienteParaEdicion();

    $solicitud = app(SolicitudEdicionClienteService::class)->solicitar(
        $cliente,
        ['nombre' => 'Juan Corregido'],
        [],
        'CURP con typo',
        $cajera
    );

    app(SolicitudEdicionClienteService::class)->aplicar($solicitud, $cajera);
})->throws(DomainException::class);

it('flujo completo: solicitar, aprobar y aplicar edita exactamente los campos autorizados', function (): void {
    ['cajera' => $cajera, 'gerenteSucursal' => $gerenteSucursal] = crearUsuariosSucursalEdicion();
    $cliente = crearClienteParaEdicion();

    $solicitud = app(SolicitudEdicionClienteService::class)->solicitar(
        $cliente,
        ['nombre' => 'Juan Corregido'],
        ['calle' => 'Calle Nueva'],
        'Nombre y calle mal capturados',
        $cajera
    );

    $solicitud = app(SolicitudEdicionClienteService::class)->decidir($solicitud, 'aprobada', 'Verificado con el cliente', $gerenteSucursal);
    expect($solicitud->estado)->toBe('aprobada');

    $clienteActualizado = app(SolicitudEdicionClienteService::class)->aplicar($solicitud, $cajera);

    expect($clienteActualizado->datosPersonales->nombre)->toBe('Juan Corregido')
        ->and($clienteActualizado->datosPersonales->apellido_paterno)->toBe('Perez')
        ->and($clienteActualizado->datosPersonales->direccion->calle)->toBe('Calle Nueva')
        ->and($solicitud->fresh()->estado)->toBe('aplicada');
});

/**
 * Antes, aplicar() sobreescribía con el valor propuesto (capturado al SOLICITAR) sin fijarse
 * si el registro real había cambiado desde entonces -- una edición directa del cliente (ej. la
 * propia Distribuidora, vía PUT clientes/{id}) mientras la solicitud de la cajera seguía
 * pendiente/aprobada se revertía en silencio al aplicar la autorización, con el valor viejo.
 */
it('REGRESION: no revierte en silencio una edición directa hecha mientras la solicitud seguía pendiente', function (): void {
    ['cajera' => $cajera, 'gerenteSucursal' => $gerenteSucursal] = crearUsuariosSucursalEdicion();
    $cliente = crearClienteParaEdicion();

    $solicitud = app(SolicitudEdicionClienteService::class)->solicitar(
        $cliente,
        ['nombre' => 'Juan Corregido (propuesto por cajera)'],
        [],
        'CURP con typo',
        $cajera
    );

    $solicitud = app(SolicitudEdicionClienteService::class)->decidir($solicitud, 'aprobada', 'Ok', $gerenteSucursal);

    // updated_at solo guarda hasta el segundo -- sin cruzar un segundo real de reloj, la
    // edición directa de abajo podría quedar en el mismo segundo que el snapshot tomado al
    // solicitar, y la comparación no vería ninguna diferencia (mismo problema ya conocido en
    // otras partes de este código con created_at/fecha_decision).
    sleep(1);

    // Mientras tanto, alguien más (ej. la propia Distribuidora) edita el cliente directamente,
    // por fuera de este flujo de autorización -- con un nombre distinto al propuesto.
    $cliente->datosPersonales->update(['nombre' => 'Juan Editado Directo']);

    expect(fn () => app(SolicitudEdicionClienteService::class)->aplicar($solicitud, $cajera))
        ->toThrow(DomainException::class);

    // El nombre editado directamente sigue ahí -- no se revirtió al valor propuesto viejo.
    expect($cliente->fresh()->datosPersonales->nombre)->toBe('Juan Editado Directo')
        ->and($solicitud->fresh()->estado)->toBe('aprobada');
});

it('una solicitud rechazada no puede aplicarse', function (): void {
    ['cajera' => $cajera, 'gerenteSucursal' => $gerenteSucursal] = crearUsuariosSucursalEdicion();
    $cliente = crearClienteParaEdicion();

    $solicitud = app(SolicitudEdicionClienteService::class)->solicitar($cliente, ['nombre' => 'Otro Nombre'], [], 'motivo', $cajera);
    $solicitud = app(SolicitudEdicionClienteService::class)->decidir($solicitud, 'rechazada', 'No procede', $gerenteSucursal);

    app(SolicitudEdicionClienteService::class)->aplicar($solicitud, $cajera);
})->throws(DomainException::class);

it('el gerente de sucursal puede listar por HTTP las solicitudes de edición pendientes, y la ruta no la tapa clientes/{id}', function (): void {
    ['cajera' => $cajera, 'gerenteSucursal' => $gerenteSucursal] = crearUsuariosSucursalEdicion();
    $cliente = crearClienteParaEdicion();

    app(SolicitudEdicionClienteService::class)->solicitar($cliente, ['nombre' => 'Juan Corregido'], [], 'CURP con typo', $cajera);

    Sanctum::actingAs($gerenteSucursal->fresh());

    $response = $this->getJson('/api/v1/distribuidora/clientes/ediciones');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.estado', 'pendiente')
        ->assertJsonPath('data.data.0.cliente_id', $cliente->id);
});

/**
 * Antes, la solicitud solo traía 'campos_propuestos' (el "después") -- quien autoriza no tenía
 * forma de ver el valor ACTUAL del cliente sin salirse a buscarlo a mano en su perfil. Ahora
 * el recurso también trae 'antes' con esos mismos campos, leído en vivo del cliente.
 */
it('la solicitud trae el valor actual del cliente ("antes") junto a lo propuesto, para poder compararlos', function (): void {
    ['cajera' => $cajera, 'gerenteSucursal' => $gerenteSucursal] = crearUsuariosSucursalEdicion();
    $cliente = crearClienteParaEdicion();

    app(SolicitudEdicionClienteService::class)->solicitar(
        $cliente,
        ['nombre' => 'Juan Corregido'],
        ['calle' => 'Calle Nueva'],
        'Nombre y calle mal capturados',
        $cajera
    );

    Sanctum::actingAs($gerenteSucursal->fresh());

    $this->getJson('/api/v1/distribuidora/clientes/ediciones')
        ->assertStatus(200)
        ->assertJsonPath('data.data.0.campos_propuestos.datos_personales.nombre', 'Juan Corregido')
        ->assertJsonPath('data.data.0.antes.datos_personales.nombre', 'Juan')
        ->assertJsonPath('data.data.0.campos_propuestos.direccion.calle', 'Calle Nueva')
        ->assertJsonPath('data.data.0.antes.direccion.calle', 'Calle Vieja')
        // El snapshot interno (updated_at para detectar ediciones concurrentes) es un detalle
        // de implementación de aplicar() -- no debe salir en la respuesta que ve el gerente.
        ->assertJsonMissingPath('data.data.0.campos_propuestos._snapshot');
});

it('la cajera solo ve por HTTP sus propias solicitudes de edición', function (): void {
    ['cajera' => $cajera] = crearUsuariosSucursalEdicion();
    $otraCajera = User::factory()->create(['role_id' => $cajera->role_id, 'sucursal_id' => $cajera->sucursal_id, 'is_active' => true]);
    $cliente = crearClienteParaEdicion();

    app(SolicitudEdicionClienteService::class)->solicitar($cliente, ['nombre' => 'Juan Corregido'], [], 'motivo', $cajera);

    Sanctum::actingAs($otraCajera);

    $this->getJson('/api/v1/distribuidora/clientes/ediciones')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.data');
});
