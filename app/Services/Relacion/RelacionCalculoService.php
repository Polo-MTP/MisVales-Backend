<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\ConfiguracionFechas;
use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\RelacionDetalle;
use App\Models\SeguroTabla;
use App\Models\Vale;
use App\Services\Configuracion\ConfiguracionService;
use App\Services\Notificacion\NotificacionService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calcula la relación (corte / estado de cuenta) de una distribuidora, siguiendo la fórmula
 * de "Analisis de calculo de relacion": capital + comisión + interés + seguro (+ recargo si aplica),
 * prorrateados entre las quincenas del vale.
 *
 * El corte corre 2 veces al mes (quincenal): en dia_corte y dia_corte_2, configurables por
 * sucursal en ConfiguracionFechas y siempre en pareja (ver esDiaDeCorte()). Cada corte cobra
 * una cuota ("quincena") de cada vale pendiente, así que un vale a N quincenas tarda N/2
 * meses en liquidarse.
 *
 * Los puntos de fidelidad NO se calculan aquí: solo aplican sobre pagos anticipados, y eso
 * se determina hasta la conciliación bancaria (fuera del alcance de este service).
 */
final class RelacionCalculoService
{
    public function __construct(
        private readonly ConfiguracionService $configuracionService,
        private readonly NotificacionService $notificacionService,
    ) {}

    /**
     * Genera el corte del día para todas las distribuidoras activas cuya sucursal tiene hoy
     * (o la fecha indicada) uno de sus dos días de corte quincenales configurados. Las
     * distribuidoras sin vales pendientes se omiten silenciosamente.
     *
     * Cada distribuidora se procesa aislada: si una truena (ej. ya existe relación para esa
     * fecha), las demás siguen generándose normal — antes, una sola excepción dentro del
     * chunk() detenía TODO el resto del lote sin avisar cuántas sí se alcanzaron a generar.
     *
     * @return array{generadas: array<int, Relacion>, errores: array<int, string>} Relaciones
     *                                                                             generadas indexadas por distribuidora_id, y mensajes de error por distribuidora
     *                                                                             que falló (indexados igual).
     */
    public function generarCortesDelDia(?string $fecha = null): array
    {
        $fechaCorte = $fecha ? Carbon::parse($fecha) : now();
        $generadas = [];
        $errores = [];

        Distribuidora::query()
            ->where('estado', 'ACTIVO')
            ->with('sucursal', 'categoria')
            ->chunk(50, function ($distribuidoras) use ($fechaCorte, &$generadas, &$errores): void {
                foreach ($distribuidoras as $distribuidora) {
                    try {
                        $fechasConfig = $this->configuracionService->obtenerFechasVigentes(
                            $distribuidora->sucursal_id,
                            $fechaCorte->toDateString()
                        );

                        if (! $this->esDiaDeCorte($fechasConfig, $fechaCorte)) {
                            continue;
                        }

                        $relacion = $this->generarParaDistribuidora($distribuidora, $fechaCorte->toDateString());

                        if ($relacion) {
                            $generadas[$distribuidora->id] = $relacion;
                        }
                    } catch (Throwable $e) {
                        Log::error('generarCortesDelDia: fallo al generar el corte de una distribuidora, se continúa con las demás', [
                            'distribuidora_id' => $distribuidora->id,
                            'fecha_corte' => $fechaCorte->toDateString(),
                            'error' => $e->getMessage(),
                        ]);
                        $errores[$distribuidora->id] = $e->getMessage();
                    }
                }
            });

        return ['generadas' => $generadas, 'errores' => $errores];
    }

    /**
     * Traduce dia_corte/dia_corte_2 (día del mes 1-31, puede exceder los días reales del mes)
     * a un día real para $fecha: se capa al último día calendario, mismo criterio que ya usa
     * dia_limite_pago (ver calcularFechas()) -- así 31 funciona como "fin de mes" incluso en
     * meses de 28-30 días.
     */
    private function diaDeCorteCapeado(int $diaConfigurado, CarbonInterface $fecha): int
    {
        return min($diaConfigurado, $fecha->daysInMonth);
    }

