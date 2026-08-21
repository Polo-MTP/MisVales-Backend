<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\RelacionDetalle;
use App\Models\SeguroTabla;
use App\Models\Vale;
use App\Services\Configuracion\ConfiguracionService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Calcula la relación (corte / estado de cuenta) de una distribuidora, siguiendo la fórmula
 * de "Analisis de calculo de relacion": capital + comisión + interés + seguro (+ recargo si aplica),
 * prorrateados entre las quincenas del vale.
 *
 * Los puntos de fidelidad NO se calculan aquí: solo aplican sobre pagos anticipados, y eso
 * se determina hasta la conciliación bancaria (fuera del alcance de este service).
 */
final class RelacionCalculoService
{
    public function __construct(
        private readonly ConfiguracionService $configuracionService,
    ) {}

    /**
     * Genera el corte del día para todas las distribuidoras activas cuya sucursal tiene
     * hoy su día de corte configurado (o el de la fecha indicada). Las distribuidoras sin
     * vales pendientes se omiten silenciosamente.
     *
     * @return array<int, Relacion> Relaciones generadas, indexadas por distribuidora_id.
     */
    public function generarCortesDelDia(?string $fecha = null): array
    {
        $fechaCorte = $fecha ? Carbon::parse($fecha) : now();
        $generadas = [];

        Distribuidora::query()
            ->where('estado', 'ACTIVO')
            ->with('sucursal', 'categoria')
            ->chunk(50, function ($distribuidoras) use ($fechaCorte, &$generadas): void {
                foreach ($distribuidoras as $distribuidora) {
                    $fechasConfig = $this->configuracionService->obtenerFechasVigentes(
                        $distribuidora->sucursal_id,
                        $fechaCorte->toDateString()
                    );

                    if ((int) $fechasConfig->dia_corte !== (int) $fechaCorte->day) {
                        continue;
                    }

                    $relacion = $this->generarParaDistribuidora($distribuidora, $fechaCorte->toDateString());

                    if ($relacion) {
                        $generadas[$distribuidora->id] = $relacion;
                    }
                }
            });

        return $generadas;
    }

    /**
     * Genera la relación de un corte para una distribuidora. Si no tiene vales con saldo
     * pendiente, no genera nada (retorna null).
     */
    public function generarParaDistribuidora(Distribuidora $distribuidora, ?string $fechaCorte = null): ?Relacion
    {
        $fechaCorte = $fechaCorte ? Carbon::parse($fechaCorte) : now();

        if (Relacion::query()
            ->where('distribuidora_id', $distribuidora->id)
            ->whereDate('fecha_corte', $fechaCorte->toDateString())
            ->exists()
        ) {
            throw new DomainException('Ya existe una relación generada para esta distribuidora en la fecha de corte indicada.');
        }

        $valesPendientes = Vale::query()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('activo', true)
            ->whereIn('estado', ['autorizado', 'parcial', 'vencido'])
            ->get();

        if ($valesPendientes->isEmpty()) {
            return null;
        }

        [$fechaLimitePago, $fechaAnticipadoDesde, $fechaAnticipadoHasta] = $this->calcularFechas(
            $distribuidora->sucursal_id,
            $fechaCorte
        );

        $comisionBasePct = (float) ($this->configuracionService->obtenerValorVigente('comision_base_pct') ?? 10);
        $interesPctQuincena = (float) ($this->configuracionService->obtenerValorVigente('interes_pct_quincena') ?? 5);
        $multaNoPago = (float) ($this->configuracionService->obtenerValorVigente('multa_no_pago') ?? 300);

        return DB::transaction(function () use (
            $distribuidora,
            $fechaCorte,
            $fechaLimitePago,
            $fechaAnticipadoDesde,
            $fechaAnticipadoHasta,
            $valesPendientes,
            $comisionBasePct,
            $interesPctQuincena,
            $multaNoPago,
        ): Relacion {
            /** @var Relacion $relacion */
            $relacion = Relacion::query()->create([
                'distribuidora_id' => $distribuidora->id,
                'sucursal_id' => $distribuidora->sucursal_id,
                'referencia_pago' => $this->construirReferenciaPago($distribuidora, $fechaCorte),
                'fecha_corte' => $fechaCorte->toDateString(),
                'fecha_limite_pago' => $fechaLimitePago->toDateString(),
                'fecha_pago_anticipado_desde' => $fechaAnticipadoDesde->toDateString(),
                'fecha_pago_anticipado_hasta' => $fechaAnticipadoHasta->toDateString(),
                'limite_credito_snapshot' => $distribuidora->limite_credito,
                'categoria_id_snapshot' => $distribuidora->categoria_id,
                'porcentaje_comision_snapshot' => $distribuidora->categoria?->porcentaje_comision,
                'estado' => 'pendiente',
            ]);

            $totales = [
                'capital' => 0.0, 'comision' => 0.0, 'interes' => 0.0, 'seguro' => 0.0, 'categoria' => 0.0, 'recargo' => 0.0, 'total' => 0.0,
            ];

            foreach ($valesPendientes as $vale) {
                $detalle = $this->calcularDetalleVale($relacion, $vale, $comisionBasePct, $interesPctQuincena, $multaNoPago);

                $totales['capital'] += (float) $detalle->capital;
                $totales['comision'] += (float) $detalle->comision;
                $totales['interes'] += (float) $detalle->interes;
                $totales['seguro'] += (float) $detalle->seguro;
                $totales['categoria'] += (float) $detalle->categoria;
                $totales['recargo'] += (float) $detalle->recargo;
                $totales['total'] += (float) $detalle->total;
            }

            $relacion->update([
                'total_capital' => $totales['capital'],
                'total_comision' => $totales['comision'],
                'total_interes' => $totales['interes'],
                'total_seguro' => $totales['seguro'],
                'total_categoria' => $totales['categoria'],
                'total_recargos' => $totales['recargo'],
                'total_a_pagar' => $totales['total'],
            ]);

            return $relacion->fresh(['detalles.vale', 'detalles.cliente', 'detalles.producto', 'categoriaSnapshot']);
        });
    }

