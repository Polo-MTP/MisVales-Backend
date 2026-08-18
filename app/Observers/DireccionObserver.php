<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Direccion;
use App\Services\Geocoding\GeocodingService;

/**
 * Geocodifica automáticamente cada dirección nueva o cuyos campos de ubicación cambiaron,
 * para que el Verificador pueda ver el pin en el mapa durante la visita física de campo.
 */
final class DireccionObserver
{
    public function __construct(
        private readonly GeocodingService $geocodingService,
    ) {}

    public function created(Direccion $direccion): void
    {
        $this->geocodificar($direccion);
    }

    public function updated(Direccion $direccion): void
    {
        if ($direccion->wasChanged(['calle', 'numero_ext', 'colonia', 'ciudad', 'estado', 'codigo_postal'])) {
            $this->geocodificar($direccion);
        }
    }

    private function geocodificar(Direccion $direccion): void
    {
        $coordenadas = $this->geocodingService->geocodificar($direccion);

        if ($coordenadas === null) {
            return;
        }

        $direccion->latitud = $coordenadas['lat'];
        $direccion->longitud = $coordenadas['lng'];

        // saveQuietly: si no, un 'updated' aquí volvería a disparar este mismo observer.
        $direccion->saveQuietly();
    }
}
