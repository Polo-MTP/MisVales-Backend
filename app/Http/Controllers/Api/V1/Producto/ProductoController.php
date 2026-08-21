<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Producto;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Productos\ProductoStoreRequest;
use App\Http\Requests\Api\V1\Productos\ProductoUpdateRequest;
use App\Http\Resources\Productos\ProductoResource;
use App\Models\Distribuidora;
use App\Models\Producto;
use App\Services\Producto\ProductoService;
use App\Services\Relacion\RelacionCalculoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductoController extends Controller
{
    public function __construct(
        protected ProductoService $productoService,
        protected RelacionCalculoService $relacionCalculoService,
    ) {}

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

    /**
     * Previsualiza cuánto le tocaría pagar por quincena a una distribuidora si le dan este
     * producto, usando las reglas vigentes ahora mismo — sin necesidad de solicitar un vale
     * ni de que se genere ningún corte. Si quien pregunta es una Distribuidora, usa su propia
     * categoría; el staff puede pasar ?distribuidora_id= para simular la de alguien más.
     */
    public function simular(Request $request, Producto $producto): JsonResponse
    {
        $usuario = $request->user();

        $distribuidora = $usuario?->role?->name === 'Distribuidora'
            ? $usuario->distribuidora
            : ($request->filled('distribuidora_id') ? Distribuidora::query()->find($request->integer('distribuidora_id')) : null);

        $resultado = $this->relacionCalculoService->simularPagoQuincenal(
            (float) $producto->monto,
            (int) ($producto->quincenas ?? 1),
            $distribuidora,
        );

        return response()->json([
            'producto_id' => $producto->id,
            'monto' => (float) $producto->monto,
            'quincenas' => (int) ($producto->quincenas ?? 1),
            ...$resultado,
            'nota' => 'Estimado si paga puntual cada quincena, con las reglas vigentes hoy. El corte real puede variar si hay atraso (se pierde el descuento de categoría y se suma la multa) o si cambian los parámetros antes de generarse.',
        ]);
    }
}
