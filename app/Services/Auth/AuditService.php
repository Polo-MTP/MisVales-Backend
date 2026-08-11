<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\LoginAttempt;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class AuditService
{
    /**
     * Obtiene los datos paginados de auditoría de intentos de inicio de sesión.
     */
    public function getHistoricalLoginData(int $perPage = 10): LengthAwarePaginator
    {
        return LoginAttempt::query()
            ->with('user:id,name,email')->latest()
            ->paginate($perPage);
    }

    /**
     * Obtiene el log genérico de todo lo que se hace en el aplicativo (creación/edición/borrado
     * de los modelos de negocio observados por AuditLogObserver), paginado.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getAuditLog(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = AuditLog::query()->with('user:id,name,email')->latest();

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', 'like', '%'.$filters['action'].'%');
        }

        return $query->paginate($perPage);
    }
}
