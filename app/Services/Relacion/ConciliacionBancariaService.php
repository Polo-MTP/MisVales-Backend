<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\AbonoConciliacion;
use App\Models\PuntoMovimiento;
use App\Models\Relacion;
use App\Models\User;
use App\Models\Vale;
use App\Services\Configuracion\ConfiguracionService;
use App\Services\Notificacion\NotificacionService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Importa el Excel que la cajera descarga del banco, concilia cada movimiento contra la
 * referencia de pago única de una Relacion, y aplica el abono. Si la relación queda liquidada,
 * dispara el cálculo de puntos (solo en pago anticipado) o la penalización (pago fuera de tiempo).
 */
final class ConciliacionBancariaService
{
    private const COLUMNAS_ESPERADAS = ['item', 'concepto', 'referencia', 'pago', 'folio de pago', 'fecha de pago', 'hora', 'tipo de pago'];

    public function __construct(
        private readonly ConfiguracionService $configuracionService,
        private readonly NotificacionService $notificacionService,
    ) {}

    /**
     * @return array{procesadas: int, conciliadas: int, sin_coincidencia: int, duplicados: int, errores: array<int, string>}
     */
    public function importarArchivo(UploadedFile $archivo, ?int $convenioBancarioId, User $usuario): array
    {
        $rutaGuardada = Storage::disk('local')->putFile('conciliaciones', $archivo);

        $spreadsheet = IOFactory::load($archivo->getRealPath());
        // formatData=false: se necesitan los valores crudos (el banco exporta fechas como serial
        // de Excel o como texto según la fila, y montos formateados como texto "$2,100" no castean a float).
        // calculateFormulas=false: es un archivo externo (banco); una celda que por casualidad empiece
        // con "=" debe leerse tal cual, nunca evaluarse como fórmula.
        $filas = $spreadsheet->getActiveSheet()->toArray(null, false, false, false);

        $encabezado = array_map(
            fn ($valor) => Str::of((string) $valor)->lower()->trim()->toString(),
            array_shift($filas) ?? []
        );

        $resumen = ['procesadas' => 0, 'conciliadas' => 0, 'sin_coincidencia' => 0, 'duplicados' => 0, 'errores' => []];

        foreach ($filas as $numeroFila => $fila) {
            if ($this->filaVacia($fila)) {
                continue;
            }

            try {
                $datos = $this->mapearFila($encabezado, $fila);
                $abono = $this->procesarFila($datos, $convenioBancarioId, $usuario, (string) $rutaGuardada);

                if (! $abono->wasRecentlyCreated) {
                    $resumen['duplicados']++;

                    continue;
                }

                $resumen['procesadas']++;
                $resumen[$abono->estado === 'conciliado' ? 'conciliadas' : 'sin_coincidencia']++;
            } catch (Throwable $e) {
                $resumen['errores'][] = 'Fila '.($numeroFila + 2).': '.$e->getMessage();
            }
        }

        return $resumen;
    }

    /**
     * Concilia manualmente un abono que no coincidió con ninguna referencia (ej. la distribuidora
     * escribió mal el número de relación). Solo la cajera ejecuta ($ejecutor); requiere que un
     * gerente/coordinador haya autorizado previamente la solicitud puntual (ver SolicitudConciliacionService).
     */
    public function conciliarManual(AbonoConciliacion $abono, Relacion $relacion, User $ejecutor, string $motivo, ?int $autorizadoPorId = null): AbonoConciliacion
    {
        if ($abono->estado !== 'sin_coincidencia') {
            throw new DomainException('Este abono ya fue conciliado previamente.');
        }

        return DB::transaction(function () use ($abono, $relacion, $ejecutor, $motivo, $autorizadoPorId): AbonoConciliacion {
            $abono->update([
                'relacion_id' => $relacion->id,
                'estado' => 'conciliado_manual',
                'conciliado_por' => $ejecutor->id,
                'autorizado_por' => $autorizadoPorId ?? $ejecutor->id,
                'motivo_manual' => $motivo,
            ]);

            $this->aplicarAbono($relacion, (float) $abono->monto, Carbon::parse($abono->fecha_pago));

            return $abono->fresh();
        });
    }

