<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PuntoMovimiento;
use App\Models\Relacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrige relaciones marcadas 'liquidada' por el bug de ConciliacionBancariaService::
 * aplicarAbono() (ya arreglado): antes del fix, un saldo pendiente dentro de
 * margen_tolerancia_conciliacion (configurable, hasta cientos de pesos) se trataba como
 * "ya pagado" -- una relación con saldo real pendiente > 0 quedaba "Liquidada" en la app.
 *
 * Este comando SOLO corrige Relacion.estado (a partir de qué tan pendiente sigue realmente,
 * ver decidirEstadoCorrecto()). A propósito NO toca automáticamente:
 *   - RelacionDetalle.estado ('pagado' de cada cuota)
 *   - Vale.estado / Vale.tipo (marcarValesPagados() los pudo haber marcado 'pagado' o
 *     convertido de pre-vale a vale-digital)
 *   - Puntos de fidelidad ya otorgados (PuntoMovimiento + Distribuidora.puntos_acumulados)
 * porque revertir esos automáticamente (quitarle puntos ya mostrados a una distribuidora,
 * "des-pagar" un vale del que ya se generó uno nuevo asumiendo que estaba liquidado) puede
 * causar más confusión/daño que dejarlos y revisarlos caso por caso. El comando SÍ reporta
 * (informativo, siempre, incluso sin --apply) qué relaciones corregidas tienen puntos ya
 * otorgados o vales ya marcados pagados, para que el equipo los revise a mano.
 *
 * Por defecto es solo un preview (dry-run): no toca nada hasta que se pasa --apply.
 */
final class CorregirRelacionesLiquidadasDeMas extends Command
{
    protected $signature = 'relaciones:corregir-liquidadas-de-mas {--apply : Aplica los cambios; sin esta bandera solo se muestra el preview, sin tocar nada}';

    protected $description = 'Corrige el estado de relaciones marcadas liquidada con saldo pendiente real (bug de margen de tolerancia, ya arreglado en el código).';

    private const EPSILON = 0.01;

    public function handle(): int
    {
        $aplicar = (bool) $this->option('apply');
        $hoy = now()->toDateString();

        $relaciones = Relacion::query()
            ->where('estado', 'liquidada')
            ->with(['detalles', 'distribuidora'])
            ->get()
            ->filter(fn (Relacion $r): bool => (float) $r->total_a_pagar - (float) $r->total_abonado > self::EPSILON);

        if ($relaciones->isEmpty()) {
            $this->info('No se encontró ninguna relación "liquidada" con saldo pendiente real.');

            return self::SUCCESS;
        }

        $filas = [];
        $porCorregir = [];
        $requierenRevisionManual = [];

        foreach ($relaciones as $relacion) {
            $saldoReal = round((float) $relacion->total_a_pagar - (float) $relacion->total_abonado, 2);
            $estadoCorrecto = $this->decidirEstadoCorrecto($relacion, $hoy);

            $filas[] = [
                $relacion->id,
                $relacion->referencia_pago,
                $relacion->distribuidora?->numero_distribuidora,
                number_format($saldoReal, 2),
                $estadoCorrecto,
            ];
            $porCorregir[] = [$relacion, $estadoCorrecto];

            $detallesPagados = $relacion->detalles->where('estado', 'pagado')->count();
            $puntosOtorgados = PuntoMovimiento::query()->where('relacion_id', $relacion->id)->exists();

            if ($detallesPagados > 0 || $puntosOtorgados) {
                $requierenRevisionManual[] = [
                    $relacion->id,
                    $relacion->referencia_pago,
                    $detallesPagados > 0 ? "{$detallesPagados} cuota(s) marcada(s) 'pagado'" : '-',
                    $puntosOtorgados ? 'Sí' : '-',
                ];
            }
        }

        $this->table(
            ['Relación ID', 'Referencia', 'Distribuidora', 'Saldo real pendiente', 'Estado correcto'],
            $filas
        );

        if ($requierenRevisionManual !== []) {
            $this->newLine();
            $this->warn('Estas además tienen efectos secundarios que este comando NO revierte automáticamente -- revísalas a mano:');
            $this->table(
                ['Relación ID', 'Referencia', 'Cuotas marcadas pagado', '¿Puntos ya otorgados?'],
                $requierenRevisionManual
            );
        }

        if (! $aplicar) {
            $this->newLine();
            $this->warn(count($filas).' relación(es) con estado inconsistente. Este fue solo un preview -- vuelve a correr con --apply para corregir el estado.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($porCorregir): void {
            foreach ($porCorregir as [$relacion, $estadoCorrecto]) {
                $relacion->estado = $estadoCorrecto;
                $relacion->save();
            }
        });

        $this->info(count($filas).' relación(es) corregida(s).');

        return self::SUCCESS;
    }

    /**
     * Mismo criterio que RelacionEstadoService::marcarVencidas(): si la fecha límite de pago
     * ya pasó, vencida; si no, parcial (ya tiene algo abonado) o pendiente (nada abonado).
     */
    private function decidirEstadoCorrecto(Relacion $relacion, string $hoy): string
    {
        if ($relacion->fecha_limite_pago !== null && $relacion->fecha_limite_pago->toDateString() < $hoy) {
            return 'vencida';
        }

        return (float) $relacion->total_abonado > 0 ? 'parcial' : 'pendiente';
    }
}
