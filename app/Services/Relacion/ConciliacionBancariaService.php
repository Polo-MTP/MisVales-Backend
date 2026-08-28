<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\AbonoConciliacion;
use App\Models\Relacion;
use App\Models\RelacionDetalle;
use App\Models\User;
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
        private readonly RelacionLiquidacionService $relacionLiquidacionService,
        private readonly ExcedenteConciliacionService $excedenteConciliacionService,
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
            // Vacío/no-match: el abono se aplica al corte completo, igual que antes. Mismo
            // problema que 'referencia' (Excel exportando la columna como número, perdiendo
            // los ceros a la izquierda) -- concepto también es puramente numérico
            // (%05d vale_id + %04d cuota_numero, 9 dígitos), así que necesita el mismo
            // re-rellenado antes de comparar contra RelacionDetalle.concepto.
            'concepto' => $this->normalizarNumeroConLongitud($fila['concepto'] ?? '', 9),
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

        $relacion = Relacion::query()->where('referencia_pago', $datos['referencia'])->first();

        // Si el corte tiene más de un vale y la distribuidora puso el concepto de uno en
        // particular, el abono es de ESE vale, no del corte completo -- buscarlo solo dentro
        // de los detalles de la relación ya encontrada (el concepto no es global, es por vale).
        // Se calcula ANTES del chequeo de duplicados de abajo porque el respaldo sin folio lo
        // necesita (ver comentario ahí).
        $detalle = ($relacion && $datos['concepto'] !== '')
            ? RelacionDetalle::query()->where('relacion_id', $relacion->id)->where('concepto', $datos['concepto'])->first()
            : null;

        // Sin concepto que matchee (no vino, o no encontró nada), pero si la relación tiene UN
        // solo vale no hay ninguna ambigüedad real -- el pago solo puede ser de ese detalle.
        // Cuando el abono cubre el total, RelacionLiquidacionService::marcarValesPagados() ya
        // corrige esto por su cuenta (fuerza 'pagado' en todos los detalles de una Relacion que
        // queda 'liquidada', tenga o no concepto). Pero un abono PARCIAL nunca deja la Relacion
        // en 'liquidada', así que esa red de seguridad no corre: sin esto, el RelacionDetalle.pago
        // se quedaba en 0 (y su estado en 'pendiente') aunque Relacion.total_abonado sí reflejara
        // el abono parcial -- inconsistente entre el total del corte y su único detalle.
        if (! $detalle && $relacion) {
            $detalle = $this->detalleUnicoSiAplica($relacion);
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
            // datos EXACTOS de la fila, incluyendo relacion_detalle_id: sin esto, dos vales
            // DISTINTOS del mismo corte multi-vale que coincidieran en monto+fecha+hora se
            // habrían tratado como el mismo pago reimportado, perdiendo el segundo abono real.
            // Que dos pagos reales distintos coincidan a la vez en los cinco es prácticamente
            // imposible, así que si ya existe un abono (también sin folio) con todo igual, es
            // el mismo movimiento reimportado, no uno nuevo.
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
                ->when($detalle === null, fn ($q) => $q->whereNull('relacion_detalle_id'), fn ($q) => $q->where('relacion_detalle_id', $detalle->id))
                ->first();

            if ($existente) {
                return $existente;
            }
        }

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
     * Si $detalle viene (el concepto identificó a cuál vale corresponde), el abono se aplica
     * a ESE detalle primero (su propio 'pago'/'estado'), y el total_abonado de la relación se
     * recalcula sumando todos sus detalles -- así un corte con varios vales pagados por
     * separado, en distintos momentos, refleja bien cuánto falta en cada uno.
     *
     * Sin $detalle, el pago es "acumulado de la distribuidora": se reparte entre TODAS sus
     * cuotas sin liquidar (de cualquier corte, no solo $relacion), de la más antigua a la más
     * nueva -- una sola transferencia salda lo más viejo primero, sin importar en cuántos
     * cortes ni de cuántos clientes esté repartida la deuda. Antes esto solo sumaba al total
     * de $relacion sin tocar los detalles individuales, dejando fuera cualquier saldo de OTRO
     * corte ya vencido de la misma distribuidora -- la distribuidora no tenía forma de pagar
     * "todo lo que debe" de un solo golpe, tenía que ir corte por corte o vale por vale.
     */
    private function aplicarAbono(Relacion $relacion, float $monto, Carbon $fechaAbono, ?RelacionDetalle $detalle = null): void
    {
        DB::transaction(function () use ($relacion, $monto, $fechaAbono, $detalle): void {
            if ($detalle) {
                // lockForUpdate(): mismo motivo que en la rama de abajo -- dos abonos casi
                // simultáneos sobre la misma relación no deben pisarse.
                $relacion = Relacion::query()->whereKey($relacion->id)->lockForUpdate()->firstOrFail();
                $detalle = RelacionDetalle::query()->whereKey($detalle->id)->lockForUpdate()->firstOrFail();
                $detalle->pago = (float) $detalle->pago + $monto;
                $saldoCuota = (float) $detalle->total - (float) $detalle->pago;
                $detalle->estado = $saldoCuota <= self::EPSILON_LIQUIDACION ? 'pagado' : 'parcial';
                $detalle->save();

                $relacion->total_abonado = (float) RelacionDetalle::query()->where('relacion_id', $relacion->id)->sum('pago');
                $this->finalizarRelacion($relacion, $fechaAbono);

                // Si el banco reportó más de lo que se debía esta cuota, el excedente se
                // registra en el saldo a favor del VALE específico que lo generó -- nunca de
                // la distribuidora en general, para que el excedente de un cliente no termine
                // pagando la deuda de otro.
                $this->excedenteConciliacionService->registrarParaDetalle($relacion, $detalle);

                return;
            }

            $distribuidoraId = $relacion->distribuidora_id;

            // 'arrastrada' (ver RelacionCalculoService::calcularDetalleVale()) queda fuera: su
            // saldo ya se movió a la cuota siguiente del mismo vale, pagarla aquí duplicaría
            // esa deuda. 'estado'/'relacion_detalles.relacion_id' van calificados con el nombre
            // de tabla -- relaciones y relacion_detalles TIENEN AMBAS una columna 'estado', sin
            // calificar el join la resuelve contra la tabla equivocada según el motor de BD.
            $pendientes = RelacionDetalle::query()
                ->join('relaciones', 'relaciones.id', '=', 'relacion_detalles.relacion_id')
                ->where('relaciones.distribuidora_id', $distribuidoraId)
                ->whereNotIn('relacion_detalles.estado', ['pagado', 'arrastrada'])
                ->orderBy('relaciones.fecha_corte')
                ->orderBy('relacion_detalles.cuota_numero')
                ->select('relacion_detalles.*')
                ->lockForUpdate()
                ->get();

            $restante = $monto;
            $relacionIdsTocadas = [];

            foreach ($pendientes as $pendiente) {
                if ($restante <= self::EPSILON_LIQUIDACION) {
                    break;
                }

                $saldo = round((float) $pendiente->total - (float) $pendiente->pago, 2);

                if ($saldo <= 0.0) {
                    continue;
                }

                $aplicado = min($restante, $saldo);
                $pendiente->pago = (float) $pendiente->pago + $aplicado;
                $pendiente->estado = ((float) $pendiente->total - (float) $pendiente->pago) <= self::EPSILON_LIQUIDACION ? 'pagado' : 'parcial';
                $pendiente->save();

                $restante = round($restante - $aplicado, 2);
                $relacionIdsTocadas[$pendiente->relacion_id] = true;
            }

            foreach (array_keys($relacionIdsTocadas) as $relacionIdTocada) {
                /** @var Relacion $relacionTocada */
                $relacionTocada = Relacion::query()->whereKey($relacionIdTocada)->lockForUpdate()->firstOrFail();
                $relacionTocada->total_abonado = (float) RelacionDetalle::query()->where('relacion_id', $relacionTocada->id)->sum('pago');
                $this->finalizarRelacion($relacionTocada, $fechaAbono);
            }

            // Sobrante después de saldar TODO lo que la distribuidora debía -- se registra
            // como excedente contra la relación que disparó el pago (misma lógica de siempre,
            // ExcedenteConciliacionService reparte entre los vales de esa relación).
            if ($restante > self::EPSILON_LIQUIDACION) {
                $this->excedenteConciliacionService->registrarParaRelacion($relacion->fresh(), $restante);
            }
        });
    }

    /**
     * Actualiza el estado (parcial/liquidada) de una relación ya con total_abonado
     * recalculado, y dispara puntos/penalización si quedó liquidada. Compartido entre el pago
     * de un vale puntual (por concepto) y el reparto acumulado entre varias relaciones.
     */
    private function finalizarRelacion(Relacion $relacion, Carbon $fechaAbono): void
    {
        $saldoPendiente = (float) $relacion->total_a_pagar - (float) $relacion->total_abonado;

        if ($saldoPendiente <= self::EPSILON_LIQUIDACION) {
            $relacion->estado = 'liquidada';
        } elseif ((float) $relacion->total_abonado > 0) {
            $relacion->estado = 'parcial';
        }

        $relacion->save();

        // Si esto acaba de liquidar la relación, la fecha que decide "anticipado vs. tardío"
        // (RelacionLiquidacionService::procesarPuntos()) NO debe ser la de ESTE abono en
        // particular -- si hicieron falta varios abonos para completar el total y llegaron en
        // un orden distinto al cronológico (el Excel del banco no viene ordenado por fecha, o
        // un abono se concilió manualmente después con su fecha original de transferencia), el
        // que por casualidad complete el saldo podría ser uno que sí llegó a tiempo aunque OTRO
        // de los abonos que forman ese mismo total haya llegado tarde. La relación solo queda
        // de verdad "liquidada a tiempo" hasta que llega el ÚLTIMO abono (por fecha) de todos
        // los que la componen.
        $fechaEfectiva = $relacion->estado === 'liquidada'
            ? Carbon::parse(AbonoConciliacion::query()->where('relacion_id', $relacion->id)->max('fecha_pago') ?? $fechaAbono)
            : $fechaAbono;

        $this->relacionLiquidacionService->procesarLiquidacion($relacion, $fechaEfectiva);
    }

    /**
     * Si la relación tiene un solo RelacionDetalle (un solo vale en ese corte), lo regresa --
     * sin ambigüedad posible, un pago sin concepto (o con concepto que no matcheó nada) solo
     * puede ser de ese vale. Con más de un detalle no hay forma de saber cuál es, se regresa
     * null (el abono se sigue aplicando solo al total de la relación, como siempre).
     */
    private function detalleUnicoSiAplica(Relacion $relacion): ?RelacionDetalle
    {
        $detalles = RelacionDetalle::query()->where('relacion_id', $relacion->id)->get();

        return $detalles->count() === 1 ? $detalles->first() : null;
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
        return $this->normalizarNumeroConLongitud($valor, 18);
    }

    /**
     * Re-rellena con ceros a la izquierda un identificador puramente numérico de longitud fija
     * (referencia_pago: 18 dígitos: 9 de distribuidora + 9 de fecha; concepto: 9 dígitos: 5 de
     * vale_id + 4 de cuota_numero) que Excel exportó como número en vez de texto, perdiendo los
     * ceros que sí tenía el valor original. Si no es puramente numérico (o ya mide lo esperado),
     * se deja tal cual -- ya viene bien, o no es este tipo de problema.
     */
    private function normalizarNumeroConLongitud(mixed $valor, int $longitud): string
    {
        $texto = trim((string) $valor);

        if ($texto !== '' && ctype_digit($texto) && mb_strlen($texto) < $longitud) {
            return mb_str_pad($texto, $longitud, '0', STR_PAD_LEFT);
        }

        return $texto;
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
