<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Distribuidora;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Distribuidora\DecidirAumentoCreditoRequest;
use App\Http\Requests\Api\V1\Distribuidora\SolicitarAumentoCreditoRequest;
use App\Http\Resources\Distribuidora\SolicitudAumentoCreditoResource;
use App\Models\Distribuidora;
use App\Models\SolicitudAumentoCredito;
use App\Models\User;
use App\Services\Distribuidora\SolicitudAumentoCreditoService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SolicitudAumentoCreditoController extends ApiController
{
    public function __construct(
        private readonly SolicitudAumentoCreditoService $service
    ) {}

    /**
     * Lista solicitudes de aumento de crédito visibles para el usuario según su rol.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $solicitudes = $this->service->listar($usuario, $request->all());

        return $this->success(
            data: SolicitudAumentoCreditoResource::collection($solicitudes)->response()->getData(true),
            message: 'Lista de solicitudes de aumento de crédito obtenida exitosamente.'
        );
    }

    /**
     * La distribuidora (o su coordinador) solicita un aumento a su línea de crédito.
     */
    public function store(SolicitarAumentoCreditoRequest $request, Distribuidora $distribuidora): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $solicitud = $this->service->solicitar(
            $distribuidora,
            $usuario,
            (float) $request->input('monto_solicitado'),
            (string) $request->string('motivo')
        );

        return $this->created(
            data: new SolicitudAumentoCreditoResource($solicitud),
            message: 'Solicitud de aumento de crédito enviada. Queda pendiente de decisión del gerente.'
        );
    }

    /**
     * El Gerente aprueba (indicando el monto otorgado, que puede ser menor al solicitado) o
     * rechaza la solicitud.
     */
    public function decidir(DecidirAumentoCreditoRequest $request, SolicitudAumentoCredito $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        try {
            $solicitud = $this->service->decidir(
                $solicitud,
                (string) $request->string('decision'),
                $request->filled('monto_otorgado') ? (float) $request->input('monto_otorgado') : null,
                $request->filled('comentario') ? (string) $request->string('comentario') : null,
                $usuario
            );

            return $this->success(
                data: new SolicitudAumentoCreditoResource($solicitud),
                message: 'Decisión registrada exitosamente.'
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }
    }
}
