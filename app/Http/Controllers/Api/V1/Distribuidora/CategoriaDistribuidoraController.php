<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Distribuidora;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Distribuidora\CategoriaDistribuidoraStoreRequest;
use App\Http\Requests\Api\V1\Distribuidora\CategoriaDistribuidoraUpdateRequest;
use App\Http\Resources\Distribuidora\CategoriaDistribuidoraResource;
use App\Models\CategoriaDistribuidora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CategoriaDistribuidoraController extends ApiController
{
    /**
     * Lista las categorías de distribuidora. Por defecto solo las activas; ?activas=false
     * también incluye las desactivadas (para poder reactivarlas).
     */
    public function index(Request $request): JsonResponse
    {
        $query = CategoriaDistribuidora::query()->orderBy('nombre');

        if ($request->boolean('activas', true)) {
            $query->where('activo', true);
        }

        return $this->success(CategoriaDistribuidoraResource::collection($query->get()));
    }

    /**
     * Crea una nueva categoría de distribuidora (el % de comisión que se descuenta del pago
     * quincenal en RelacionCalculoService, ver "Analisis de calculo de relacion").
     */
    public function store(CategoriaDistribuidoraStoreRequest $request): JsonResponse
    {
        $categoria = CategoriaDistribuidora::query()->create($request->validated());

        return $this->created(new CategoriaDistribuidoraResource($categoria));
    }

    /**
     * Actualiza el nombre, porcentaje o descripción de una categoría existente.
     */
    public function update(CategoriaDistribuidoraUpdateRequest $request, CategoriaDistribuidora $categoria): JsonResponse
    {
        $categoria->update($request->validated());

        return $this->success(new CategoriaDistribuidoraResource($categoria));
    }

    /**
     * Baja lógica: no elimina el registro (las relaciones ya generadas guardan su propio
     * snapshot del % vigente en ese momento), solo deja de ofrecerse para nuevas asignaciones.
     */
    public function destroy(CategoriaDistribuidora $categoria): JsonResponse
    {
        $categoria->update(['activo' => false]);

        return $this->success(new CategoriaDistribuidoraResource($categoria), 'Categoría desactivada.');
    }
}
