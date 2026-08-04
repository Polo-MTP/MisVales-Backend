<?php

namespace App\Http\Controllers\Api\V1\Producto;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Productos\ProductoStoreRequest;
use App\Http\Requests\Api\V1\Productos\ProductoUpdateRequest;

use App\Http\Resources\Productos\ProductoResource;
use App\Models\Producto;
use App\Services\Producto\ProductoService;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoController extends Controller
{
    public function __construct(protected ProductoService $productoService)
    {
    }

    public function index(): JsonResponse
    {
        $productos = $this->productoService->listar(true);
        return response()->json(ProductoResource::collection($productos));
    }

    public function store(ProductoStoreRequest $request): JsonResponse
    {
        $producto = $this->productoService->crear($request->validated());
        return response()->json(new ProductoResource($producto), 201);
    }

    public function show(Producto $producto): JsonResponse
    {
        return response()->json(new ProductoResource($producto));
    }

    public function update(ProductoUpdateRequest $request, Producto $producto): JsonResponse
    {
        $producto = $this->productoService->actualizar($producto, $request->validated());
        return response()->json(new ProductoResource($producto));
    }

    public function destroy(Producto $producto): JsonResponse
    {
        $this->productoService->desactivar($producto);
        return response()->json(['message' => 'Producto desactivado']);
    }
}