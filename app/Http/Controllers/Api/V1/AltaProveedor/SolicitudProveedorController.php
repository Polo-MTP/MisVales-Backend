<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\AltaProveedor;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\AltaProveedor\AprobarSolicitudProveedorRequest;
use App\Http\Requests\Api\V1\AltaProveedor\CrearSolicitudProveedorRequest;
use App\Http\Requests\Api\V1\AltaProveedor\VerificarSolicitudProveedorRequest;
use App\Http\Resources\AltaProveedor\SolicitudProveedorResource;
use App\Models\SolicitudProveedor;
use App\Models\User;
use App\Services\AltaProveedor\SolicitudProveedorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final class SolicitudProveedorController extends ApiController
{
    public function index(): JsonResponse
    {
        $solicitudes = QueryBuilder::for(SolicitudProveedor::class)
            ->allowedFilters([
                'estado',
                'decision_gerente',
                AllowedFilter::exact('coordinador_id'),
                AllowedFilter::exact('verificador_id'),
            ])
            ->allowedIncludes(['datosPersonales.direccion', 'coordinador', 'verificador', 'gerente', 'evidencias', 'logs'])
            ->allowedSorts(['created_at', 'id'])
            ->defaultSort('-created_at')
            ->paginate();

        return $this->success(SolicitudProveedorResource::collection($solicitudes));
    }

    public function show(SolicitudProveedor $solicitud): JsonResponse
    {
        $solicitud->load(['datosPersonales.direccion', 'coordinador', 'verificador', 'gerente', 'evidencias', 'logs.usuario']);

        return $this->success(new SolicitudProveedorResource($solicitud));
    }

    public function store(CrearSolicitudProveedorRequest $request, SolicitudProveedorService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $solicitud = $service->crearSolicitud($request->validated(), $user);

        return $this->created(new SolicitudProveedorResource($solicitud), 'Solicitud de nuevo proveedor capturada exitosamente.');
    }

    public function verificar(SolicitudProveedor $solicitud, VerificarSolicitudProveedorRequest $request, SolicitudProveedorService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $solicitudActualizada = $service->verificarSolicitud($solicitud, $request->validated(), $user);

        return $this->success(new SolicitudProveedorResource($solicitudActualizada), 'Verificación registrada y dictaminada exitosamente.');
    }

    public function aprobarORechazar(SolicitudProveedor $solicitud, AprobarSolicitudProveedorRequest $request, SolicitudProveedorService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $solicitudProcesada = $service->aprobarORechazar($solicitud, $request->validated(), $user);

        return $this->success(new SolicitudProveedorResource($solicitudProcesada), 'Decisión de gerencia registrada exitosamente.');
    }
}
