<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use App\Models\Direccion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Convierte una Direccion en coordenadas vía Google Geocoding API. Es un enriquecimiento
 * best-effort para que el Verificador vea el pin en el mapa durante la visita física: si
 * falla (sin API key, sin resultados, timeout), nunca debe tumbar el alta/edición de la
 * dirección — solo se registra el problema y latitud/longitud quedan en null.
 */
final class GeocodingService
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function geocodificar(Direccion $direccion): ?array
    {
        $apiKey = config('services.google_maps.geocoding_key');

        if (empty($apiKey)) {
            return null;
        }

        $direccionCompleta = trim(sprintf(
            '%s %s, %s, %s, %s, %s, México',
            $direccion->calle,
            $direccion->numero_ext,
            $direccion->colonia,
            $direccion->ciudad,
            $direccion->estado,
            $direccion->codigo_postal,
        ));

        try {
            $response = Http::timeout(5)->get(self::ENDPOINT, [
                'address' => $direccionCompleta,
                'region' => 'mx',
                'key' => $apiKey,
            ]);
        } catch (Throwable $e) {
            Log::warning('GeocodingService: fallo de conexión al geocodificar dirección', [
                'direccion_id' => $direccion->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        /** @var array{status?: string, results?: array<int, array{geometry?: array{location?: array{lat?: float, lng?: float}}}>} $body */
        $body = $response->json() ?? [];

        $location = $body['results'][0]['geometry']['location'] ?? null;

        if (($body['status'] ?? null) !== 'OK' || $location === null) {
            Log::info('GeocodingService: sin coincidencias para la dirección', [
                'direccion_id' => $direccion->id,
                'status' => $body['status'] ?? null,
            ]);

            return null;
        }

        return ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng']];
    }
}
