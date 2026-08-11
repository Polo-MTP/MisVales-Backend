<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Distribuidora;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Distribuidora\CategoriaDistribuidoraResource;
use App\Models\CategoriaDistribuidora;
use Illuminate\Http\JsonResponse;

final class CategoriaDistribuidoraController extends ApiController
{
    /**
     * Lista las categorías de distribuidora activas, usadas al asignar crédito (PUT distribuidoras/{id}/credito).
     */
    public function index(): JsonResponse
    {
        $categorias = CategoriaDistribuidora::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        return $this->success(CategoriaDistribuidoraResource::collection($categorias));
    }
}
