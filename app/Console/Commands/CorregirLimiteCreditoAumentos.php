<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Distribuidora;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrige distribuidoras infladas por el bug de SolicitudAumentoCreditoService::decidir()
 * (ya arreglado): antes de ese fix, aprobar una solicitud SUMABA monto_otorgado al
 * limite_credito ya vigente, en vez de fijarlo como el nuevo límite total. Una distribuidora
 * con $25,000 aprobada para subir a $75,000 terminaba con $100,000 en vez de $75,000.
 *
 * Para cada distribuidora con al menos una solicitud 'aprobada', el límite correcto es el
 * monto_otorgado de su solicitud aprobada MÁS RECIENTE (mismo criterio que ya usa
 * Distribuidora::aumentoCreditoSinConsumir()) -- bajo la semántica correcta, cada aprobación
 * fija el total completo, así que la última aprobada siempre "gana" sobre las anteriores.
 *
 * CAVEAT que no se puede resolver solo con estos datos: si alguien reasignó el crédito a mano
 * (endpoint PUT /distribuidoras/{id}/credito, vía DistribuidoraService::asignarCredito) DESPUÉS
 * de esa última solicitud aprobada, este comando pisaría ese valor manual más reciente. Revisa
 * el audit_log (acción 'Distribuidora.actualizado') de cada distribuidora listada antes de
 * correr con --apply si el sistema de auditoría ya estaba activo en ese momento.
 *
 * Por defecto es solo un preview (dry-run): no toca nada hasta que se pasa --apply.
 */
final class CorregirLimiteCreditoAumentos extends Command
{
    protected $signature = 'distribuidoras:corregir-limite-credito {--apply : Aplica los cambios; sin esta bandera solo se muestra el preview, sin tocar nada}';

    protected $description = 'Corrige limite_credito en distribuidoras infladas por el bug de suma en vez de reemplazo al aprobar un aumento de crédito.';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('apply');

        $distribuidoras = Distribuidora::query()
            ->whereHas('solicitudesAumentoCredito', fn ($q) => $q->where('estado', 'aprobada'))
            ->with(['solicitudesAumentoCredito' => fn ($q) => $q->where('estado', 'aprobada')->latest('fecha_decision')])
            ->get();

        $filas = [];
        $porCorregir = [];

        foreach ($distribuidoras as $distribuidora) {
            $ultimaAprobada = $distribuidora->solicitudesAumentoCredito->first();

            if (! $ultimaAprobada || $ultimaAprobada->monto_otorgado === null) {
                continue;
            }

            $limiteActual = (float) $distribuidora->limite_credito;
            $limiteCorrecto = (float) $ultimaAprobada->monto_otorgado;

            if (abs($limiteActual - $limiteCorrecto) < 0.01) {
                continue;
            }

            $filas[] = [
                $distribuidora->id,
                $distribuidora->numero_distribuidora,
                number_format($limiteActual, 2),
                number_format($limiteCorrecto, 2),
                number_format($limiteActual - $limiteCorrecto, 2),
            ];
            $porCorregir[] = [$distribuidora, $limiteCorrecto];
        }

        if ($filas === []) {
            $this->info('No se encontró ninguna distribuidora con limite_credito inconsistente respecto a su última solicitud aprobada.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Número', 'Límite actual', 'Límite correcto', 'Diferencia (de más)'],
            $filas
        );

        if (! $aplicar) {
            $this->warn(count($filas).' distribuidora(s) con límite inconsistente. Este fue solo un preview -- vuelve a correr con --apply para corregirlas.');
            $this->warn('Antes de aplicar: confirma en audit_log que ninguna de estas tuvo una reasignación manual de crédito DESPUÉS de su última solicitud aprobada (ver docblock del comando).');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($porCorregir): void {
            foreach ($porCorregir as [$distribuidora, $limiteCorrecto]) {
                $distribuidora->limite_credito = $limiteCorrecto;
                $distribuidora->save();
            }
        });

        $this->info(count($filas).' distribuidora(s) corregida(s).');

        return self::SUCCESS;
    }
}
