<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\PuntoMovimiento;
use App\Models\Relacion;
use App\Models\Vale;
use App\Services\Configuracion\ConfiguracionService;
use App\Services\Notificacion\NotificacionService;
use Carbon\Carbon;

/**
 * Efectos secundarios de que una Relacion quede 'liquidada', sin importar qué la liquidó --
 * un abono bancario conciliado (ConciliacionBancariaService) o el saldo a favor de un
 * excedente anterior aplicado automáticamente al generar el corte
 * (ExcedenteConciliacionService). Antes vivía solo dentro de ConciliacionBancariaService;
 * se extrajo aquí porque ahora hay dos caminos que pueden liquidar una relación y ambos
 * necesitan exactamente el mismo resultado (puntos/penalización + vales marcados pagados).
 */
final class RelacionLiquidacionService
{
    public function __construct(
        private readonly ConfiguracionService $configuracionService,
        private readonly NotificacionService $notificacionService,
    ) {}

    /**
     * Si la relación quedó 'liquidada', dispara el cálculo de puntos (pago anticipado) o la
     * penalización (pago fuera de tiempo) y marca cuotas/vale como pagados. Si no está
     * liquidada, no hace nada -- seguro de llamar siempre tras cualquier cambio de estado.
     */
    public function procesarLiquidacion(Relacion $relacion, Carbon $fechaAbono): void
    {
        if ($relacion->estado !== 'liquidada') {
            return;
        }

        $this->procesarPuntos($relacion, $fechaAbono);
        $this->marcarValesPagados($relacion);
    }

    /**
     * Puntos solo en pago anticipado; penalización del 20% si fue fuera de tiempo. Pago puntual: sin efecto.
     */
    private function procesarPuntos(Relacion $relacion, Carbon $fechaAbono): void
    {
        if (PuntoMovimiento::query()->where('relacion_id', $relacion->id)->exists()) {
            return; // Ya se procesó (evita doble conteo si llegan varios abonos tras liquidar).
        }

        $esAnticipado = $relacion->fecha_pago_anticipado_desde
            && $relacion->fecha_pago_anticipado_hasta
            && $fechaAbono->betweenIncluded($relacion->fecha_pago_anticipado_desde, $relacion->fecha_pago_anticipado_hasta);

        $esTardio = $fechaAbono->greaterThan($relacion->fecha_limite_pago);

        if ($esAnticipado) {
            $this->generarPuntos($relacion);
        } elseif ($esTardio) {
            $this->penalizarPuntos($relacion);
        }
    }

    /**
     * Otorga puntos de fidelidad por pago anticipado, calculados sobre el total de productos
     * otorgados en el corte según los divisores/multiplicadores configurables.
     */
    private function generarPuntos(Relacion $relacion): void
    {
        $divisor = (float) ($this->configuracionService->obtenerValorVigente('puntos_divisor') ?? 1200);
        $multiplicador = (float) ($this->configuracionService->obtenerValorVigente('puntos_multiplicador') ?? 3);

        $totalOtorgado = $this->totalProductosOtorgadosEnCorte($relacion);
        $puntos = (int) (floor($totalOtorgado / max($divisor, 1)) * $multiplicador);

        if ($puntos <= 0) {
            return;
        }

        PuntoMovimiento::query()->create([
            'distribuidora_id' => $relacion->distribuidora_id,
            'relacion_id' => $relacion->id,
            'tipo' => 'generado',
            'cantidad' => $puntos,
            'motivo' => 'Pago anticipado de la relación '.$relacion->referencia_pago,
        ]);

        $relacion->distribuidora?->increment('puntos_acumulados', $puntos);
        $relacion->update(['puntos_generados' => $puntos]);

        if ($relacion->distribuidora?->usuario) {
            $this->notificacionService->crear(
                $relacion->distribuidora->usuario,
                'puntos_generados',
                'Relación '.$relacion->referencia_pago.' (+'.$puntos.' pts)'
            );
        }
    }

