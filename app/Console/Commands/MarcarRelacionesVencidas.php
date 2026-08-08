<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Relacion\RelacionEstadoService;
use Illuminate\Console\Command;

final class MarcarRelacionesVencidas extends Command
{
    protected $signature = 'relaciones:marcar-vencidas {--fecha= : Fecha de referencia (YYYY-MM-DD), por defecto hoy}';

    protected $description = 'Marca como vencidas las relaciones pendientes/parciales cuya fecha límite de pago ya pasó.';

    public function handle(RelacionEstadoService $relacionEstadoService): int
    {
        $total = $relacionEstadoService->marcarVencidas($this->option('fecha'));

        $this->info("{$total} relación(es) marcada(s) como vencida(s).");

        return self::SUCCESS;
    }
}
