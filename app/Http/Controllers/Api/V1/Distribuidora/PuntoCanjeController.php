<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Distribuidora;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Distribuidora\CanjearPuntosRequest;
use App\Http\Resources\Distribuidora\PuntoMovimientoResource;
use App\Models\Distribuidora;
use App\Models\User;
use App\Services\Distribuidora\PuntoCanjeService;
use DomainException;
use Illuminate\Http\JsonResponse;

final class PuntoCanjeController extends ApiController
{
    public function __construct(
        private readonly PuntoCanjeService $puntoCanjeService,
    ) {}

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
            return $this->error($e->getMessage());
        }
    }
}