    /**
     * Descuenta un porcentaje configurable de los puntos acumulados por pago fuera de tiempo.
     */
    private function penalizarPuntos(Relacion $relacion): void
    {
        $pct = (float) ($this->configuracionService->obtenerValorVigente('puntos_penalizacion_pct') ?? 20);
        $puntosActuales = (int) ($relacion->distribuidora?->puntos_acumulados ?? 0);
        $penalizacion = (int) floor($puntosActuales * $pct / 100);

        if ($penalizacion <= 0) {
            return;
        }

        PuntoMovimiento::query()->create([
            'distribuidora_id' => $relacion->distribuidora_id,
            'relacion_id' => $relacion->id,
            'tipo' => 'penalizado',
            'cantidad' => -$penalizacion,
            'motivo' => 'Pago fuera de tiempo de la relación '.$relacion->referencia_pago,
        ]);

        $relacion->distribuidora?->decrement('puntos_acumulados', $penalizacion);

        if ($relacion->distribuidora?->usuario) {
            $this->notificacionService->crear(
                $relacion->distribuidora->usuario,
                'puntos_penalizados',
                'Relación '.$relacion->referencia_pago.' (-'.$penalizacion.' pts)'
            );
        }
    }

    /**
     * "Total de productos otorgados en el corte": suma de los vales autorizados a esta distribuidora
     * entre el corte anterior y el corte actual. Supuesto a confirmar (ver documento fuente, ambiguo).
     */
    private function totalProductosOtorgadosEnCorte(Relacion $relacion): float
    {
        $corteAnterior = Relacion::query()
            ->where('distribuidora_id', $relacion->distribuidora_id)
            ->where('id', '!=', $relacion->id)
            ->where('fecha_corte', '<', $relacion->fecha_corte)
            ->latest('fecha_corte')
            ->first();

        $desde = $corteAnterior?->fecha_corte ?? $relacion->distribuidora?->created_at;

        return (float) Vale::query()
            ->where('distribuidora_id', $relacion->distribuidora_id)
            ->when($desde, fn ($q) => $q->where('fecha_autorizacion', '>=', $desde))
            ->where('fecha_autorizacion', '<=', $relacion->fecha_corte)
            ->sum('monto');
    }

    /**
     * Al liquidarse el corte, cada cuota (RelacionDetalle) de ese corte queda 'pagado' — sin
     * esto, RelacionCalculoService::calcularDetalleVale() nunca encuentra una cuota anterior en
     * 'pagado' (se queda en 'pendiente' para siempre) y le cobra el recargo por atraso a TODO
     * corte a partir del segundo, así se haya pagado puntual.
     *
     * 'arrastrada' queda fuera por completo: esa cuota ya no representa una deuda propia (su
     * saldo se movió a la cuota que la absorbió, ver RelacionCalculoService::calcularDetalleVale())
     * -- forzarla a 'pagado' aquí mentiría (nunca se le pagó nada a ELLA, pago sigue en 0) y le
     * quitaría su estado histórico real. Solo puede haber quedado 'arrastrada' si esta misma
     * Relacion junta más de un vale y otro sí se liquidó normal.
     *
     * Un vale además queda 'pagado' hasta que se liquida su última cuota (cuota_numero ===
     * cuotas_totales); si nació como 'pre-vale' (primer vale de un cliente nuevo), se convierte
     * en 'vale-digital' en ese mismo momento.
     */
    private function marcarValesPagados(Relacion $relacion): void
    {
        $relacion->loadMissing('detalles.vale');

        foreach ($relacion->detalles as $detalle) {
            if ($detalle->estado === 'arrastrada') {
                continue;
            }

            if ($detalle->estado !== 'pagado') {
                $detalle->update(['estado' => 'pagado']);
            }

            if ($detalle->cuota_numero !== $detalle->cuotas_totales) {
                continue;
            }

            $vale = $detalle->vale;
            if (! $vale || $vale->estado === 'pagado') {
                continue;
            }

            $vale->estado = 'pagado';
            if ($vale->tipo === 'pre-vale') {
                $vale->tipo = 'vale-digital';
            }
            $vale->save();
        }
    }
}
