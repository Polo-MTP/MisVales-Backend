<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\AbonoConciliacion;
use App\Models\PuntoMovimiento;
use App\Models\Relacion;
use App\Models\RelacionDetalle;
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

        // COLUMNAS_ESPERADAS existía pero nunca se usaba para validar nada -- si el banco
        // renombraba/quitaba una columna, el archivo se "procesaba" igual, con esa columna
        // leyéndose silenciosamente como vacía en cada fila (ver mapearFila()) en vez de
        // avisar con un error claro por qué nada está conciliando.
        $columnasFaltantes = array_diff(self::COLUMNAS_ESPERADAS, $encabezado);
        if ($columnasFaltantes !== []) {
            throw new DomainException('El archivo no tiene las columnas esperadas: '.implode(', ', $columnasFaltantes).'.');
        }

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
            // La corrección manual es por referencia mal capturada (typo en la relación) -- si
            // el concepto que sí llegó bien coincide con alguno de los detalles de esta
            // relación, igual se aprovecha para aplicar el abono al vale correcto.
            $detalle = RelacionDetalle::query()
                ->where('relacion_id', $relacion->id)
                ->where('concepto', $abono->referencia_leida)
                ->first();

            $abono->update([
                'relacion_id' => $relacion->id,
                'relacion_detalle_id' => $detalle?->id,
                'estado' => 'conciliado_manual',
                'conciliado_por' => $ejecutor->id,
                'autorizado_por' => $autorizadoPorId ?? $ejecutor->id,
                'motivo_manual' => $motivo,
            ]);

            $this->aplicarAbono($relacion, (float) $abono->monto, Carbon::parse($abono->fecha_pago), $detalle);

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
    public function levantarQueja(AbonoConciliacion $abono, User $distribuidoraUsuario, string $motivo, ?UploadedFile $evidencia = null): AbonoConciliacion
    {
        $distribuidoraId = $distribuidoraUsuario->distribuidora?->id;

        if (! $distribuidoraId || $abono->relacion?->distribuidora_id !== $distribuidoraId) {
            abort(403, 'Este abono no pertenece a tu distribuidora.');
        }

        $abono->queja_por = $distribuidoraUsuario->id;
        $abono->queja_motivo = $motivo;
        $abono->queja_fecha = now();

        if ($evidencia) {
            $disk = (config('filesystems.default') === 's3' || ! empty(config('filesystems.disks.s3.bucket')))
                ? 's3'
                : 'public';

            $ruta = Storage::disk($disk)->putFile('quejas-conciliacion', $evidencia, 'public');
            $abono->queja_evidencia_url = Storage::disk($disk)->url($ruta);
        }

        $abono->save();

        // Sin esto, una queja se queda invisible hasta que alguien entra a buscarla a mano en el
        // listado de abonos -- avisa a las Cajeras de la sucursal de la relación para que sepan
        // que deben iniciar la conciliación manual (solicitarAutorizacion) sobre este abono.
        $this->notificacionService->notificarRolEnSucursal(
            'Cajera',
            $abono->relacion?->sucursal_id,
            'abono_con_queja',
            'Abono #'.$abono->id,
            $distribuidoraUsuario
        );

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
            // Identifica el vale específico cuando el corte junta varios y la distribuidora
            // los paga por separado -- ver RelacionCalculoService::construirConceptoVale().
            // Vacío/no-match: el abono se aplica al corte completo, igual que antes.
            'concepto' => trim((string) ($fila['concepto'] ?? '')),
            'monto' => (float) preg_replace('/[^0-9.\-]/', '', (string) ($fila['pago'] ?? 0)),
            // '' normalizado a null (no solo "sin la clave"): una celda vacía en el Excel debe
            // guardarse como NULL, no como string vacío -- si no, el respaldo de deduplicación
            // sin folio (ver procesarFila()) nunca encuentra el whereNull('folio_pago') que
            // necesita para comparar contra abonos previos igual de "sin folio".
            'folio_pago' => (isset($fila['folio de pago']) && trim((string) $fila['folio de pago']) !== '')
                ? trim((string) $fila['folio de pago'])
                : null,
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
        } else {
            // Sin folio (el banco no siempre lo trae, ej. depósitos en ventanilla) no hay
            // identificador único que comparar -- se arma uno compuesto con el resto de los
            // datos EXACTOS de la fila. Que dos pagos reales distintos coincidan a la vez en
            // referencia, monto, fecha Y hora es prácticamente imposible, así que si ya existe
            // un abono (también sin folio) con los cuatro iguales, es el mismo movimiento
            // reimportado, no uno nuevo.
            $existente = AbonoConciliacion::query()
                ->whereNull('folio_pago')
                ->where('referencia_leida', $datos['referencia'])
                ->where('monto', $datos['monto'])
                // whereDate(), no where(): el cast 'date' de Eloquent guarda fecha_pago con hora
                // en 00:00:00 ("2026-02-13 00:00:00"), no como el string plano "2026-02-13" que
                // trae $datos -- mismo problema (y mismo fix) que ya se dio en otro lado de esta
                // API con vigente_desde/vigente_hasta.
                ->when($datos['fecha_pago'] === null, fn ($q) => $q->whereNull('fecha_pago'), fn ($q) => $q->whereDate('fecha_pago', $datos['fecha_pago']))
                ->when($datos['hora_pago'] === null, fn ($q) => $q->whereNull('hora_pago'), fn ($q) => $q->where('hora_pago', $datos['hora_pago']))
                ->first();

            if ($existente) {
                return $existente;
            }
        }

        $relacion = Relacion::query()->where('referencia_pago', $datos['referencia'])->first();

        // Si el corte tiene más de un vale y la distribuidora puso el concepto de uno en
        // particular, el abono es de ESE vale, no del corte completo -- buscarlo solo dentro
        // de los detalles de la relación ya encontrada (el concepto no es global, es por vale).
        $detalle = ($relacion && $datos['concepto'] !== '')
            ? RelacionDetalle::query()->where('relacion_id', $relacion->id)->where('concepto', $datos['concepto'])->first()
            : null;

        $abono = AbonoConciliacion::query()->create([
            'relacion_id' => $relacion?->id,
            'relacion_detalle_id' => $detalle?->id,
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
            $this->aplicarAbono($relacion, (float) $datos['monto'], Carbon::parse($datos['fecha_pago']), $detalle);
        }

        return $abono;
    }

    /**
     * Saldo pendiente por debajo de este umbral se considera pagado. Es un epsilon de
     * redondeo de centavos (float), NO una cantidad de negocio que se perdona -- a propósito
     * mucho más chico que margen_tolerancia_conciliacion (ver abajo), que puede configurarse
     * en cientos de pesos para otros fines. Antes 'liquidada'/'pagado' usaban ese mismo margen
     * configurable: un corte con $252 todavía sin pagar (por debajo de un margen de $300)
     * salía marcado "Liquidada" en la app de la distribuidora, mostrando a la vez un saldo
     * pendiente > 0 y el badge de pagado -- dinero real quedaba condonado en silencio, sin que
     * nadie lo autorizara explícitamente. Perdonar un saldo real es una decisión de gerencia
     * (ver RelacionEstadoService::perdonar()), no un efecto secundario de este margen.
     */
    private const EPSILON_LIQUIDACION = 0.01;

    /**
     * Suma el abono al total de la relación y actualiza su estado (parcial/liquidada); si
     * queda liquidada, dispara puntos/penalización.
     *
     * Si $detalle viene (el concepto identificó a cuál vele corresponde), el abono se aplica
     * a ESE detalle primero (su propio 'pago'/'estado'), y el total_abonado de la relación se
     * recalcula sumando todos sus detalles -- así un corte con varios vales pagados por
     * separado, en distintos momentos, refleja bien cuánto falta en cada uno. Sin $detalle
     * (corte de un solo vale, o pago sin concepto), se suma directo al total de la relación,
     * igual que siempre.
     */
    private function aplicarAbono(Relacion $relacion, float $monto, Carbon $fechaAbono, ?RelacionDetalle $detalle = null): void
    {
        DB::transaction(function () use ($relacion, $monto, $fechaAbono, $detalle): void {
            $relacion->refresh();

            // margen_tolerancia_conciliacion sigue vigente más abajo, pero solo para decidir
            // si un EXCEDENTE (pagaron de más) amerita avisarle a alguien -- no para decidir
            // si algo ya quedó pagado, ver EPSILON_LIQUIDACION arriba.
            $margenTolerancia = (float) ($this->configuracionService->obtenerValorVigente('margen_tolerancia_conciliacion') ?? 0);

            if ($detalle) {
                $detalle->refresh();
                $detalle->pago = (float) $detalle->pago + $monto;
                $saldoCuota = (float) $detalle->total - (float) $detalle->pago;
                $detalle->estado = $saldoCuota <= self::EPSILON_LIQUIDACION ? 'pagado' : 'parcial';
                $detalle->save();

                $relacion->total_abonado = (float) RelacionDetalle::query()->where('relacion_id', $relacion->id)->sum('pago');
            } else {
                $relacion->total_abonado = (float) $relacion->total_abonado + $monto;
            }

            $saldoPendiente = (float) $relacion->total_a_pagar - (float) $relacion->total_abonado;

            if ($saldoPendiente <= self::EPSILON_LIQUIDACION) {
                $relacion->estado = 'liquidada';
            } elseif ((float) $relacion->total_abonado > 0) {
                $relacion->estado = 'parcial';
            }

            $relacion->save();

            if ($relacion->estado === 'liquidada') {
                $this->procesarPuntos($relacion, $fechaAbono);
                $this->marcarValesPagados($relacion);
            }

            // Si el banco reportó más de lo que se debía (o dos abonos distintos matchearon el
            // mismo concepto por error), el excedente no se refleja en ningún lado más que aquí
            // -- sin avisar, ese dinero de más queda invisible en vez de esperar a que alguien
            // decida si se aplica al siguiente corte o se reembolsa a la distribuidora.
            $excedente = (float) $relacion->total_abonado - (float) $relacion->total_a_pagar;
            if ($excedente > $margenTolerancia) {
                $recurso = 'Relación #'.$relacion->id.' — excedente $'.number_format($excedente, 2);
                $this->notificacionService->notificarRolEnSucursal('Gerente de Sucursal', $relacion->sucursal_id, 'abono_excedente', $recurso);
                $this->notificacionService->notificarRolEnSucursal('Coordinador', $relacion->sucursal_id, 'abono_excedente', $recurso);
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
