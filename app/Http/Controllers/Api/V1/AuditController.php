<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Auth\AuditService;
use Illuminate\Http\JsonResponse;

final class AuditController extends ApiController
{
    public function __construct(
        private readonly AuditService $auditService
    ) {}

    public function getHistoricalData(): JsonResponse
    {
        $logs = $this->auditService->getHistoricalLoginData(10);

        return $this->success(
            data: $logs,
            message: 'Historial de auditoría de accesos obtenido exitosamente.'
        );
    }
}
