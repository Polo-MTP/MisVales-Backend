<?php

declare(strict_types=1);

use App\Console\Commands\GenerarCortesRelaciones;
use App\Console\Commands\MarcarRelacionesVencidas;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// El corte de cada sucursal puede caer en dos días distintos del mes (dia_corte/dia_corte_2
// en ConfiguracionFechas), así que se evalúa todos los días; el comando internamente filtra
// qué distribuidoras tienen corte hoy.
Schedule::command(GenerarCortesRelaciones::class)->dailyAt('01:00');
Schedule::command(MarcarRelacionesVencidas::class)->dailyAt('01:30');
