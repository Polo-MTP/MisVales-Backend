<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sucursal;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Sucursal\SucursalStoreRequest;
use App\Http\Requests\Api\V1\Sucursal\SucursalUpdateRequest;
use App\Http\Resources\Sucursal\SucursalResource;
use App\Models\Sucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SucursalController extends ApiController
{
    /**
     * Lista sucursales. Por defecto solo las activas; ?activas=false también incluye las
     * desactivadas.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Sucursal::query()->orderBy('nombre');

        if ($request->boolean('activas', true)) {
            $query->where('is_active', true);
        }

        return $this->success(SucursalResource::collection($query->get()));
    }

    /**
     * Da de alta una nueva sucursal.
     */
    public function store(SucursalStoreRequest $request): JsonResponse
    {
        $sucursal = Sucursal::query()->create($request->validated());

        return $this->created(new SucursalResource($sucursal));
    }

    public function show(Sucursal $sucursal): JsonResponse
    {
        return $this->success(new SucursalResource($sucursal));
    }

    public function update(SucursalUpdateRequest $request, Sucursal $sucursal): JsonResponse
    {
        $sucursal->update($request->validated());

        return $this->success(new SucursalResource($sucursal));
    }
}
