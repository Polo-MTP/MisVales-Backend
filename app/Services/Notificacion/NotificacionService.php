<?php

declare(strict_types=1);

namespace App\Services\Notificacion;

use App\Models\Notificacion;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Gerente de Sucursal ve solo los movimientos de su sucursal; Gerente General y Administrador
 * ven todos los movimientos del aplicativo, sin filtrar.
 */
final class NotificacionService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function listar(User $usuario, array $filters = []): LengthAwarePaginator
    {
        $query = Notificacion::query()->with(['sucursal', 'usuario']);

        if ($usuario->role?->name === 'Gerente de Sucursal') {
            $query->where('sucursal_id', $usuario->sucursal_id);
        }

        if (! empty($filters['accion'])) {
            $query->where('accion', 'like', '%'.$filters['accion'].'%');
        }

        return $query->latest('id')->paginate((int) ($filters['per_page'] ?? 20));
    }
}
