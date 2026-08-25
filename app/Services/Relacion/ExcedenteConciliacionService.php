<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\ExcedenteMovimiento;
use App\Models\Relacion;
use App\Models\RelacionDetalle;
use App\Models\Vale;
use App\Services\Configuracion\ConfiguracionService;
use App\Services\Notificacion\NotificacionService;

/**
 * Lleva el saldo a favor que deja un abono bancario mayor a lo que realmente se debía en una
 * cuota (ConciliacionBancariaService::aplicarAbono). Antes ese excedente no se guardaba en
 * ningún lado más que en una notificación opcional -- si nadie le hacía caso, el dinero de más
 * quedaba invisible. Ahora se registra siempre, POR VALE (no por distribuidora): el excedente
 * de un cliente nunca debe terminar pagando la deuda de otro cliente de la misma distribuidora,
 * así que vive en Vale::saldo_excedente y solo se descuenta de las cuotas futuras de ESE mismo
 * vale. Cuando ese vale ya no tiene ninguna cuota futura (ya quedó 'pagado') y aún le sobra
 * saldo, el sistema ya no tiene forma de aplicarlo solo -- ver SolicitudReembolsoExcedente.
 */
final class ExcedenteConciliacionService
{
    private const EPSILON = 0.01;

    public function __construct(
        private readonly ConfiguracionService $configuracionService,
        private readonly NotificacionService $notificacionService,
    ) {}

    /**
     * Registra que un abono con concepto identificado pagó de más la cuota de ese vale
     * específico -- el excedente es inequívocamente de ese vale, sin necesidad de repartir nada.
     */
    public function registrarParaDetalle(Relacion $relacion, RelacionDetalle $detalle): void
    {
        $sobrepago = round((float) $detalle->pago - (float) $detalle->total, 2);

        if ($sobrepago <= self::EPSILON || ! $detalle->vale) {
            return;
        }

        $this->registrar($relacion, $detalle->vale, $sobrepago);
    }

    /**
     * Un abono SIN concepto (o que no se pudo matchear a un detalle) se sigue aplicando al
     * total agregado de la relación, como siempre. Si de ahí resulta un excedente, no hay forma
     * de saber con certeza de cuál vale vino -- si la relación es de un solo vale, es
     * inequívoco; si tiene varios, se reparte proporcional al peso de cada uno en el total del
     * corte (mejor que perderlo o adivinar cuál "gana" completo).
     */
    public function registrarParaRelacion(Relacion $relacion, float $excedenteTotal): void
    {
        if ($excedenteTotal <= self::EPSILON) {
            return;
        }

        $relacion->loadMissing('detalles.vale');
        $detalles = $relacion->detalles;
        $totalRelacion = (float) $relacion->total_a_pagar;

        if ($detalles->isEmpty() || $totalRelacion <= self::EPSILON) {
            return;
        }

        $restante = $excedenteTotal;
        $ultimo = $detalles->count() - 1;

        foreach ($detalles as $indice => $detalle) {
            if (! $detalle->vale) {
                continue;
            }

            // El último se lleva lo que sobre del redondeo, para que la suma repartida cuadre
            // exacto con $excedenteTotal (nunca de más entre todos por acumulación de centavos).
            $porcion = $indice === $ultimo
                ? round($restante, 2)
                : round($excedenteTotal * ((float) $detalle->total / $totalRelacion), 2);

            $restante = round($restante - $porcion, 2);

            if ($porcion > self::EPSILON) {
                $this->registrar($relacion, $detalle->vale, $porcion);
            }
        }
    }

    private function registrar(Relacion $relacion, Vale $vale, float $monto): void
    {
        ExcedenteMovimiento::query()->create([
            'distribuidora_id' => $vale->distribuidora_id,
            'relacion_id' => $relacion->id,
            'vale_id' => $vale->id,
            'tipo' => 'generado',
            'monto' => $monto,
            'motivo' => 'Pago de más en la relación '.$relacion->referencia_pago,
        ]);

        $vale->increment('saldo_excedente', $monto);

        if ($vale->distribuidora?->usuario) {
            $this->notificacionService->crear(
                $vale->distribuidora->usuario,
                'excedente_generado',
                'Vale #'.$vale->id.' (Relación '.$relacion->referencia_pago.') — $'.number_format($monto, 2).' a tu favor'
            );
        }

        $margenTolerancia = (float) ($this->configuracionService->obtenerValorVigente('margen_tolerancia_conciliacion') ?? 0);
        if ($monto > $margenTolerancia) {
            $recurso = 'Vale #'.$vale->id.' (Relación #'.$relacion->id.') — excedente $'.number_format($monto, 2);
            $this->notificacionService->notificarRolEnSucursal('Gerente de Sucursal', $relacion->sucursal_id, 'abono_excedente', $recurso);
            $this->notificacionService->notificarRolEnSucursal('Coordinador', $relacion->sucursal_id, 'abono_excedente', $recurso);
        }
    }

    /**
     * Descuenta el saldo a favor de ESTE vale contra la cuota que se le acaba de generar en un
     * corte nuevo (llamado desde RelacionCalculoService::calcularDetalleVale(), inmediatamente
     * después de crear el RelacionDetalle) -- antes de que la distribuidora o la cajera lo vean.
     *
     * @return float Monto realmente aplicado a esta cuota (0 si el vale no tenía saldo).
     */
    public function aplicarAlVale(RelacionDetalle $detalle, Vale $vale): float
    {
        $saldo = (float) $vale->saldo_excedente;

        if ($saldo <= self::EPSILON) {
            return 0.0;
        }

        $montoAplicado = round(min($saldo, (float) $detalle->total), 2);
        if ($montoAplicado <= self::EPSILON) {
            return 0.0;
        }

        $detalle->pago = $montoAplicado;
        $saldoCuota = (float) $detalle->total - $montoAplicado;
        $detalle->estado = $saldoCuota <= self::EPSILON ? 'pagado' : 'parcial';
        $detalle->save();

        $vale->decrement('saldo_excedente', $montoAplicado);

        ExcedenteMovimiento::query()->create([
            'distribuidora_id' => $vale->distribuidora_id,
            'relacion_id' => $detalle->relacion_id,
            'vale_id' => $vale->id,
            'tipo' => 'aplicado',
            'monto' => -$montoAplicado,
            'motivo' => 'Aplicado automáticamente a la cuota '.$detalle->concepto,
        ]);

        if ($vale->distribuidora?->usuario) {
            $this->notificacionService->crear(
                $vale->distribuidora->usuario,
                'excedente_aplicado',
                'Vale #'.$vale->id.' — $'.number_format($montoAplicado, 2).' de tu saldo a favor aplicado'
            );
        }

        return $montoAplicado;
    }
}
