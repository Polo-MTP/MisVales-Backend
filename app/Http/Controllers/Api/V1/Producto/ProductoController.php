<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Producto;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Productos\ProductoStoreRequest;
use App\Http\Requests\Api\V1\Productos\ProductoUpdateRequest;
use App\Http\Resources\Productos\ProductoResource;
use App\Models\Producto;
use App\Services\Producto\ProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductoController extends Controller
{
    public function __construct(protected ProductoService $productoService) {}

    /**
     * ?activos=false permite ver también los productos desactivados (para poder reactivarlos).
     * Por defecto solo regresa los activos. Si quien pregunta es una Distribuidora, el
     * catálogo se filtra a lo que puede pedir ahora mismo (ver ProductoService::listar()).
     */
    public function index(Request $request): JsonResponse
    {
        $soloActivos = $request->boolean('activos', true);
        $usuario = $request->user();
        $distribuidora = $usuario?->role?->name === 'Distribuidora' ? $usuario->distribuidora : null;
        $productos = $this->productoService->listar($soloActivos, $distribuidora);

        return response()->json(ProductoResource::collection($productos));
    }

    /**
     * Crea un nuevo producto del catálogo.
     */
    public function store(ProductoStoreRequest $request): JsonResponse
    {
        $producto = $this->productoService->crear($request->validated());

        return response()->json(new ProductoResource($producto), 201);
    }

    /**
     * Muestra el detalle de un producto.
     */
    public function show(Producto $producto): JsonResponse
    {
        return response()->json(new ProductoResource($producto));
    }

    /**
     * Actualiza los datos de un producto existente.
     */
    public function update(ProductoUpdateRequest $request, Producto $producto): JsonResponse
    {
        $producto = $this->productoService->actualizar($producto, $request->validated());

        return response()->json(new ProductoResource($producto));
    }

    /**
     * Desactiva un producto (baja lógica, no elimina el registro).
     */
    public function destroy(Producto $producto): JsonResponse
    {
        $this->productoService->desactivar($producto);

        return response()->json(['message' => 'Producto desactivado']);
    }
}
