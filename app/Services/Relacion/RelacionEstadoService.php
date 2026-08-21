<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\RelacionPerdon;
use App\Models\User;
use App\Services\Configuracion\ConfiguracionService;
use App\Services\Distribuidora\DistribuidoraEstadoService;
use DomainException;
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
     * Perdona una relación vencida. La 1a y 2a vez (configurable via 'limite_perdones_relacion')
     * se perdona; a partir de ahí, la relación pasa directo a 'en_perdida'.
     *
     * "Perdonar" condona el recargo por atraso y el interés — no el capital, comisión, seguro ni
     * categoría, que la distribuidora sigue debiendo — poniéndolos en 0 tanto en el total de la
     * relación como en cada cuota (RelacionDetalle). Antes solo se cambiaba el estado a
     * 'perdonada' sin tocar ningún monto: la distribuidora seguía "debiendo" exactamente lo
     * mismo, contradiciendo lo que el propio nombre/documentación de este método promete.
     *
     * Esto NO libera el crédito bloqueado por los vales de este corte (Vale.estado no cambia
     * aquí) — perdonar el recargo de un corte no implica que el vale ya esté saldado.
     */
    public function perdonar(Relacion $relacion, User $autorizador, ?string $motivo = null): Relacion
    {
        if (! in_array($relacion->estado, ['pendiente', 'parcial', 'vencida'], true)) {
            throw new DomainException('Solo se pueden perdonar relaciones con saldo pendiente (pendiente, parcial o vencida).');
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

            $relacion->loadMissing('detalles');

            foreach ($relacion->detalles as $detalle) {
                if ((float) $detalle->recargo <= 0 && (float) $detalle->interes <= 0) {
                    continue;
                }

                $nuevoTotal = max(0, round((float) $detalle->total - (float) $detalle->recargo - (float) $detalle->interes, 2));
                $detalle->update(['recargo' => 0, 'interes' => 0, 'total' => $nuevoTotal]);
            }

            $nuevoTotalAPagar = max(0, round((float) $relacion->total_a_pagar - (float) $relacion->total_recargos - (float) $relacion->total_interes, 2));

            $relacion->update([
                'estado' => 'perdonada',
                'total_recargos' => 0,
                'total_interes' => 0,
                'total_a_pagar' => $nuevoTotalAPagar,
            ]);

            return $relacion->fresh(['perdon', 'detalles']);
        });
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
}
