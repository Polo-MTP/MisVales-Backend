<?php

declare(strict_types=1);

use App\Models\Direccion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function datosDireccion(): array
{
    return [
        'calle' => 'Blvd. Miguel Alemán 500',
        'colonia' => 'Centro',
        'numero_ext' => '500',
        'numero_int' => null,
        'codigo_postal' => '35000',
        'estado' => 'Durango',
        'ciudad' => 'Gómez Palacio',
    ];
}

it('geocodifica automáticamente una dirección nueva contra Google y guarda latitud/longitud', function (): void {
    config(['services.google_maps.geocoding_key' => 'test-key']);

    Http::fake([
        'maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [
                ['geometry' => ['location' => ['lat' => 25.5428, 'lng' => -103.4968]]],
            ],
        ]),
    ]);

    $direccion = Direccion::create(datosDireccion());

    expect((float) $direccion->fresh()->latitud)->toBe(25.5428)
        ->and((float) $direccion->fresh()->longitud)->toBe(-103.4968);

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'maps.googleapis.com'));
});

it('vuelve a geocodificar cuando la calle cambia, pero no cuando solo cambia un dato ajeno', function (): void {
    config(['services.google_maps.geocoding_key' => 'test-key']);

    Http::fake([
        'maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [['geometry' => ['location' => ['lat' => 1.0, 'lng' => 2.0]]]],
        ]),
    ]);

    $direccion = Direccion::create(datosDireccion());
    Http::assertSentCount(1);

    $direccion->update(['numero_int' => 'A']);
    Http::assertSentCount(1);

    $direccion->update(['calle' => 'Otra calle 123']);
    Http::assertSentCount(2);
});

it('no llama a Google si no hay GOOGLE_MAPS_API_KEY configurada', function (): void {
    config(['services.google_maps.geocoding_key' => null]);

    Http::fake();

    $direccion = Direccion::create(datosDireccion());

    expect($direccion->fresh()->latitud)->toBeNull();
    Http::assertNothingSent();
});

it('no revienta el alta si la llamada a Google falla, solo deja latitud/longitud en null', function (): void {
    config(['services.google_maps.geocoding_key' => 'test-key']);

    Http::fake(['maps.googleapis.com/*' => Http::response([], 500)]);

    $direccion = Direccion::create(datosDireccion());

    expect($direccion->fresh())->not->toBeNull()
        ->and($direccion->fresh()->latitud)->toBeNull();
});
