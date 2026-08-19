<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\RelacionPerdon;
use App\Models\User;
use App\Services\Configuracion\ConfiguracionService;
use App\Services\Distribuidora\DistribuidoraEstadoService;
use Illuminate\Support\Facades\DB;

/**
 * Transiciones de estado de una Relacion que no dependen del cálculo ni de la conciliación:
 * vencimiento automático por fecha, y perdón/pérdida autorizados por gerencia.
 */
final class RelacionEstadoService
{
    public function __construct(
        private readonly ConfiguracionService $configuracionService,
        private readonly DistribuidoraEstadoService $distribuidoraEstadoService,
    ) {}

    /**
     * Marca como 'vencida' cualquier relación pendiente/parcial cuya fecha límite de pago ya
     * pasó, y evalúa MOROSIDAD automática para cada distribuidora afectada.
     */
    public function marcarVencidas(?string $fecha = null): int
    {
        $fecha = $fecha ?? now()->toDateString();

        $distribuidorasAfectadas = Relacion::query()
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->whereDate('fecha_limite_pago', '<', $fecha)
            ->pluck('distribuidora_id')
            ->unique();

        $total = Relacion::query()
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->whereDate('fecha_limite_pago', '<', $fecha)
            ->update(['estado' => 'vencida']);

        foreach ($distribuidorasAfectadas as $distribuidoraId) {
            $this->evaluarMorosidad((int) $distribuidoraId);
        }

        return $total;
    }

    /**
     * Si la distribuidora acumula 'relaciones_impagas_para_morosidad' (default 3) relaciones
     * en 'vencida' o 'en_perdida', pasa automáticamente a MOROSO — sin esperar a que gerencia
     * lo note manualmente. No la reactiva sola: volver a ACTIVO sigue siendo decisión humana
     * (PUT /distribuidoras/{id}/estado).
     */
    private function evaluarMorosidad(int $distribuidoraId): void
    {
        /** @var Distribuidora|null $distribuidora */
        $distribuidora = Distribuidora::query()->find($distribuidoraId);

        if (! $distribuidora || ! in_array($distribuidora->estado, ['ACTIVO', 'EN_VERIFICACION'], true)) {
            return;
        }

        $limite = (int) ($this->configuracionService->obtenerValorVigente('relaciones_impagas_para_morosidad') ?? 3);

        $relacionesImpagas = Relacion::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->whereIn('estado', ['vencida', 'en_perdida'])
            ->count();

        if ($relacionesImpagas < $limite) {
            return;
        }

        $this->distribuidoraEstadoService->cambiarEstado(
            $distribuidora,
            'MOROSO',
            "Marcada MOROSA automáticamente: {$relacionesImpagas} relación(es) sin pagar (límite configurado: {$limite}).",
            null,
        );
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
                $this->evaluarMorosidad($relacion->distribuidora_id);

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
