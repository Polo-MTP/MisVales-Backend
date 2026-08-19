<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Relacion;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Relacion\DecidirSolicitudConciliacionRequest;
use App\Http\Requests\Api\V1\Relacion\EjecutarConciliacionManualRequest;
use App\Http\Requests\Api\V1\Relacion\ImportarConciliacionRequest;
use App\Http\Requests\Api\V1\Relacion\LevantarQuejaAbonoRequest;
use App\Http\Requests\Api\V1\Relacion\SolicitarConciliacionRequest;
use App\Http\Resources\Relacion\AbonoConciliacionResource;
use App\Http\Resources\Relacion\SolicitudConciliacionResource;
use App\Models\AbonoConciliacion;
use App\Models\SolicitudConciliacion;
use App\Models\User;
use App\Services\Relacion\ConciliacionBancariaService;
use App\Services\Relacion\SolicitudConciliacionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConciliacionController extends ApiController
{
    public function __construct(
        private readonly ConciliacionBancariaService $conciliacionService,
        private readonly SolicitudConciliacionService $solicitudConciliacionService,
    ) {}

    /**
     * Lista los abonos de conciliación bancaria, filtrables por estado y relación.
     */
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

    /**
     * Importa un archivo de movimientos bancarios y concilia automáticamente los abonos que coincidan.
     */
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

    /**
     * Lista las solicitudes de conciliación manual visibles para el usuario.
     */
    public function listarAutorizaciones(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $solicitudes = $this->solicitudConciliacionService->listar($usuario, $request->all());

        return $this->success(
            data: SolicitudConciliacionResource::collection($solicitudes)->response()->getData(true),
            message: 'Lista de solicitudes de conciliación manual obtenida exitosamente.'
        );
    }

    /**
     * Solicita autorización para conciliar manualmente un abono que no coincidió automáticamente.
     */
    public function solicitarAutorizacion(SolicitarConciliacionRequest $request, AbonoConciliacion $abono): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        try {
            $solicitud = $this->solicitudConciliacionService->solicitar(
                $abono,
                $request->integer('relacion_id'),
                (string) $request->string('motivo'),
                $usuario
            );

            return $this->created(
                data: new SolicitudConciliacionResource($solicitud),
                message: 'Solicitud de conciliación manual enviada. Queda pendiente de autorización.'
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * La distribuidora reporta que un abono no coincide con lo que ella pagó. Es solo un
     * reporte informativo — no ejecuta ninguna corrección por sí solo, avisa a la cajera.
     */
    public function levantarQueja(LevantarQuejaAbonoRequest $request, AbonoConciliacion $abono): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $abono = $this->conciliacionService->levantarQueja($abono, $usuario, (string) $request->string('motivo'));

        return $this->success(
            data: new AbonoConciliacionResource($abono),
            message: 'Queja registrada. La cajera de tu sucursal iniciará la conciliación manual.'
        );
    }

    /**
     * Autoriza o rechaza una solicitud de conciliación manual.
     */
    public function decidirAutorizacion(DecidirSolicitudConciliacionRequest $request, SolicitudConciliacion $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        try {
            $solicitud = $this->solicitudConciliacionService->decidir(
                $solicitud,
                (string) $request->string('decision'),
                $request->filled('comentario') ? (string) $request->string('comentario') : null,
                $usuario
            );

            return $this->success(
                data: new SolicitudConciliacionResource($solicitud),
                message: 'Decisión registrada exitosamente.'
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Ejecuta la conciliación manual ya autorizada, marcando el abono como conciliado.
     */
    public function conciliarManual(EjecutarConciliacionManualRequest $request, AbonoConciliacion $abono): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $solicitud = SolicitudConciliacion::query()
            ->where('abono_conciliacion_id', $abono->id)
            ->findOrFail($request->integer('solicitud_id'));

        try {
            $abonoActualizado = $this->solicitudConciliacionService->ejecutar($solicitud, $usuario);

            return $this->success(
                data: new AbonoConciliacionResource($abonoActualizado),
                message: 'Abono conciliado manualmente.'
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }
    }
}
