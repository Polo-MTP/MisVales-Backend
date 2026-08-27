<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\PuntoMovimiento;
use App\Models\User;
use App\Services\Configuracion\ConfiguracionService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Canje de puntos de una distribuidora, capturado por la cajera en caja. Solo descuenta
 * el saldo y registra el movimiento; no se liga a ninguna cuota o relación específica.
 */
final class PuntoCanjeService
{
    public function __construct(
        private readonly ConfiguracionService $configuracionService,
    ) {}

    /**
     * Descuenta puntos acumulados de la distribuidora y registra el movimiento de canje.
     */
    public function canjear(Distribuidora $distribuidora, int $cantidad, string $motivo, User $cajera): PuntoMovimiento
    {
        $this->verificarAcceso($distribuidora, $cajera);

        if ($cantidad <= 0) {
            throw new DomainException('La cantidad a canjear debe ser mayor a cero.');
        }

        $valorPunto = (float) ($this->configuracionService->obtenerValorVigente('valor_punto') ?? 0);

        return DB::transaction(function () use ($distribuidora, $cantidad, $motivo, $cajera, $valorPunto): PuntoMovimiento {
            // lockForUpdate(): sin esto, dos canjes casi simultáneos de la misma distribuidora
            // (dos cajeras, o un doble-clic) leen el mismo puntos_acumulados y ambos pasan la
            // validación de "hay suficientes" antes de que ninguno descuente -- decrement() es
            // atómico a nivel SQL (no se pierde ningún descuento), pero nada impedía que el
            // saldo terminara en negativo si la suma de los dos canjes excedía lo disponible.
            /** @var Distribuidora $distribuidoraBloqueada */
            $distribuidoraBloqueada = Distribuidora::query()->whereKey($distribuidora->id)->lockForUpdate()->firstOrFail();

            if ($cantidad > $distribuidoraBloqueada->puntos_acumulados) {
                throw new DomainException('La distribuidora no cuenta con puntos suficientes para este canje.');
            }

            /** @var PuntoMovimiento $movimiento */
            $movimiento = PuntoMovimiento::query()->create([
                'distribuidora_id' => $distribuidora->id,
                'tipo' => 'redimido',
                'cantidad' => -$cantidad,
                'valor_punto_snapshot' => $valorPunto,
                'motivo' => $motivo,
                'registrado_por' => $cajera->id,
            ]);

            $distribuidoraBloqueada->decrement('puntos_acumulados', $cantidad);

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

    /**
     * Verifica que el usuario tenga permiso para operar los puntos de esta distribuidora
     * (dueño si es rol Distribuidora, misma sucursal si es Gerente de Sucursal/Cajera).
     */
    private function verificarAcceso(Distribuidora $distribuidora, User $usuario): void
    {
        $role = $usuario->role?->name;

        if ($role === 'Distribuidora' && $distribuidora->usuario_id !== $usuario->id) {
            abort(403, 'Solo puedes operar los puntos de tu propia distribuidora.');
        }

        if (in_array($role, ['Gerente de Sucursal', 'Cajera'], true) && $distribuidora->sucursal_id !== $usuario->sucursal_id) {
            abort(403, 'No puedes operar puntos de una distribuidora de otra sucursal.');
        }
    }
}
