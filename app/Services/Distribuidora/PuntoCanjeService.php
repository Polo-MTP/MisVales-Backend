<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\PuntoMovimiento;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Canje de puntos de una distribuidora, capturado por la cajera en caja. Solo descuenta
 * el saldo y registra el movimiento; no se liga a ninguna cuota o relación específica.
 */
final class PuntoCanjeService
{
    public function canjear(Distribuidora $distribuidora, int $cantidad, string $motivo, User $cajera): PuntoMovimiento
    {
        if ($cantidad <= 0) {
            throw new DomainException('La cantidad a canjear debe ser mayor a cero.');
        }

        if ($cantidad > $distribuidora->puntos_acumulados) {
            throw new DomainException('La distribuidora no cuenta con puntos suficientes para este canje.');
        }

        return DB::transaction(function () use ($distribuidora, $cantidad, $motivo, $cajera): PuntoMovimiento {
            /** @var PuntoMovimiento $movimiento */
            $movimiento = PuntoMovimiento::query()->create([
                'distribuidora_id' => $distribuidora->id,
                'tipo' => 'redimido',
                'cantidad' => -$cantidad,
                'motivo' => $motivo,
                'registrado_por' => $cajera->id,
            ]);

            $distribuidora->decrement('puntos_acumulados', $cantidad);

            return $movimiento->fresh();
        });
    }

    /**
     * Historial de movimientos de puntos (generados, penalizados, canjeados, ajustes) de una
     * distribuidora. Sin esto no había forma de ver cómo se llegó al saldo actual.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listar(Distribuidora $distribuidora, User $usuario, array $filters = []): LengthAwarePaginator
    {
        $this->verificarAcceso($distribuidora, $usuario);

        $query = PuntoMovimiento::query()
            ->where('distribuidora_id', $distribuidora->id)
            ->with('registradoPor');

        if (! empty($filters['tipo'])) {
            $query->where('tipo', $filters['tipo']);
        }

        return $query->latest('id')->paginate((int) ($filters['per_page'] ?? 15));
    }

    private function verificarAcceso(Distribuidora $distribuidora, User $usuario): void
    {
        $role = $usuario->role?->name;

        if ($role === 'Distribuidora' && $distribuidora->usuario_id !== $usuario->id) {
            abort(403, 'Solo puedes ver los movimientos de puntos de tu propia distribuidora.');
        }

        if ($role === 'Gerente de Sucursal' && $distribuidora->sucursal_id !== $usuario->sucursal_id) {
            abort(403, 'No puedes ver movimientos de puntos de una distribuidora de otra sucursal.');
        }
    }
}