    private function calcularDetalleVale(
        Relacion $relacion,
        Vale $vale,
        float $comisionBasePct,
        float $interesPctQuincena,
        float $multaNoPago,
    ): RelacionDetalle {
        $quincenas = max(1, (int) ($vale->quincenas ?? $vale->producto?->quincenas ?? 1));
        $monto = (float) $vale->monto;

        $cuotaAnterior = RelacionDetalle::query()
            ->where('vale_id', $vale->id)
            ->latest('id')
            ->first();

        $cuotaNumero = $cuotaAnterior ? $cuotaAnterior->cuota_numero + 1 : 1;

        // Recargo: si la cuota anterior de este mismo vale no quedó liquidada, se suma la multa configurada.
        $recargo = ($cuotaAnterior && $cuotaAnterior->estado !== 'pagado') ? $multaNoPago : 0.0;

        $capital = round($monto / $quincenas, 2);
        $comision = round(($monto * $comisionBasePct / 100) / $quincenas, 2);
        // Interés simple sobre el total del plazo, prorrateado entre quincenas (ver "Analisis de calculo de relacion").
        $interes = round(($monto * $interesPctQuincena / 100 * $quincenas) / $quincenas, 2);
        $seguro = round($this->calcularSeguro($monto) / $quincenas, 2);

        // Ganancia de la distribuidora por su categoría (Cobre/Plata/Oro), snapshot al generar el corte.
        // Solo se le reconoce cuando paga a tiempo — con recargo pierde el descuento de esta quincena
        // ("se le quita la comisión por regla") y paga el monto completo sin categoría de por medio.
        $porcentajeCategoria = (float) ($relacion->porcentaje_comision_snapshot ?? 0);
        $categoria = round(($monto * $porcentajeCategoria / 100) / $quincenas, 2);

        if ($recargo > 0.0) {
            $categoria = 0.0;
            $total = round($capital + $comision + $interes + $seguro + $recargo, 2);
        } else {
            // ROUNDDOWN al piso (no round()) tal como el documento fuente calcula el "Pago Distribuidora".
            $total = floor($capital + $comision + $interes + $seguro - $categoria);
        }

        return RelacionDetalle::query()->create([
            'relacion_id' => $relacion->id,
            'vale_id' => $vale->id,
            'cliente_id' => $vale->cliente_id,
            'producto_id' => $vale->producto_id,
            'cuota_numero' => $cuotaNumero,
            'cuotas_totales' => $quincenas,
            'capital' => $capital,
            'comision' => $comision,
            'interes' => $interes,
            'seguro' => $seguro,
            'categoria' => $categoria,
            'recargo' => $recargo,
            'pago' => 0,
            'total' => $total,
            'estado' => 'pendiente',
        ]);
    }

    /**
     * Busca el costo de seguro configurado para el monto del vale (tabla de rangos, "varia segun la cantidad").
     */
    private function calcularSeguro(float $monto): float
    {
        $rango = SeguroTabla::query()->activo()->paraMonto($monto)->orderByDesc('monto_desde')->first();

        return $rango ? (float) $rango->seguro_monto : 0.0;
    }

    /**
     * Referencia única por distribuidora+corte (formato: 9 dígitos de distribuidora + 9 dígitos de fecha de corte).
     */
    private function construirReferenciaPago(Distribuidora $distribuidora, CarbonInterface $fechaCorte): string
    {
        return sprintf('%09d%09d', $distribuidora->id, (int) $fechaCorte->format('Ymd'));
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface, 2: CarbonInterface} [fechaLimitePago, anticipadoDesde, anticipadoHasta]
     */
    private function calcularFechas(?int $sucursalId, CarbonInterface $fechaCorte): array
    {
        $fechasConfig = $this->configuracionService->obtenerFechasVigentes($sucursalId, $fechaCorte->toDateString());

        $fechaLimitePago = $fechaCorte->copy()->day(min($fechasConfig->dia_limite_pago, $fechaCorte->daysInMonth));

        if ($fechaLimitePago->lte($fechaCorte)) {
            $fechaLimitePago = $fechaLimitePago->addMonthNoOverflow();
        }

        $fechaAnticipadoHasta = $fechaLimitePago->copy()->subDay();
        $fechaAnticipadoDesde = $fechaLimitePago->copy()->subDays($fechasConfig->dias_pago_anticipado);

        return [$fechaLimitePago, $fechaAnticipadoDesde, $fechaAnticipadoHasta];
    }
}
