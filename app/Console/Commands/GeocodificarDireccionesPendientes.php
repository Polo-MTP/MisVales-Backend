<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Direccion;
use App\Services\Geocoding\GeocodingService;
use Illuminate\Console\Command;

final class GeocodificarDireccionesPendientes extends Command
{
    protected $signature = 'direcciones:geocodificar-pendientes {--limite=200 : Máximo de direcciones a procesar en esta corrida}';

    protected $description = 'Geocodifica direcciones sin latitud/longitud (creadas antes de que existiera este proceso automático).';

    /**
     * Backfill único para direcciones que ya existían antes de DireccionObserver: de ahí en
     * adelante, toda dirección nueva o editada se geocodifica sola al guardarse.
     */
    public function handle(GeocodingService $geocodingService): int
    {
        $limite = (int) $this->option('limite');

        $pendientes = Direccion::query()
            ->whereNull('latitud')
            ->orWhereNull('longitud')
            ->limit($limite)
            ->get();

        if ($pendientes->isEmpty()) {
            $this->info('No hay direcciones pendientes de geocodificar.');

            return self::SUCCESS;
        }

        $geocodificadas = 0;

        foreach ($pendientes as $direccion) {
            $coordenadas = $geocodingService->geocodificar($direccion);

            if ($coordenadas === null) {
                continue;
            }

            $direccion->latitud = $coordenadas['lat'];
            $direccion->longitud = $coordenadas['lng'];
            $direccion->saveQuietly();
            $geocodificadas++;
        }

        $this->info("{$geocodificadas} de {$pendientes->count()} dirección(es) geocodificada(s).");

        return self::SUCCESS;
    }
}
