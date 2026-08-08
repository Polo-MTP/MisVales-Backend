<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Relacion;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Relacion\ConciliarManualRequest;
use App\Http\Requests\Api\V1\Relacion\ImportarConciliacionRequest;
use App\Http\Resources\Relacion\AbonoConciliacionResource;
use App\Models\AbonoConciliacion;
use App\Models\Relacion;
use App\Models\User;
use App\Services\Relacion\ConciliacionBancariaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConciliacionController extends ApiController
{
    public function __construct(
        private readonly ConciliacionBancariaService $conciliacionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = AbonoConciliacion::query()->with(['convenioBancario', 'autorizadoPor']);

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado'));
        }

        if ($request->filled('relacion_id')) {
            $query->where('relacion_id', $request->integer('relacion_id'));
        }

        $abonos = $query->latest('fecha_pago')->paginate((int) $request->input('per_page', 20));

        return $this->success(
            data: AbonoConciliacionResource::collection($abonos)->response()->getData(true),
            message: 'Lista de abonos obtenida exitosamente.'
        );
    }

    public function importar(ImportarConciliacionRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $resumen = $this->conciliacionService->importarArchivo(
            $request->file('archivo'),
            $request->integer('convenio_bancario_id') ?: null,
            $usuario
        );

        return $this->success(
            data: $resumen,
            message: "Archivo procesado: {$resumen['procesadas']} fila(s), {$resumen['conciliadas']} conciliada(s), {$resumen['sin_coincidencia']} sin coincidencia."
        );
    }

    public function conciliarManual(ConciliarManualRequest $request, AbonoConciliacion $abono): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $relacion = Relacion::query()->findOrFail($request->integer('relacion_id'));

        try {
            $abonoActualizado = $this->conciliacionService->conciliarManual(
                $abono,
                $relacion,
                $usuario,
                (string) $request->string('motivo')
            );

            return $this->success(
                data: new AbonoConciliacionResource($abonoActualizado),
                message: 'Abono conciliado manualmente.'
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage());
        }
    }
}
