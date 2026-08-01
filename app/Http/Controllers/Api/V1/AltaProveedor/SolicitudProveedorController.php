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
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $baseQuery = SolicitudProveedor::query();

        // Filtrado por sucursal: Gerente General y Administrador ven todo; los demás roles solo ven su sucursal.
        if ($user->role?->name !== 'Gerente General' && $user->role?->name !== 'Administrador') {
            $baseQuery->where('sucursal_id', $user->sucursal_id);
        }

        $solicitudes = QueryBuilder::for($baseQuery)
            ->allowedFilters([
                'estado',
                'decision_gerente',
                AllowedFilter::exact('sucursal_id'),
                AllowedFilter::exact('coordinador_id'),
                AllowedFilter::exact('verificador_id'),
            ])
            ->allowedIncludes(['datosPersonales.direccion', 'sucursal', 'coordinador', 'verificador', 'gerente', 'evidencias', 'logs'])
            ->allowedSorts(['created_at', 'id'])
            ->defaultSort('-created_at')
            ->paginate();

        return $this->success(SolicitudProveedorResource::collection($solicitudes));
    }

    public function show(SolicitudProveedor $solicitud, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Validación de permisos por sucursal en consulta individual
        if ($user->role?->name !== 'Gerente General' && $user->role?->name !== 'Administrador') {
            if ($user->sucursal_id !== $solicitud->sucursal_id) {
                return $this->forbidden('Acceso Denegado. No tienes permisos para consultar solicitudes pertenecientes a otra sucursal.');
            }
        }

        $solicitud->load(['datosPersonales.direccion', 'sucursal', 'coordinador', 'verificador', 'gerente', 'evidencias', 'logs.usuario']);

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
