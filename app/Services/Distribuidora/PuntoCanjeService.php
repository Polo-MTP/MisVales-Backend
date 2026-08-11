<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\PuntoMovimiento;
use App\Models\User;
use DomainException;
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
}
