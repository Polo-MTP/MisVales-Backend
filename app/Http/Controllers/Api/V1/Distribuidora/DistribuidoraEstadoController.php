<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Distribuidora;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Distribuidora\CambiarEstadoDistribuidoraRequest;
use App\Http\Resources\Distribuidora\DistribuidoraResource;
use App\Http\Resources\Distribuidora\HistorialEstadoDistribuidoraResource;
use App\Models\Distribuidora;
use App\Models\User;
use App\Services\Distribuidora\DistribuidoraEstadoService;
use Illuminate\Http\JsonResponse;

final class DistribuidoraEstadoController extends ApiController
{
    public function __construct(
        private readonly DistribuidoraEstadoService $estadoService
    ) {}

    /**
     * Cambia el estado de una distribuidora guardando justificación y registrando la auditoría.
     */
    public function cambiarEstado(int $id, CambiarEstadoDistribuidoraRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        /** @var Distribuidora $distribuidora */
        $distribuidora = Distribuidora::query()->findOrFail($id);

        $nuevoEstado = (bool) $request->input('estado');
        $motivo = (string) $request->input('motivo');

        $distribuidoraActualizada = $this->estadoService->cambiarEstado(
            $distribuidora,
            $nuevoEstado,
            $motivo,
            $usuario
        );

        return $this->success(
            data: new DistribuidoraResource($distribuidoraActualizada),
            message: 'Estado de distribuidora actualizado exitosamente.'
        );
    }

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
