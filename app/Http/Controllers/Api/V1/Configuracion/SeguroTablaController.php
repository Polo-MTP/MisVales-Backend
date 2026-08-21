<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Configuracion;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Configuracion\SeguroTablaStoreRequest;
use App\Http\Requests\Api\V1\Configuracion\SeguroTablaUpdateRequest;
use App\Http\Resources\Configuracion\SeguroTablaResource;
use App\Models\SeguroTabla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestiona la tabla de rangos de seguro por monto de vale ("varía según la cantidad",
 * ver "Analisis de calculo de relacion"). RelacionCalculoService lee de aquí en vivo,
 * así que un cambio aplica de inmediato a los cortes que se generen después.
 */
final class SeguroTablaController extends ApiController
{
    /**
     * Lista los rangos de seguro. Por defecto solo los activos; ?activos=false también
     * incluye los desactivados (para poder reactivarlos).
     */
    public function index(Request $request): JsonResponse
    {
        $query = SeguroTabla::query()->orderBy('monto_desde');

        if ($request->boolean('activos', true)) {
            $query->activo();
        }

        return $this->success(SeguroTablaResource::collection($query->get()));
    }

    /**
     * Crea un nuevo rango de seguro.
     */
    public function store(SeguroTablaStoreRequest $request): JsonResponse
    {
        $seguro = SeguroTabla::query()->create($request->validated());

        return $this->created(new SeguroTablaResource($seguro));
    }

    /**
     * Actualiza un rango de seguro existente.
     */
    public function update(SeguroTablaUpdateRequest $request, SeguroTabla $seguro): JsonResponse
    {
        $seguro->update($request->validated());

        return $this->success(new SeguroTablaResource($seguro));
    }

    /**
     * Baja lógica: no elimina el registro (los detalles de relaciones ya generados guardan
     * su propio monto de seguro calculado), solo deja de aplicarse a cortes futuros.
     */
    public function destroy(SeguroTabla $seguro): JsonResponse
    {
        $seguro->update(['activo' => false]);

        return $this->success(new SeguroTablaResource($seguro), 'Rango de seguro desactivado.');
    }
}
