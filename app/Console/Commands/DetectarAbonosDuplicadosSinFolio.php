<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AbonoConciliacion;
use App\Models\Relacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reporta abonos sin folio de pago que parecen el mismo pago aplicado más de una vez --
 * el hueco que ConciliacionBancariaService::procesarFila() tenía antes de agregar el respaldo
 * de deduplicación (referencia + monto + fecha + hora + relacion_detalle_id, ver ese método).
 * Cualquier fila del Excel del banco sin folio, importada dos veces (mismo archivo resubido, o
 * un rango de fechas que se solapa con una importación anterior), pudo haber quedado como dos
 * AbonoConciliacion en vez de uno, inflando total_abonado de la relación correspondiente.
 *
 * Es SOLO REPORTE -- no corrige nada, ni siquiera con una bandera. Decidir cuál de los abonos
 * del grupo es "el real" y ajustar total_abonado/estado en consecuencia es una decisión que
 * requiere ver el Excel original (¿de verdad se subió dos veces, o es una coincidencia rara de
 * dos pagos reales?); y si el abono de más ya alcanzó a liquidar la relación, la corrección
 * además carga con el mismo problema de puntos/vales ya marcados pagados que
 * relaciones:corregir-liquidadas-de-mas -- no algo para resolver con un UPDATE automático.
 */
final class DetectarAbonosDuplicadosSinFolio extends Command
{
    protected $signature = 'conciliaciones:detectar-duplicados-sin-folio';

    protected $description = 'Reporta (sin corregir nada) abonos sin folio de pago que parecen el mismo pago aplicado más de una vez.';

    public function handle(): int
    {
        $grupos = AbonoConciliacion::query()
            ->whereNull('folio_pago')
            ->select('referencia_leida', 'relacion_detalle_id', 'monto', 'fecha_pago', 'hora_pago', DB::raw('COUNT(*) as total'))
            ->groupBy('referencia_leida', 'relacion_detalle_id', 'monto', 'fecha_pago', 'hora_pago')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($grupos->isEmpty()) {
            $this->info('No se encontraron abonos sin folio que parezcan duplicados.');

            return self::SUCCESS;
        }

        $filas = [];
        $totalPosiblementeDuplicado = 0.0;

        foreach ($grupos as $grupo) {
            $idsQuery = AbonoConciliacion::query()
                ->whereNull('folio_pago')
                ->where('referencia_leida', $grupo->referencia_leida)
                ->where('monto', $grupo->monto)
                ->where('fecha_pago', $grupo->fecha_pago);

            $grupo->relacion_detalle_id === null
                ? $idsQuery->whereNull('relacion_detalle_id')
                : $idsQuery->where('relacion_detalle_id', $grupo->relacion_detalle_id);

            $grupo->hora_pago === null
                ? $idsQuery->whereNull('hora_pago')
                : $idsQuery->where('hora_pago', $grupo->hora_pago);

            $ids = $idsQuery->pluck('id')->implode(', ');

            $relacion = Relacion::query()->where('referencia_pago', $grupo->referencia_leida)->first();
            $vecesDeMas = (int) $grupo->total - 1;
            $montoDeMas = (float) $grupo->monto * $vecesDeMas;
            $totalPosiblementeDuplicado += $montoDeMas;

            $filas[] = [
                $grupo->referencia_leida,
                $relacion?->id ?? '(sin relación)',
                number_format((float) $grupo->monto, 2),
                $grupo->fecha_pago,
                $grupo->hora_pago ?? '(sin hora)',
                $grupo->total,
                $ids,
                number_format($montoDeMas, 2),
            ];
        }

        $this->table(
            ['Referencia', 'Relación ID', 'Monto c/u', 'Fecha', 'Hora', '# veces aplicado', 'IDs de abonos', 'Monto de más (posible)'],
            $filas
        );

        $this->newLine();
        $this->warn(count($filas).' grupo(s) encontrados. Monto total posiblemente duplicado: $'.number_format($totalPosiblementeDuplicado, 2));
        $this->warn('Esto es SOLO un reporte -- no corrige nada. Para cada grupo: confirma contra el Excel original del banco si de verdad se importó dos veces, decide cuál AbonoConciliacion es el real, y ajusta total_abonado/estado de la relación a mano (revisando también si ya disparó puntos o marcó vales/cuotas como pagados).');

        return self::SUCCESS;
    }
}
