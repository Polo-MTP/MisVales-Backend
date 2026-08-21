<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Relacion\RelacionCalculoService;
use Illuminate\Console\Command;

final class GenerarCortesRelaciones extends Command
{
    protected $signature = 'relaciones:generar-cortes {--fecha= : Fecha de corte a evaluar (YYYY-MM-DD), por defecto hoy}';

    protected $description = 'Genera la relación (corte) de todas las distribuidoras cuya sucursal tiene hoy su día de corte configurado.';

    /**
     * Genera el corte del día (o de la fecha indicada) para las distribuidoras que correspondan.
     */
    public function handle(RelacionCalculoService $relacionCalculoService): int
    {
        $fecha = $this->option('fecha');

        $resultado = $relacionCalculoService->generarCortesDelDia($fecha);

        $this->info(count($resultado['generadas']).' relación(es) generada(s).');

        if ($resultado['errores'] !== []) {
            $this->error(count($resultado['errores']).' distribuidora(s) fallaron y se omitieron:');
            foreach ($resultado['errores'] as $distribuidoraId => $mensaje) {
                $this->error("  - distribuidora #{$distribuidoraId}: {$mensaje}");
            }
        }

        return self::SUCCESS;
    }
}
