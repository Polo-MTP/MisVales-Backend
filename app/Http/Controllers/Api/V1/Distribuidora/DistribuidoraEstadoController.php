<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Distribuidora;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Distribuidora\HistorialEstadoDistribuidoraResource;
use App\Models\Distribuidora;
use App\Services\Distribuidora\DistribuidoraEstadoService;
use Illuminate\Http\JsonResponse;

final class DistribuidoraEstadoController extends ApiController
{
    public function __construct(
        private readonly DistribuidoraEstadoService $estadoService
    ) {}

    /**
     * Consulta el historial de cambios de estado para una distribuidora específica.
     */
    public function historial(int $id): JsonResponse
    {
        /** @var Distribuidora $distribuidora */
        $distribuidora = Distribuidora::query()->findOrFail($id);

        $historial = $this->estadoService->obtenerHistorialEstados($distribuidora);

        return $this->success(
            data: HistorialEstadoDistribuidoraResource::collection($historial),
            message: 'Historial de estado de distribuidora obtenido exitosamente.'
        );
    }
}
