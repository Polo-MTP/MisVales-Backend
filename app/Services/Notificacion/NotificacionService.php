<?php

declare(strict_types=1);

namespace App\Services\Notificacion;

use App\Models\Notificacion;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Gerente de Sucursal ve todos los movimientos de su sucursal (vista de supervisión);
 * Gerente General y Administrador ven todos los movimientos del aplicativo, sin filtrar.
 * El resto de roles (Distribuidora, Verificador, Coordinador, Cajera) solo ven las
 * notificaciones dirigidas a ellos mismos (destinatario_id).
 */
final class NotificacionService
{
    /**
     * Crea una notificación dirigida a un usuario específico.
     */
    public function crear(User $destinatario, string $accion, ?string $recurso = null, ?User $actor = null): Notificacion
    {
        /** @var Notificacion $notificacion */
        $notificacion = Notificacion::query()->create([
            'sucursal_id' => $destinatario->sucursal_id,
            'user_id' => $actor?->id,
            'destinatario_id' => $destinatario->id,
            'accion' => $accion,
            'recurso' => $recurso,
        ]);

        return $notificacion;
    }

    /**
     * Notifica a todos los usuarios de un rol dentro de una sucursal (ej. todas las Cajeras
     * de una sucursal cuando una distribuidora cae en MOROSO -- no hay una cajera "dueña"
     * de cada distribuidora, así que se avisa a todas las de esa sucursal).
     *
     * @return Collection<int, Notificacion>
     */
    public function notificarRolEnSucursal(string $role, ?int $sucursalId, string $accion, ?string $recurso = null, ?User $actor = null): Collection
    {
        if ($sucursalId === null) {
            return collect();
        }

        return User::query()
            ->where('sucursal_id', $sucursalId)
            ->whereHas('role', fn ($q) => $q->where('name', $role))
            ->get()
            ->map(fn (User $destinatario) => $this->crear($destinatario, $accion, $recurso, $actor));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listar(User $usuario, array $filters = []): LengthAwarePaginator
    {
        $query = Notificacion::query()->with(['sucursal', 'usuario', 'destinatario']);

        $role = $usuario->role?->name;

        if ($role === 'Gerente de Sucursal') {
            $query->where('sucursal_id', $usuario->sucursal_id);
        } elseif (! in_array($role, ['Gerente General', 'Administrador'], true)) {
            $query->where('destinatario_id', $usuario->id);
        }

        if (! empty($filters['accion'])) {
            $query->where('accion', 'like', '%'.$filters['accion'].'%');
        }

        if (array_key_exists('leidas', $filters) && $filters['leidas'] !== null) {
            filter_var($filters['leidas'], FILTER_VALIDATE_BOOLEAN)
                ? $query->whereNotNull('leido_at')
                : $query->whereNull('leido_at');
        }

        return $query->latest('id')->paginate((int) ($filters['per_page'] ?? 20));
    }

    /**
     * Marca como leída una notificación — solo su propio destinatario puede hacerlo.
     */
    public function marcarLeida(Notificacion $notificacion, User $usuario): Notificacion
    {
        if ($notificacion->destinatario_id !== $usuario->id) {
            throw new DomainException('Esta notificación no es tuya.');
        }

        if ($notificacion->leido_at === null) {
            $notificacion->update(['leido_at' => now()]);
        }

        return $notificacion;
    }
}