    /**
     * Es día de corte si coincide con dia_corte o dia_corte_2 (ya capados al mes de $fecha).
     */
    private function esDiaDeCorte(ConfiguracionFechas $fechasConfig, CarbonInterface $fecha): bool
    {
        return $fecha->day === $this->diaDeCorteCapeado((int) $fechasConfig->dia_corte, $fecha)
            || $fecha->day === $this->diaDeCorteCapeado((int) $fechasConfig->dia_corte_2, $fecha);
    }

    /**
     * Próximo día de corte a partir de $hoy, inclusive -- si hoy mismo es uno de los dos días
     * de corte, ese es el "próximo" (ver ProximoPagoTest: "el mismo día del corte cuenta como
     * todavía no pasó").
     */
    private function proximaFechaDeCorte(ConfiguracionFechas $fechasConfig, CarbonInterface $hoy): CarbonInterface
    {
        $fechasEsteMes = [
            $hoy->copy()->day($this->diaDeCorteCapeado((int) $fechasConfig->dia_corte, $hoy)),
            $hoy->copy()->day($this->diaDeCorteCapeado((int) $fechasConfig->dia_corte_2, $hoy)),
        ];
        usort($fechasEsteMes, static fn (CarbonInterface $a, CarbonInterface $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        foreach ($fechasEsteMes as $fecha) {
            if ($fecha->gte($hoy)) {
                return $fecha;
            }
        }

        $siguienteMes = $hoy->copy()->addMonthNoOverflow()->startOfMonth();
        $fechasSiguienteMes = [
            $siguienteMes->copy()->day($this->diaDeCorteCapeado((int) $fechasConfig->dia_corte, $siguienteMes)),
            $siguienteMes->copy()->day($this->diaDeCorteCapeado((int) $fechasConfig->dia_corte_2, $siguienteMes)),
        ];
        usort($fechasSiguienteMes, static fn (CarbonInterface $a, CarbonInterface $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $fechasSiguienteMes[0];
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
            // Vuelve a bloquear y revisar aquí adentro: el exists() de arriba corrió sin lock,
            // así que dos llamadas casi simultáneas (un reintento manual que choca con el job
            // programado, por ejemplo) podrían pasarlo ambas antes de que cualquiera inserte su
            // Relacion. lockForUpdate() serializa esa ventana -- la segunda, tras esperar el
            // commit de la primera, ve la relación recién creada y recibe el mismo
            // DomainException amigable en vez de un QueryException crudo por el unique constraint.
            Distribuidora::query()->whereKey($distribuidora->id)->lockForUpdate()->first();

            if (Relacion::query()
                ->where('distribuidora_id', $distribuidora->id)
                ->whereDate('fecha_corte', $fechaCorte->toDateString())
                ->exists()
            ) {
                throw new DomainException('Ya existe una relación generada para esta distribuidora en la fecha de corte indicada.');
            }

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

            $relacion = $relacion->fresh(['detalles.vale', 'detalles.cliente', 'detalles.producto', 'categoriaSnapshot']);

            if ($distribuidora->usuario) {
                $this->notificacionService->crear(
                    $distribuidora->usuario,
                    'corte_listo',
                    'Relación '.$relacion->referencia_pago
                );
            }

            return $relacion;
        });
    }

    /**
     * Previsualiza el pago quincenal estimado para un monto/plazo dados, ANTES de que exista
     * un vale o se genere ningún corte — mismas reglas vigentes ahora mismo que usaría un
     * corte real. Es un estimado "si paga puntual": no incluye recargo (depende de un
     * comportamiento futuro que todavía no pasa) y usa la configuración vigente EN ESTE
     * MOMENTO, que puede cambiar antes de que el corte real se genere.
     *
     * @return array{capital: float, comision: float, interes: float, seguro: float, categoria: float, pago_quincenal: float, total_estimado_plazo: float}
     */
    public function simularPagoQuincenal(float $monto, int $quincenas, ?Distribuidora $distribuidora = null): array
    {
        $comisionBasePct = (float) ($this->configuracionService->obtenerValorVigente('comision_base_pct') ?? 10);
        $interesPctQuincena = (float) ($this->configuracionService->obtenerValorVigente('interes_pct_quincena') ?? 5);
        $porcentajeCategoria = (float) ($distribuidora?->categoria?->porcentaje_comision ?? 0);

        $base = $this->calcularMontosBase($monto, max(1, $quincenas), $comisionBasePct, $interesPctQuincena, $porcentajeCategoria);

        return [
            ...$base,
            'total_estimado_plazo' => round($base['pago_quincenal'] * max(1, $quincenas), 2),
        ];
    }

    /**
     * Cuándo será el próximo corte de esta distribuidora y cuánto se estima que le va a
     * tocar pagar — SIN generar ni persistir nada. Suma, para cada vale activo/pendiente
     * (mismo criterio que generarParaDistribuidora: autorizado/parcial/vencido), el pago
     * estimado de su siguiente cuota. Es un estimado "si paga puntual" — no incluye recargo,
     * eso depende de un comportamiento futuro que todavía no pasa.
     *
     * @return array{fecha_corte: string, fecha_limite_pago: string, referencia_pago: string|null, monto_estimado: float, vales: array<int, array{vale_id: int, monto: float, pago_estimado: float, concepto: string}>}
     */
    public function proximoPago(Distribuidora $distribuidora, ?string $desde = null): array
    {
        $hoy = $desde ? Carbon::parse($desde)->startOfDay() : now()->startOfDay();

        $fechasConfig = $this->configuracionService->obtenerFechasVigentes($distribuidora->sucursal_id, $hoy->toDateString());
        $proximaFechaCorte = $this->proximaFechaDeCorte($fechasConfig, $hoy);

        [$fechaLimitePago] = $this->calcularFechas($distribuidora->sucursal_id, $proximaFechaCorte);

        $comisionBasePct = (float) ($this->configuracionService->obtenerValorVigente('comision_base_pct') ?? 10);
        $interesPctQuincena = (float) ($this->configuracionService->obtenerValorVigente('interes_pct_quincena') ?? 5);
        $porcentajeCategoria = (float) ($distribuidora->categoria?->porcentaje_comision ?? 0);

        $vales = [];
        $montoEstimado = 0.0;

        $valesPendientes = Vale::query()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('activo', true)
            ->whereIn('estado', ['autorizado', 'parcial', 'vencido'])
            ->get();

        foreach ($valesPendientes as $vale) {
            $quincenas = max(1, (int) ($vale->quincenas ?? $vale->producto?->quincenas ?? 1));
            $base = $this->calcularMontosBase((float) $vale->monto, $quincenas, $comisionBasePct, $interesPctQuincena, $porcentajeCategoria);

            $cuotaAnterior = RelacionDetalle::query()->where('vale_id', $vale->id)->latest('id')->first();
            $cuotaNumero = $cuotaAnterior ? $cuotaAnterior->cuota_numero + 1 : 1;

            $vales[] = [
                'vale_id' => $vale->id,
                'monto' => (float) $vale->monto,
                'pago_estimado' => $base['pago_quincenal'],
                // Lo que la distribuidora debe poner en "Concepto" si paga este vale por
                // separado dentro de un corte con más de uno -- ver construirConceptoVale().
                'concepto' => $this->construirConceptoVale($vale->id, $cuotaNumero),
            ];
            $montoEstimado += $base['pago_quincenal'];
        }

        return [
            'fecha_corte' => $proximaFechaCorte->toDateString(),
            'fecha_limite_pago' => $fechaLimitePago->toDateString(),
            // La referencia es una fórmula pura (distribuidora_id + fecha_corte, ver
            // construirReferenciaPago()), no depende de que el corte ya exista como registro --
            // se puede calcular y mostrar desde antes para que la distribuidora prepare su
            // transferencia con la referencia correcta sin tener que esperar al corte real. PERO
            // solo si ya hay algo autorizado que la respalde: sin vales autorizados/parciales/
            // vencidos no hay nada que vaya a cobrarse en ese corte, y mostrar una referencia sin
            // nada detrás es engañoso (lo único que existe en ese caso son solicitudes que la
            // distribuidora todavía puede cancelar).
            'referencia_pago' => empty($vales) ? null : $this->construirReferenciaPago($distribuidora, $proximaFechaCorte),
            'monto_estimado' => round($montoEstimado, 2),
            'vales' => $vales,
        ];
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

        // Ganancia de la distribuidora por su categoría (Cobre/Plata/Oro), snapshot al generar el corte.
        $porcentajeCategoria = (float) ($relacion->porcentaje_comision_snapshot ?? 0);
        $base = $this->calcularMontosBase($monto, $quincenas, $comisionBasePct, $interesPctQuincena, $porcentajeCategoria);

        if ($recargo > 0.0) {
            // Con recargo pierde el descuento de esta quincena ("se le quita la comisión por
            // regla") y paga el monto completo sin categoría de por medio.
            $categoria = 0.0;
            $total = round($base['capital'] + $base['comision'] + $base['interes'] + $base['seguro'] + $recargo, 2);
        } else {
            $categoria = $base['categoria'];
            $total = $base['pago_quincenal'];
        }

        return RelacionDetalle::query()->create([
            'relacion_id' => $relacion->id,
            'vale_id' => $vale->id,
            'concepto' => $this->construirConceptoVale($vale->id, $cuotaNumero),
            'cliente_id' => $vale->cliente_id,
            'producto_id' => $vale->producto_id,
            'cuota_numero' => $cuotaNumero,
            'cuotas_totales' => $quincenas,
            'capital' => $base['capital'],
            'comision' => $base['comision'],
            'interes' => $base['interes'],
            'seguro' => $base['seguro'],
            'categoria' => $categoria,
            'recargo' => $recargo,
            'pago' => 0,
            'total' => $total,
            'estado' => 'pendiente',
        ]);
    }

    /**
     * Cálculo puro (sin recargo, sin tocar la base de datos) del pago quincenal "limpio":
     * capital + comisión + interés + seguro, menos el descuento de categoría, redondeado al
     * piso. Es la misma fórmula que calcularDetalleVale() aplica cuando no hay recargo — la
     * comparten el cálculo real del corte y simularPagoQuincenal().
     *
     * @return array{capital: float, comision: float, interes: float, seguro: float, categoria: float, pago_quincenal: float}
     */
    private function calcularMontosBase(float $monto, int $quincenas, float $comisionBasePct, float $interesPctQuincena, float $porcentajeCategoria): array
    {
        $quincenas = max(1, $quincenas);

        $capital = round($monto / $quincenas, 2);
        $comision = round(($monto * $comisionBasePct / 100) / $quincenas, 2);
        // Interés simple sobre el total del plazo, prorrateado entre quincenas (ver "Analisis de calculo de relacion").
        $interes = round($monto * $interesPctQuincena / 100, 2);
        $seguro = round($this->calcularSeguro($monto) / $quincenas, 2);
        $categoria = round(($monto * $porcentajeCategoria / 100) / $quincenas, 2);

        // ROUNDDOWN al piso (no round()) tal como el documento fuente calcula el "Pago Distribuidora".
        $pagoQuincenal = floor($capital + $comision + $interes + $seguro - $categoria);

        return [
            'capital' => $capital,
            'comision' => $comision,
            'interes' => $interes,
            'seguro' => $seguro,
            'categoria' => $categoria,
            'pago_quincenal' => $pagoQuincenal,
        ];
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
     * Identificador único por vale/cuota (formato: 5 dígitos de vale_id + 4 de cuota_numero).
     * A diferencia de referencia_pago (por distribuidora+corte, compartida si el corte junta
     * varios vales), esto es lo que distingue, DENTRO de un mismo corte, a cuál vale
     * corresponde cada abono -- la distribuidora lo pone en el campo "Concepto" de su
     * transferencia cuando paga cada vale por separado (ver ConciliacionBancariaService).
     */
    private function construirConceptoVale(int $valeId, int $cuotaNumero): string
    {
        return sprintf('%05d%04d', $valeId, $cuotaNumero);
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
