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
    public function getHistoricalLoginData(int $perPage = 10, array $filters = []): LengthAwarePaginator
    {
        $query = LoginAttempt::query()
            ->with(['user.role', 'user.sucursal'])
            ->latest();

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('email_attempted', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('failure_reason', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Obtiene el log de auditoría completo del sistema con filtros avanzados y relaciones.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getAuditLog(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = AuditLog::query()
            ->with(['user.role', 'sucursal'])
            ->latest();

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['sucursal_id'])) {
            $query->where('sucursal_id', (int) $filters['sucursal_id']);
        }

        if (! empty($filters['modulo'])) {
            if ($filters['modulo'] === 'General') {
                $query->where(function ($q): void {
                    $q->where('modulo', 'General')->orWhereNull('modulo');
                });
            } else {
                $query->where('modulo', $filters['modulo']);
            }
        }

        if (! empty($filters['nivel'])) {
            $query->where('nivel', $filters['nivel']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', 'like', '%'.$filters['action'].'%');
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('descripcion', 'like', "%{$search}%")
                    ->orWhere('resource', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search): void {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return $query->paginate($perPage);
    }
}
