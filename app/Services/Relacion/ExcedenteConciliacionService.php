<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\ExcedenteMovimiento;
use App\Models\Relacion;
use App\Services\Configuracion\ConfiguracionService;
use App\Services\Notificacion\NotificacionService;
use Carbon\Carbon;

/**
 * Lleva el saldo a favor que deja un abono bancario mayor a lo que realmente se debía en esa
 * relación (ConciliacionBancariaService::aplicarAbono). Antes ese excedente no se guardaba en
 * ningún lado más que en una notificación opcional -- si nadie le hacía caso, el dinero de más
 * quedaba invisible. Ahora se registra siempre (distribuidora.saldo_excedente + un
 * excedente_movimientos por auditoría) y se descuenta solo del siguiente corte que se genere
 * para esa misma distribuidora, sin que nadie tenga que aplicarlo a mano.
 */
final class ExcedenteConciliacionService
{
    private const EPSILON = 0.01;

    public function __construct(
        private readonly ConfiguracionService $configuracionService,
        private readonly NotificacionService $notificacionService,
        private readonly RelacionLiquidacionService $relacionLiquidacionService,
    ) {}

    /**
     * Registra que esta relación quedó con $monto pagado de más: suma al saldo a favor de la
     * distribuidora y deja el rastro en excedente_movimientos. Si el excedente supera el
     * margen de tolerancia configurado, además avisa a Gerente de Sucursal y Coordinador --
     * ese margen ya no decide si algo "cuenta" como excedente (todo excedente real se
     * registra), solo si amerita una notificación por ser inusualmente grande.
     */
    public function registrar(Relacion $relacion, float $monto): void
    {
        if ($monto <= self::EPSILON) {
            return;
        }

        $distribuidora = $relacion->distribuidora;
        if (! $distribuidora) {
            return;
        }

        ExcedenteMovimiento::query()->create([
            'distribuidora_id' => $distribuidora->id,
            'relacion_id' => $relacion->id,
            'tipo' => 'generado',
            'monto' => $monto,
            'motivo' => 'Pago de más en la relación '.$relacion->referencia_pago,
        ]);

        $distribuidora->increment('saldo_excedente', $monto);

        if ($distribuidora->usuario) {
            $this->notificacionService->crear(
                $distribuidora->usuario,
                'excedente_generado',
                'Relación '.$relacion->referencia_pago.' — $'.number_format($monto, 2).' a tu favor'
            );
        }

        $margenTolerancia = (float) ($this->configuracionService->obtenerValorVigente('margen_tolerancia_conciliacion') ?? 0);
        if ($monto > $margenTolerancia) {
            $recurso = 'Relación #'.$relacion->id.' — excedente $'.number_format($monto, 2);
            $this->notificacionService->notificarRolEnSucursal('Gerente de Sucursal', $relacion->sucursal_id, 'abono_excedente', $recurso);
            $this->notificacionService->notificarRolEnSucursal('Coordinador', $relacion->sucursal_id, 'abono_excedente', $recurso);
        }
    }

    /**
     * Descuenta del saldo a favor de la distribuidora, contra el total a pagar de un corte
     * RECIÉN CREADO (antes de que la distribuidora o la cajera lo vean) -- se llama desde
     * RelacionCalculoService::generarParaDistribuidora(). Si el saldo alcanza para liquidar el
     * corte completo, dispara los mismos efectos que un abono bancario liquidándolo (puntos/
     * penalización, vales marcados pagados).
     *
     * @return float Monto realmente aplicado (0 si no había saldo o el corte no tenía nada que pagar).
     */
    public function aplicarAlNuevoCorte(Relacion $relacion, Carbon $fechaCorte): float
    {
        $distribuidora = $relacion->distribuidora;
        $saldo = (float) ($distribuidora?->saldo_excedente ?? 0);

        if ($saldo <= self::EPSILON) {
            return 0.0;
        }

        $montoAplicado = round(min($saldo, (float) $relacion->total_a_pagar), 2);
        if ($montoAplicado <= self::EPSILON) {
            return 0.0;
        }

        $relacion->total_abonado = (float) $relacion->total_abonado + $montoAplicado;
        $saldoPendiente = (float) $relacion->total_a_pagar - (float) $relacion->total_abonado;
        $relacion->estado = $saldoPendiente <= self::EPSILON ? 'liquidada' : 'parcial';
        $relacion->save();

        ExcedenteMovimiento::query()->create([
            'distribuidora_id' => $distribuidora->id,
            'relacion_id' => $relacion->id,
            'tipo' => 'aplicado',
            'monto' => -$montoAplicado,
            'motivo' => 'Aplicado automáticamente al corte '.$relacion->referencia_pago,
        ]);

        $distribuidora->decrement('saldo_excedente', $montoAplicado);

        if ($distribuidora->usuario) {
            $this->notificacionService->crear(
                $distribuidora->usuario,
                'excedente_aplicado',
                'Relación '.$relacion->referencia_pago.' — $'.number_format($montoAplicado, 2).' de tu saldo a favor aplicado'
            );
        }

        $this->relacionLiquidacionService->procesarLiquidacion($relacion, $fechaCorte);

        return $montoAplicado;
    }
}
