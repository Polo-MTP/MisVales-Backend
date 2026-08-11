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

it('una solicitud rechazada no puede aplicarse', function (): void {
    ['cajera' => $cajera, 'gerenteSucursal' => $gerenteSucursal] = crearUsuariosSucursalEdicion();
    $cliente = crearClienteParaEdicion();

    $solicitud = app(SolicitudEdicionClienteService::class)->solicitar($cliente, ['nombre' => 'Otro Nombre'], [], 'motivo', $cajera);
    $solicitud = app(SolicitudEdicionClienteService::class)->decidir($solicitud, 'rechazada', 'No procede', $gerenteSucursal);

    app(SolicitudEdicionClienteService::class)->aplicar($solicitud, $cajera);
})->throws(DomainException::class);
