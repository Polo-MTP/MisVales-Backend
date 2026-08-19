<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * VerifyVpnAccess es la segunda capa de defensa sobre el firewall/DNS real del droplet —
 * mientras config('security.vpn_host') esté vacío (infra aún no da el dominio real), no
 * debe bloquear nada, para no tumbar estas rutas en dev/test. La detección es por el host
 * de la petición, no por IP: evita depender de X-Forwarded-For/TRUSTED_PROXIES.
 */
it('deja pasar cualquier host cuando no hay VPN_HOST configurado', function (): void {
    config(['security.vpn_host' => '']);
    Route::middleware(['api', 'vpn'])->get('/__test/vpn-sin-configurar', fn () => response()->json(['ok' => true]));

    $this->getJson('/__test/vpn-sin-configurar')->assertStatus(200)->assertJson(['ok' => true]);
});

it('deja pasar una petición que llega por el host de la VPN', function (): void {
    config(['security.vpn_host' => 'vpn.misvales.test']);
    Route::middleware(['api', 'vpn'])->get('/__test/vpn-host-permitido', fn () => response()->json(['ok' => true]));

    // Se simula el host con una URL absoluta: Request::create() toma el host de la propia
    // URI, así que un header 'Host' por separado no basta (Symfony lo sobreescribe con el
    // host de la URI si esta trae uno).
    $this->getJson('http://vpn.misvales.test/__test/vpn-host-permitido')
        ->assertStatus(200)->assertJson(['ok' => true]);
});

it('la comparación de host no distingue mayúsculas/minúsculas', function (): void {
    config(['security.vpn_host' => 'vpn.misvales.test']);
    Route::middleware(['api', 'vpn'])->get('/__test/vpn-host-mayusculas', fn () => response()->json(['ok' => true]));

    $this->getJson('http://VPN.MISVALES.TEST/__test/vpn-host-mayusculas')
        ->assertStatus(200)->assertJson(['ok' => true]);
});

it('bloquea con 403 una petición que llega por el host público', function (): void {
    config(['security.vpn_host' => 'vpn.misvales.test']);
    Route::middleware(['api', 'vpn'])->get('/__test/vpn-host-bloqueado', fn () => response()->json(['ok' => true]));

    $this->getJson('http://api.misvales.test/__test/vpn-host-bloqueado')
        ->assertStatus(403)
        ->assertJson([
            'success' => false,
            'message' => 'Esta acción solo está disponible desde la red autorizada.',
        ]);
});

it('las dos rutas de decisión confirmadas por infra tienen el middleware vpn adjunto', function (): void {
    $decidirConciliacion = Route::getRoutes()->getByName('api.v1.conciliaciones.decidir_autorizacion');
    $decidirEdicion = Route::getRoutes()->getByName('api.v1.distribuidora.clientes.decidir_edicion');

    expect($decidirConciliacion)->not->toBeNull()
        ->and($decidirConciliacion->middleware())->toContain('vpn')
        ->and($decidirEdicion)->not->toBeNull()
        ->and($decidirEdicion->middleware())->toContain('vpn');
});
