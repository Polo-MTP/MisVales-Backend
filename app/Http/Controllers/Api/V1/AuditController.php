<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Auth\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditController extends ApiController
{
    public function __construct(
        private readonly AuditService $auditService
    ) {}

    /**
     * Devuelve el historial reciente de intentos de login con filtros.
     */
    public function getHistoricalData(Request $request): JsonResponse
    {
        $logs = $this->auditService->getHistoricalLoginData(
            (int) $request->input('per_page', 10),
            $request->only(['user_id', 'status', 'search'])
        );

        return $this->success(
            data: $logs,
            message: 'Historial de auditoría de accesos obtenido exitosamente.'
        );
    }

    /**
     * Lista el log de auditoría paginado, filtrable por usuario, sucursal, módulo, nivel, acción y texto.
     */
    public function getAuditLog(Request $request): JsonResponse
    {
        $logs = $this->auditService->getAuditLog(
            (int) $request->input('per_page', 20),
            $request->only(['user_id', 'sucursal_id', 'modulo', 'nivel', 'action', 'search'])
        );

        return $this->success(
            data: $logs,
            message: 'Log de auditoría obtenido exitosamente.'
        );
    }
}
