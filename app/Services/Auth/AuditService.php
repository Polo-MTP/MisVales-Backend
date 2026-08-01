<?php

declare(strict_types=1);

namespace App\Services\Auth;

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
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }
}
