<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Relacion;

use App\Http\Controllers\Api\ApiController;
use App\Models\Distribuidora;
use App\Models\User;
use App\Services\Relacion\EstadoCuentaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EstadoCuentaController extends ApiController
{
    public function __construct(
        private readonly EstadoCuentaService $estadoCuentaService,
    ) {}

    /**
     * GET /api/v1/distribuidoras/{distribuidora}/estado-cuenta
     *
     * Estado de cuenta acumulado, agrupado por cliente, con el total general en
     * 'total_pendiente'. Una Distribuidora solo puede consultar el suyo.
     */
    public function index(Distribuidora $distribuidora, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        if ($usuario->role?->name === 'Distribuidora' && $usuario->distribuidora?->id !== $distribuidora->id) {
            abort(403, 'Acceso Denegado. Este no es tu estado de cuenta.');
        }

        $resultado = $this->estadoCuentaService->obtenerPorDistribuidora($distribuidora);

        return $this->success(
            data: [
                'clientes' => $resultado['clientes']->values(),
                'total_pendiente' => $resultado['total_pendiente'],
            ],
            message: 'Estado de cuenta obtenido exitosamente.'
        );
    }
}
