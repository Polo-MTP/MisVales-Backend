<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\Relacion;
use App\Models\RelacionPerdon;
use App\Models\User;
use App\Services\Configuracion\ConfiguracionService;
use Illuminate\Support\Facades\DB;

/**
 * Transiciones de estado de una Relacion que no dependen del cálculo ni de la conciliación:
 * vencimiento automático por fecha, y perdón/pérdida autorizados por gerencia.
 */
final class RelacionEstadoService
{
    public function __construct(
        private readonly ConfiguracionService $configuracionService,
    ) {}

    /**
     * Marca como 'vencida' cualquier relación pendiente/parcial cuya fecha límite de pago ya pasó.
     */
    public function marcarVencidas(?string $fecha = null): int
    {
        $fecha = $fecha ?? now()->toDateString();

        return Relacion::query()
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->whereDate('fecha_limite_pago', '<', $fecha)
            ->update(['estado' => 'vencida']);
    }

    /**
     * Perdona una relación vencida. La 1a y 2a vez (configurable via 'limite_perdones_relacion')
     * se perdona; a partir de ahí, la relación pasa directo a 'en_perdida'.
     */
    public function perdonar(Relacion $relacion, User $autorizador, ?string $motivo = null): Relacion
    {
        if (! in_array($relacion->estado, ['pendiente', 'parcial', 'vencida'], true)) {
            throw new \DomainException('Solo se pueden perdonar relaciones con saldo pendiente (pendiente, parcial o vencida).');
        }

        $limitePerdones = (int) ($this->configuracionService->obtenerValorVigente('limite_perdones_relacion') ?? 2);
        $perdonesPrevios = RelacionPerdon::query()->where('distribuidora_id', $relacion->distribuidora_id)->count();

        return DB::transaction(function () use ($relacion, $autorizador, $motivo, $limitePerdones, $perdonesPrevios): Relacion {
            if ($perdonesPrevios >= $limitePerdones) {
                $relacion->update(['estado' => 'en_perdida']);

                return $relacion->fresh();
            }

            RelacionPerdon::query()->create([
                'distribuidora_id' => $relacion->distribuidora_id,
                'relacion_id' => $relacion->id,
                'numero_perdon' => $perdonesPrevios + 1,
                'autorizado_por' => $autorizador->id,
                'motivo' => $motivo,
            ]);

            $relacion->update(['estado' => 'perdonada']);

            return $relacion->fresh('perdon');
        });
    }
}
