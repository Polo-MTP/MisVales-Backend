<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Distribuidora;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Distribuidora\DecidirSolicitudEdicionClienteRequest;
use App\Http\Requests\Api\V1\Distribuidora\SolicitarEdicionClienteRequest;
use App\Http\Resources\Distribuidora\ClienteResource;
use App\Http\Resources\Distribuidora\SolicitudEdicionClienteResource;
use App\Models\Cliente;
use App\Models\SolicitudEdicionCliente;
use App\Models\User;
use App\Services\Distribuidora\SolicitudEdicionClienteService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SolicitudEdicionClienteController extends ApiController
{
    public function __construct(
        private readonly SolicitudEdicionClienteService $solicitudEdicionClienteService,
    ) {}

    /**
     * Lista las solicitudes de edición de cliente visibles para el usuario.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $solicitudes = $this->solicitudEdicionClienteService->listar($usuario, $request->all());

        return $this->success(
            data: SolicitudEdicionClienteResource::collection($solicitudes)->response()->getData(true),
            message: 'Lista de solicitudes de edición de cliente obtenida exitosamente.'
        );
    }

    /**
     * Propone una edición de datos de cliente; queda pendiente de autorización.
     */
    public function store(SolicitarEdicionClienteRequest $request, int $id): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $cliente = Cliente::query()->findOrFail($id);

        $solicitud = $this->solicitudEdicionClienteService->solicitar(
            $cliente,
            (array) $request->input('datos_personales', []),
            (array) $request->input('direccion', []),
            (string) $request->string('motivo'),
            $usuario
        );

        return $this->created(
            data: new SolicitudEdicionClienteResource($solicitud),
            message: 'Solicitud de edición enviada. Queda pendiente de autorización.'
        );
    }

    /**
     * Autoriza o rechaza una solicitud de edición de cliente pendiente.
     */
    public function decidir(DecidirSolicitudEdicionClienteRequest $request, SolicitudEdicionCliente $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        try {
            $solicitud = $this->solicitudEdicionClienteService->decidir(
                $solicitud,
                (string) $request->string('decision'),
                $request->filled('comentario') ? (string) $request->string('comentario') : null,
                $usuario
            );

            return $this->success(
                data: new SolicitudEdicionClienteResource($solicitud),
                message: 'Decisión registrada exitosamente.'
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Aplica al cliente los cambios de una solicitud de edición ya autorizada.
     */
    public function aplicar(Request $request, int $id): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $request->validate(['solicitud_id' => ['required', 'integer', 'exists:solicitudes_edicion_cliente,id']]);

        $solicitud = SolicitudEdicionCliente::query()
            ->where('cliente_id', $id)
            ->findOrFail($request->integer('solicitud_id'));

        try {
            $cliente = $this->solicitudEdicionClienteService->aplicar($solicitud, $usuario);

            return $this->success(
                data: new ClienteResource($cliente),
                message: 'Datos del cliente actualizados exitosamente.'
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }
    }
}
