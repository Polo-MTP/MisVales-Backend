<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Relacion;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Relacion\RelacionResource;
use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\User;
use App\Services\Relacion\RelacionCalculoService;
use App\Services\Relacion\RelacionEstadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RelacionController extends ApiController
{
    public function __construct(
        private readonly RelacionCalculoService $relacionCalculoService,
        private readonly RelacionEstadoService $relacionEstadoService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Relacion::query()->with(['sucursal', 'categoriaSnapshot']);

        if ($request->filled('distribuidora_id')) {
            $query->where('distribuidora_id', $request->integer('distribuidora_id'));
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado'));
        }

        $relaciones = $query->latest('fecha_corte')->paginate((int) $request->input('per_page', 15));

        return $this->success(
            data: RelacionResource::collection($relaciones)->response()->getData(true),
            message: 'Lista de relaciones obtenida exitosamente.'
        );
    }

    public function show(Relacion $relacion): JsonResponse
    {
        $relacion->load(['sucursal', 'categoriaSnapshot', 'detalles.vale', 'detalles.cliente.datosPersonales', 'detalles.producto']);

        return $this->success(
            data: new RelacionResource($relacion),
            message: 'Detalle de relación obtenido exitosamente.'
        );
    }

    public function generar(Request $request): JsonResponse
    {
        $request->validate([
            'distribuidora_id' => ['nullable', 'integer', 'exists:distribuidoras,id'],
            'fecha_corte' => ['nullable', 'date'],
        ]);

        try {
            if ($request->filled('distribuidora_id')) {
                $distribuidora = Distribuidora::query()->findOrFail($request->integer('distribuidora_id'));
                $relacion = $this->relacionCalculoService->generarParaDistribuidora($distribuidora, $request->input('fecha_corte'));

                if (! $relacion) {
                    return $this->success(message: 'La distribuidora no tiene vales con saldo pendiente; no se generó relación.');
                }

                return $this->created(
                    data: new RelacionResource($relacion->load(['detalles.vale', 'detalles.cliente', 'detalles.producto', 'sucursal', 'categoriaSnapshot'])),
                    message: 'Relación generada exitosamente.'
                );
            }

            $generadas = $this->relacionCalculoService->generarCortesDelDia($request->input('fecha_corte'));

            return $this->created(
                data: RelacionResource::collection(array_values($generadas)),
                message: count($generadas).' relación(es) generada(s) para el corte del día.'
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function perdonar(Request $request, Relacion $relacion): JsonResponse
    {
        $request->validate([
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $usuario */
        $usuario = $request->user();

        try {
            $relacion = $this->relacionEstadoService->perdonar($relacion, $usuario, $request->input('motivo'));

            return $this->success(
                data: new RelacionResource($relacion),
                message: $relacion->estado === 'perdonada' ? 'Relación perdonada exitosamente.' : 'Límite de perdones alcanzado: la relación se marcó como pérdida.'
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage());
        }
    }
}
