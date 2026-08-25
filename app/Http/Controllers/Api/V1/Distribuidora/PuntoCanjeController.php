<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Distribuidora;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Distribuidora\CanjearPuntosRequest;
use App\Http\Resources\Distribuidora\PuntoMovimientoResource;
use App\Models\Distribuidora;
use App\Models\User;
use App\Services\Distribuidora\PuntoCanjeService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PuntoCanjeController extends ApiController
{
    public function __construct(
        private readonly PuntoCanjeService $puntoCanjeService,
    ) {}

    /**
     * Lista el historial de movimientos de puntos de la distribuidora.
     */
    public function index(Request $request, Distribuidora $distribuidora): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $movimientos = $this->puntoCanjeService->listar($distribuidora, $usuario, $request->all());

        return $this->success(
            data: PuntoMovimientoResource::collection($movimientos)->response()->getData(true),
            message: 'Historial de movimientos de puntos obtenido exitosamente.'
        );
    }

    /**
     * Canjea puntos de fidelidad de la distribuidora, registrando el movimiento correspondiente.
     */
    public function canjear(CanjearPuntosRequest $request, Distribuidora $distribuidora): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        try {
            $movimiento = $this->puntoCanjeService->canjear(
                $distribuidora,
                $request->integer('cantidad'),
                (string) $request->string('motivo'),
                $usuario
            );

            return $this->success(
                data: new PuntoMovimientoResource($movimiento),
                message: 'Puntos canjeados exitosamente.'
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422, [], ApiErrorCode::DOMAIN_ERROR);
        }
    }
}
