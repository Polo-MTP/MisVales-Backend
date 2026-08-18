<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Distribuidora;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Distribuidora\StoreClienteRequest;
use App\Http\Requests\Api\V1\Distribuidora\UpdateClienteRequest;
use App\Http\Resources\Distribuidora\ClienteResource;
use App\Http\Resources\Distribuidora\DistribuidoraResource;
use App\Models\Cliente;
use App\Models\User;
use App\Services\Distribuidora\ClienteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClienteController extends ApiController
{
    public function __construct(
        private readonly ClienteService $clienteService
    ) {}

    /**
     * Lista los clientes visibles para el usuario según su rol/distribuidora.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $clientes = $this->clienteService->listarClientes($usuario, $request->all());

        return $this->success(
            data: ClienteResource::collection($clientes)->response()->getData(true),
            message: 'Lista de clientes obtenida exitosamente.'
        );
    }

    /**
     * Registra un nuevo cliente para la distribuidora del usuario autenticado.
     */
    public function store(StoreClienteRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $cliente = $this->clienteService->crearCliente($request->validated(), $usuario);

        return $this->created(
            data: new ClienteResource($cliente),
            message: 'Cliente registrado exitosamente.'
        );
    }

    /**
     * Muestra el detalle de un cliente, validando que sea visible para el usuario.
     */
    public function show(int $id, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $cliente = $this->clienteService->obtenerCliente($id, $usuario);

        return $this->success(
            data: new ClienteResource($cliente),
            message: 'Detalles del cliente obtenidos exitosamente.'
        );
    }

    /**
     * Actualiza los datos de un cliente existente.
     */
    public function update(int $id, UpdateClienteRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $clienteModel = Cliente::query()->findOrFail($id);

        $cliente = $this->clienteService->actualizarCliente($clienteModel, $request->validated(), $usuario);

        return $this->success(
            data: new ClienteResource($cliente),
            message: 'Datos del cliente actualizados exitosamente.'
        );
    }

    /**
     * Activa o desactiva un cliente.
     */
    public function cambiarEstado(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'estado' => ['required', 'boolean'],
        ]);

        /** @var User $usuario */
        $usuario = $request->user();

        $clienteModel = Cliente::query()->findOrFail($id);

        $cliente = $this->clienteService->cambiarEstadoCliente($clienteModel, (bool) $request->input('estado'), $usuario);

        return $this->success(
            data: new ClienteResource($cliente),
            message: 'Estado del cliente actualizado exitosamente.'
        );
    }

    /**
     * Devuelve (creando si hace falta) el perfil de distribuidora del usuario autenticado.
     */
    public function miPerfil(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $distribuidora = $this->clienteService->obtenerOAsegurarDistribuidora($usuario);

        return $this->success(
            data: new DistribuidoraResource($distribuidora),
            message: 'Perfil de distribuidora obtenido exitosamente.'
        );
    }
}
