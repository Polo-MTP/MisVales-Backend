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
     * pasó, le aplica la multa por no pago a sus propias cuotas sin liquidar, y evalúa
     * MOROSIDAD automática para cada distribuidora afectada.
     *
     * La multa vive en la quincena que se atrasó, no en la siguiente: antes,
     * RelacionCalculoService le agregaba el recargo a la cuota NUEVA cuando detectaba que la
     * anterior seguía sin pagar -- eso dejaba a la última quincena de un vale sin ninguna forma
     * de recibir su multa (no hay "cuota siguiente" que la cargue), y mezclaba en un mismo
     * corte cargos que le corresponden a otro. Aquí, en cambio, el vencimiento se detecta y se
     * cobra sobre la relación que de verdad se atrasó, sin depender de que exista una después.
     */
    public function marcarVencidas(?string $fecha = null): int
    {
        $fecha = $fecha ?? now()->toDateString();

        $relacionesVencidas = Relacion::query()
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->whereDate('fecha_limite_pago', '<', $fecha)
            ->with('detalles')
            ->get();

        $multaNoPago = (float) ($this->configuracionService->obtenerValorVigente('multa_no_pago') ?? 300);

        foreach ($relacionesVencidas as $relacion) {
            $this->aplicarMultaPorVencimiento($relacion, $multaNoPago);
            $relacion->estado = 'vencida';
            $relacion->save();
        }

        foreach ($relacionesVencidas->pluck('distribuidora_id')->unique() as $distribuidoraId) {
            $this->evaluarMorosidad((int) $distribuidoraId);
        }

        return $relacionesVencidas->count();
    }

    /**
     * Le agrega la multa (y le quita el descuento de categoría, misma regla de siempre: "con
     * recargo pierde el descuento de esa quincena") a cada cuota de $relacion que sigue sin
     * liquidarse. Idempotente por diseño: una vez que una cuota ya tiene recargo > 0, o ya está
     * 'pagada', se salta -- necesario porque una relación 'vencida' puede recibir un abono
     * parcial después (ConciliacionBancariaService::aplicarAbono() la regresa a 'parcial' si no
     * la liquida del todo), volviendo a calificar para este método en la siguiente corrida sin
     * que eso duplique la multa ya cobrada. Esa misma idempotencia es la que permite que
     * RelacionCalculoService::calcularDetalleVale() también la llame -- de ahí que sea pública:
     * cobra la multa de inmediato al generar el siguiente corte de un vale sin esperar a que
     * marcarVencidas() corra en la noche, sin riesgo de cobrarla dos veces si de todos modos
     * el barrido nocturno se adelanta.
     *
     * No guarda $relacion -- quien llama decide cuándo hacer save() (marcarVencidas() lo hace
     * junto con el cambio de estado a 'vencida'; calcularDetalleVale() no toca ese estado).
     */
    public function aplicarMultaPorVencimiento(Relacion $relacion, float $multaNoPago): void
    {
        $recargoAgregado = 0.0;
        $categoriaPerdida = 0.0;
        $deltaTotal = 0.0;

        foreach ($relacion->detalles as $detalle) {
            $saldoCuota = (float) $detalle->total - (float) $detalle->pago;

            if ($detalle->estado === 'pagado' || (float) $detalle->recargo > 0.0 || $saldoCuota <= 0.01) {
                continue;
            }

            $totalAnterior = (float) $detalle->total;
            $categoriaDeEstaCuota = (float) $detalle->categoria;

            // Se recalcula desde capital+comisión+interés+seguro (sin el descuento de categoría,
            // que se pierde aquí) en vez de sumarle el descuento exacto a un 'total' que ya
            // perdió sus centavos en el ROUNDDOWN al piso de calcularMontosBase() -- ese total
            // YA venía redondeado hacia abajo con el descuento adentro, así que sumarle el
            // descuento completo después no reconstruye el monto real sin descuento, deja
            // faltando hasta $0.99 por cuota (ver vale de $15,000 a 8 quincenas: "perdiendo un
            // peso" en la segunda multa).
            //
            // El piso se aplica hasta sumar TODO (capital+comisión+interés+seguro + multa +
            // arrastre), no antes por separado -- el arrastre llega con sus centavos exactos
            // (nunca se trunca al traerlo), solo se trunca la suma final.
            $sinDescuento = (float) $detalle->capital + (float) $detalle->comision + (float) $detalle->interes + (float) $detalle->seguro;

            $detalle->recargo = $multaNoPago;
            $detalle->categoria = 0.0;
            $detalle->total = floor($sinDescuento + $multaNoPago + (float) $detalle->arrastre);
            $detalle->save();

            $recargoAgregado += $multaNoPago;
            $categoriaPerdida += $categoriaDeEstaCuota;
            $deltaTotal += round((float) $detalle->total - $totalAnterior, 2);
        }

        if ($recargoAgregado > 0.0) {
            $relacion->total_recargos = round((float) $relacion->total_recargos + $recargoAgregado, 2);
            $relacion->total_categoria = round((float) $relacion->total_categoria - $categoriaPerdida, 2);
            // El incremento real de cada detalle (no "multa + categoría perdida" asumido): con
            // el recálculo de arriba, la diferencia real puede no coincidir exacto con esa suma
            // por los mismos centavos que el ROUNDDOWN se había comido -- sumar el delta real
            // evita que total_a_pagar (nivel relación) se desalinee de la suma de sus detalles.
            $relacion->total_a_pagar = round((float) $relacion->total_a_pagar + $deltaTotal, 2);
        }
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
