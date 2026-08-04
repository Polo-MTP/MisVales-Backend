<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Distribuidora;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Distribuidora\DistribuidoraStoreRequest;
use App\Http\Requests\Api\V1\Distribuidora\DistribuidoraUpdateRequest;
use App\Http\Requests\Api\V1\Distribuidora\DistribuidoraEstadoRequest;
use App\Http\Requests\Api\V1\Distribuidora\DistribuidoraCreditoRequest;
use App\Http\Resources\Distribuidora\DistribuidoraResource;
use App\Models\Distribuidora;
use App\Services\Distribuidora\DistribuidoraService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
final class DistribuidoraController extends Controller
{
    use AuthorizesRequests;
    public function __construct(
        private readonly DistribuidoraService $distribuidoraService
    ) {
    }

    /**
     * GET /api/v1/distribuidoras
     */
    public function index(): JsonResponse
    {
        $distribuidoras = $this->distribuidoraService->listarPorRol();
        return response()->json(DistribuidoraResource::collection($distribuidoras));
    }

    /**
     * POST /api/v1/distribuidoras
     */
    public function store(DistribuidoraStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $distribuidora = $this->distribuidoraService->crear($data, $request->user());
        return response()->json(new DistribuidoraResource($distribuidora), 201);
    }

    /**
     * GET /api/v1/distribuidoras/{distribuidora}
     */
    public function show(Distribuidora $distribuidora): JsonResponse
    {
        $distribuidora->load(['datosPersonales', 'categoria', 'sucursal', 'coordinador', 'verificador']);
        return response()->json(new DistribuidoraResource($distribuidora));
    }

    /**
     * PUT /api/v1/distribuidoras/{distribuidora}
     */
    public function update(DistribuidoraUpdateRequest $request, Distribuidora $distribuidora): JsonResponse
    {
        $data = $request->validated();
        if (isset($data['datos_personales'])) {
            $distribuidora->datosPersonales()->update($data['datos_personales']);
            unset($data['datos_personales']);
        }
        $distribuidora = $this->distribuidoraService->actualizar($distribuidora, $data);
        return response()->json(new DistribuidoraResource($distribuidora));
    }

    /**
     * DELETE /api/v1/distribuidoras/{distribuidora}
     * (Desactiva lógicamente, no elimina físicamente)
     */
    public function destroy(Distribuidora $distribuidora): JsonResponse
    {
        $distribuidora->update(['estado' => 'INACTIVO']);
        return response()->json(['message' => 'Distribuidora desactivada']);
    }

    /**
     * PUT /api/v1/distribuidoras/{distribuidora}/estado
     */
    public function cambiarEstado(DistribuidoraEstadoRequest $request, Distribuidora $distribuidora): JsonResponse
    {
        $this->authorize('cambiarEstado', $distribuidora);

        $distribuidora = $this->distribuidoraService->cambiarEstado(
            $distribuidora,
            $request->input('estado'),
            $request->input('motivo', 'Cambio de estado'),
            $request->user()
        );

        return response()->json([
            'message' => 'Estado actualizado correctamente',
            'data' => new DistribuidoraResource($distribuidora),
        ]);
    }

    /**
     * PUT /api/v1/distribuidoras/{distribuidora}/credito
     */
    public function asignarCredito(DistribuidoraCreditoRequest $request, Distribuidora $distribuidora): JsonResponse
    {
        $this->authorize('asignarCredito', $distribuidora);

        $distribuidora = $this->distribuidoraService->asignarCredito(
            $distribuidora,
            $request->input('limite_credito'),
            $request->input('categoria_id')
        );

        return response()->json([
            'message' => 'Crédito asignado correctamente',
            'data' => new DistribuidoraResource($distribuidora),
        ]);
    }

    /**
     * GET /api/v1/distribuidoras/{distribuidora}/saldo-disponible
     */
    public function saldoDisponible(Distribuidora $distribuidora): JsonResponse
    {
        return response()->json($this->distribuidoraService->obtenerSaldoDisponible($distribuidora));
    }
}