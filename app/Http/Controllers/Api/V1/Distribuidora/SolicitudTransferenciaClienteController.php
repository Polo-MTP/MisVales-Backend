<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Distribuidora;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Distribuidora\AceptarTransferenciaClienteRequest;
use App\Http\Requests\Api\V1\Distribuidora\DecidirTransferenciaClienteRequest;
use App\Http\Requests\Api\V1\Distribuidora\SolicitarTransferenciaClienteRequest;
use App\Http\Resources\Distribuidora\SolicitudTransferenciaClienteResource;
use App\Models\Cliente;
use App\Models\SolicitudTransferenciaCliente;
use App\Models\User;
use App\Services\Distribuidora\SolicitudTransferenciaClienteService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SolicitudTransferenciaClienteController extends ApiController
{
    public function __construct(
        private readonly SolicitudTransferenciaClienteService $service
    ) {}

    /**
     * Lista solicitudes de transferencia visibles para el usuario según su rol.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $solicitudes = $this->service->listar($usuario, $request->all());

        return $this->success(
            data: SolicitudTransferenciaClienteResource::collection($solicitudes)->response()->getData(true),
            message: 'Lista de solicitudes de transferencia obtenida exitosamente.'
        );
    }

    /**
     * Una distribuidora solicita quedarse con un cliente que hoy pertenece a otra.
     */
    public function store(SolicitarTransferenciaClienteRequest $request, Cliente $cliente): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $solicitud = $this->service->solicitar($cliente, $usuario, (string) $request->string('motivo'));

        return $this->created(
            data: new SolicitudTransferenciaClienteResource($solicitud),
            message: 'Solicitud de transferencia enviada. Queda pendiente de autorización del coordinador/gerente de la distribuidora origen.'
        );
    }

    /**
     * El coordinador/gerente de la distribuidora origen autoriza o rechaza la solicitud.
     */
    public function decidir(DecidirTransferenciaClienteRequest $request, SolicitudTransferenciaCliente $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        try {
            $solicitud = $this->service->decidir(
                $solicitud,
                (string) $request->string('decision'),
                $request->filled('comentario') ? (string) $request->string('comentario') : null,
                $usuario
            );

            return $this->success(
                data: new SolicitudTransferenciaClienteResource($solicitud),
                message: 'Decisión registrada exitosamente.'
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * La distribuidora destino confirma (o declina) la transferencia ya autorizada. Al
     * confirmar, se ejecuta el movimiento real del cliente.
     */
    public function aceptar(AceptarTransferenciaClienteRequest $request, SolicitudTransferenciaCliente $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        try {
            $solicitud = $this->service->decidirAceptacion($solicitud, (string) $request->string('decision'), $usuario);

            return $this->success(
                data: new SolicitudTransferenciaClienteResource($solicitud),
                message: $solicitud->estado === 'aceptada' ? 'Transferencia completada exitosamente.' : 'Transferencia declinada.'
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }
    }
}
