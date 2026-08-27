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
 * Los puntos de fidelidad NO se calculan aquí como regla general: solo aplican sobre pagos
 * anticipados, y eso se determina hasta la conciliación bancaria (fuera del alcance de este
 * service) -- salvo el caso borde en que el saldo a favor de un excedente anterior alcance para
 * liquidar el corte completo en el momento de generarlo (ver ExcedenteConciliacionService).
 */
final class RelacionCalculoService
{
    public function __construct(
        private readonly ConfiguracionService $configuracionService,
        private readonly NotificacionService $notificacionService,
        private readonly ExcedenteConciliacionService $excedenteConciliacionService,
        private readonly RelacionLiquidacionService $relacionLiquidacionService,
    ) {}

    /**
     * Genera el corte del día para todas las distribuidoras activas cuya sucursal tiene hoy
     * (o la fecha indicada) uno de sus dos días de corte quincenales configurados. Las
     * distribuidoras sin vales pendientes se omiten silenciosamente.
     *
     * $forzar=true se salta esa restricción de día -- lo usa el disparo manual (botón "Generar
     * Cortes del Día"): el gerente decide generar en el momento, sin importar si hoy coincide
     * con el día de corte configurado. El job automático programado (GenerarCortesRelaciones,
     * corre solo a la 1am) sigue llamando esto con $forzar=false para respetar el calendario
     * quincenal real.
     *
     * Cada distribuidora se procesa aislada: si una truena (ej. ya existe relación para esa
     * fecha), las demás siguen generándose normal — antes, una sola excepción dentro del
     * chunk() detenía TODO el resto del lote sin avisar cuántas sí se alcanzaron a generar.
     *
     * @return array{generadas: array<int, Relacion>, errores: array<int, string>} Relaciones
     *                                                                             generadas indexadas por distribuidora_id, y mensajes de error por distribuidora
     *                                                                             que falló (indexados igual).
     */
    public function generarCortesDelDia(?string $fecha = null, bool $forzar = false): array
    {
        $fechaCorte = $fecha ? Carbon::parse($fecha) : now();
        $generadas = [];
        $errores = [];

        Distribuidora::query()
            ->where('estado', 'ACTIVO')
            ->with('sucursal', 'categoria')
            ->chunk(50, function ($distribuidoras) use ($fechaCorte, $forzar, &$generadas, &$errores): void {
                foreach ($distribuidoras as $distribuidora) {
                    try {
                        if (! $forzar) {
                            $fechasConfig = $this->configuracionService->obtenerFechasVigentes(
                                $distribuidora->sucursal_id,
                                $fechaCorte->toDateString()
                            );

                            if (! $this->esDiaDeCorte($fechasConfig, $fechaCorte)) {
                                continue;
                            }
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
     *
     * Si ya existe un corte para $fechaCorte, no truena: recorre día por día hasta encontrar
     * una fecha libre para esta distribuidora (ver siguienteFechaCorteLibre()). Esto es lo que
     * permite generar más de un corte el mismo día real -- el gerente da clic, se genera el
     * corte de la quincena pendiente; da clic otra vez, se genera el de la siguiente quincena
     * con fecha_corte corrida un día (y por lo tanto referencia_pago distinta).
     */
    public function generarParaDistribuidora(Distribuidora $distribuidora, ?string $fechaCorte = null): ?Relacion
    {
        $fechaCorteSolicitada = $fechaCorte ? Carbon::parse($fechaCorte) : now();

        $valesPendientes = Vale::query()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('activo', true)
            ->whereIn('estado', ['autorizado', 'parcial', 'vencido'])
            ->get();

        if ($valesPendientes->isEmpty()) {
            return null;
        }

        $comisionBasePct = (float) ($this->configuracionService->obtenerValorVigente('comision_base_pct') ?? 10);
        $interesPctQuincena = (float) ($this->configuracionService->obtenerValorVigente('interes_pct_quincena') ?? 5);

        return DB::transaction(function () use (
            $distribuidora,
            $fechaCorteSolicitada,
            $valesPendientes,
            $comisionBasePct,
            $interesPctQuincena,
        ): Relacion {
            // Serializa el acceso a esta distribuidora: dos llamadas casi simultáneas (dos
            // clics seguidos del gerente, o un reintento manual que choca con el job
            // programado) no deben pisarse -- lockForUpdate() obliga a la segunda a esperar el
            // commit de la primera antes de decidir su propia fecha_corte.
            Distribuidora::query()->whereKey($distribuidora->id)->lockForUpdate()->first();

            $fechaCorte = $this->siguienteFechaCorteLibre($distribuidora->id, $fechaCorteSolicitada);

            [$fechaLimitePago, $fechaAnticipadoDesde, $fechaAnticipadoHasta] = $this->calcularFechas(
                $distribuidora->sucursal_id,
                $fechaCorte
            );

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
            $totalAbonadoPorExcedente = 0.0;

            foreach ($valesPendientes as $vale) {
                $detalle = $this->calcularDetalleVale($relacion, $vale, $comisionBasePct, $interesPctQuincena);

                // Si este vale tiene saldo a favor de un excedente de un corte anterior (pagó
                // de más y todavía no se le aplicó a ninguna cuota suya), se descuenta aquí
                // mismo contra la cuota que se le acaba de generar -- antes de que nadie vea
                // este corte, sin que la distribuidora ni la cajera tengan que hacer nada.
                $totalAbonadoPorExcedente += $this->excedenteConciliacionService->aplicarAlVale($detalle, $vale);

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

            if ($totalAbonadoPorExcedente > 0.0) {
                // Corte recién creado: nada más pudo haberlo abonado todavía, así que el total
                // de excedente aplicado ES el total_abonado inicial (no se suma a nada previo).
                $relacion->total_abonado = $totalAbonadoPorExcedente;
                $saldoPendiente = $totales['total'] - $totalAbonadoPorExcedente;
                $relacion->estado = $saldoPendiente <= 0.01 ? 'liquidada' : 'parcial';
                $relacion->save();

                $this->relacionLiquidacionService->procesarLiquidacion($relacion, $fechaCorte);
            }

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

    /**
     * Una cuota recién generada siempre nace limpia: sin recargo y con su descuento de
     * categoría completo. La multa por atraso ya no se calcula aquí -- vive en la quincena que
     * de verdad se atrasó, no en la que se genera después (ver
     * RelacionEstadoService::marcarVencidas()).
     */
    private function calcularDetalleVale(
        Relacion $relacion,
        Vale $vale,
        float $comisionBasePct,
        float $interesPctQuincena,
    ): RelacionDetalle {
        $quincenas = max(1, (int) ($vale->quincenas ?? $vale->producto?->quincenas ?? 1));
        $monto = (float) $vale->monto;

        $cuotaAnterior = RelacionDetalle::query()
            ->where('vale_id', $vale->id)
            ->latest('id')
            ->first();

        $cuotaNumero = $cuotaAnterior ? $cuotaAnterior->cuota_numero + 1 : 1;

        // Ganancia de la distribuidora por su categoría (Cobre/Plata/Oro), snapshot al generar el corte.
        $porcentajeCategoria = (float) ($relacion->porcentaje_comision_snapshot ?? 0);
        $base = $this->calcularMontosBase($monto, $quincenas, $comisionBasePct, $interesPctQuincena, $porcentajeCategoria);

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
            'categoria' => $base['categoria'],
            'recargo' => 0.0,
            'pago' => 0,
            'total' => $base['pago_quincenal'],
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
     * Recorre día por día desde $fechaCorte hasta encontrar una fecha sin relación ya generada
     * para esta distribuidora. fecha_corte es la base de referencia_pago (ver
     * construirReferenciaPago()), así que cada corte adicional el mismo día real necesita una
     * fecha propia para no compartir referencia con el anterior.
     */
    private function siguienteFechaCorteLibre(int $distribuidoraId, Carbon $fechaCorte): Carbon
    {
        $fecha = $fechaCorte->copy();

        while (Relacion::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->whereDate('fecha_corte', $fecha->toDateString())
            ->exists()
        ) {
            $fecha = $fecha->copy()->addDay();
        }

        return $fecha;
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