    /**
     * La distribuidora reporta que un abono no coincide con lo que ella realmente pagó. Es
     * solo el punto de entrada informativo — la cajera sigue siendo quien de verdad dispara
     * SolicitudConciliacionService::solicitar()/ConciliacionBancariaService::conciliarManual()
     * para corregirlo; esto no reemplaza ese flujo, solo deja constancia de quién y por qué
     * lo reportó, visible para la cajera en el listado de abonos.
     */
    public function levantarQueja(AbonoConciliacion $abono, User $distribuidoraUsuario, string $motivo): AbonoConciliacion
    {
        $distribuidoraId = $distribuidoraUsuario->distribuidora?->id;

        if (! $distribuidoraId || $abono->relacion?->distribuidora_id !== $distribuidoraId) {
            abort(403, 'Este abono no pertenece a tu distribuidora.');
        }

        $abono->queja_por = $distribuidoraUsuario->id;
        $abono->queja_motivo = $motivo;
        $abono->queja_fecha = now();
        $abono->save();

        return $abono->fresh(['relacion', 'quejaPor']);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapearFila(array $encabezado, array $fila): array
    {
        $fila = array_combine($encabezado, array_slice($fila, 0, count($encabezado)));

        return [
            'referencia' => $this->normalizarReferencia($fila['referencia'] ?? ''),
            'monto' => (float) preg_replace('/[^0-9.\-]/', '', (string) ($fila['pago'] ?? 0)),
            'folio_pago' => isset($fila['folio de pago']) ? trim((string) $fila['folio de pago']) : null,
            'fecha_pago' => $this->parsearFecha($fila['fecha de pago'] ?? null),
            'hora_pago' => $this->parsearHora($fila['hora'] ?? null),
            'tipo_pago' => $this->normalizarTipoPago((string) ($fila['tipo de pago'] ?? '')),
        ];
    }

    /**
     * Crea el abono a partir de una fila ya normalizada, buscando la relación por su
     * referencia de pago; si hay coincidencia, aplica el abono de inmediato.
     */
    private function procesarFila(array $datos, ?int $convenioBancarioId, User $usuario, string $lote): AbonoConciliacion
    {
        if ($datos['referencia'] === '' || $datos['monto'] <= 0) {
            throw new InvalidArgumentException('Referencia o monto inválido.');
        }

        // El folio de pago es el identificador único que da el banco a esa transferencia --
        // si ya existe un abono con el mismo folio, es el mismo pago (Excel resubido, o el
        // banco exporta "últimos N días" y una fila se solapa con la importación anterior):
        // no volver a aplicarlo, o se duplicaría el monto en total_abonado.
        if ($datos['folio_pago']) {
            $existente = AbonoConciliacion::query()->where('folio_pago', $datos['folio_pago'])->first();

            if ($existente) {
                return $existente;
            }
        }

        $relacion = Relacion::query()->where('referencia_pago', $datos['referencia'])->first();

        $abono = AbonoConciliacion::query()->create([
            'relacion_id' => $relacion?->id,
            'referencia_leida' => $datos['referencia'],
            'monto' => $datos['monto'],
            'folio_pago' => $datos['folio_pago'],
            'fecha_pago' => $datos['fecha_pago'],
            'hora_pago' => $datos['hora_pago'],
            'tipo_pago' => $datos['tipo_pago'],
            'convenio_bancario_id' => $convenioBancarioId,
            'estado' => $relacion ? 'conciliado' : 'sin_coincidencia',
            'lote_archivo' => $lote,
            'subido_por' => $usuario->id,
        ]);

        if ($relacion) {
            $this->aplicarAbono($relacion, (float) $datos['monto'], Carbon::parse($datos['fecha_pago']));
        }

        return $abono;
    }

    /**
     * Suma el abono al total de la relación y actualiza su estado (parcial/liquidada) según
     * el margen de tolerancia configurado; si queda liquidada, dispara puntos/penalización.
     */
    private function aplicarAbono(Relacion $relacion, float $monto, Carbon $fechaAbono): void
    {
        DB::transaction(function () use ($relacion, $monto, $fechaAbono): void {
            $relacion->refresh();
            $relacion->total_abonado = (float) $relacion->total_abonado + $monto;

            $margenTolerancia = (float) ($this->configuracionService->obtenerValorVigente('margen_tolerancia_conciliacion') ?? 0);
            $saldoPendiente = (float) $relacion->total_a_pagar - (float) $relacion->total_abonado;

            if ($saldoPendiente <= $margenTolerancia) {
                $relacion->estado = 'liquidada';
            } elseif ((float) $relacion->total_abonado > 0) {
                $relacion->estado = 'parcial';
            }

            $relacion->save();

            if ($relacion->estado === 'liquidada') {
                $this->procesarPuntos($relacion, $fechaAbono);
                $this->marcarValesPagados($relacion);
            }
        });
    }

    /**
     * Al liquidarse el corte, cada cuota (RelacionDetalle) de ese corte queda 'pagado' — sin
     * esto, RelacionCalculoService::calcularDetalleVale() nunca encuentra una cuota anterior en
     * 'pagado' (se queda en 'pendiente' para siempre) y le cobra el recargo por atraso a TODO
     * corte a partir del segundo, así se haya pagado puntual.
     *
     * Un vale además queda 'pagado' hasta que se liquida su última cuota (cuota_numero ===
     * cuotas_totales); si nació como 'pre-vale' (primer vale de un cliente nuevo), se convierte
     * en 'vale-digital' en ese mismo momento.
     */
    private function marcarValesPagados(Relacion $relacion): void
    {
        $relacion->loadMissing('detalles.vale');

        foreach ($relacion->detalles as $detalle) {
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
     * Indica si una fila del Excel no tiene ningún valor útil (fin del listado o fila de relleno).
     */
    private function filaVacia(array $fila): bool
    {
        return count(array_filter($fila, fn ($v) => $v !== null && $v !== '')) === 0;
    }

    /**
     * Tolerante a formatos mixtos: serial de Excel (numérico) o texto "d/m/Y".
     */
    private function parsearFecha(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_numeric($valor)) {
            return ExcelDate::excelToDateTimeObject((float) $valor)->format('Y-m-d');
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $formato) {
            try {
                return Carbon::createFromFormat($formato, trim((string) $valor))->format('Y-m-d');
            } catch (Throwable) {
                continue;
            }
        }

        return Carbon::parse((string) $valor)->format('Y-m-d');
    }

    /**
     * Tolerante a formatos mixtos: serial de Excel (numérico) o texto de hora.
     */
    private function parsearHora(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_numeric($valor)) {
            return ExcelDate::excelToDateTimeObject((float) $valor)->format('H:i:s');
        }

        try {
            return Carbon::parse((string) $valor)->format('H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * La referencia real (RelacionCalculoService::construirReferenciaPago) siempre tiene 18
     * dígitos: 9 del id de la distribuidora + 9 de la fecha de corte, ambos rellenados con
     * ceros a la izquierda. Si el banco exporta esa columna como número en vez de texto (muy
     * común en Excel), esos ceros se pierden al leer la celda -- "000000001020260920" llega
     * como 1020260920 -- y sin esto la comparación exacta contra Relacion::referencia_pago
     * nunca hace match, cayendo siempre en "sin_coincidencia" aunque la referencia sea
     * correcta. Se vuelve a rellenar antes de comparar; si no es puramente numérica (o ya
     * mide 18), se deja tal cual.
     */
    private function normalizarReferencia(mixed $valor): string
    {
        $referencia = trim((string) $valor);

        if ($referencia !== '' && ctype_digit($referencia) && mb_strlen($referencia) < 18) {
            return mb_str_pad($referencia, 18, '0', STR_PAD_LEFT);
        }

        return $referencia;
    }

    /**
     * Normaliza el texto libre de "tipo de pago" del banco a uno de los valores esperados
     * por el sistema, tolerando variaciones y errores de captura conocidos.
     */
    private function normalizarTipoPago(string $valor): string
    {
        $valor = Str::of($valor)->lower()->ascii()->toString();

        return match (true) {
            // Tolera "Transferencia" (correcto) y "Tranferencia" (typo real del banco, sin la 's').
            str_contains($valor, 'transfer') || str_contains($valor, 'tranfer') => 'transferencia',
            str_contains($valor, 'banca') => 'banca_en_linea',
            str_contains($valor, 'ventanilla') => 'pago_en_ventanilla',
            default => 'otro',
        };
    }
}
