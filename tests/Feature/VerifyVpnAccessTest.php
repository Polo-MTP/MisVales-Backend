<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * VerifyVpnAccess es la segunda capa de defensa sobre el firewall real del droplet —
 * mientras config('security.vpn_allowed_cidrs') esté vacío (infra aún no da el rango
 * real), no debe bloquear nada, para no tumbar estas rutas en dev/test.
 */
it('deja pasar cualquier IP cuando no hay CIDRs configurados', function (): void {
    config(['security.vpn_allowed_cidrs' => []]);
    Route::middleware(['api', 'vpn'])->get('/__test/vpn-sin-configurar', fn () => response()->json(['ok' => true]));

    $this->getJson('/__test/vpn-sin-configurar')->assertStatus(200)->assertJson(['ok' => true]);
});

it('deja pasar una IP dentro del CIDR configurado', function (): void {
    config(['security.vpn_allowed_cidrs' => ['127.0.0.1/32']]);
    Route::middleware(['api', 'vpn'])->get('/__test/vpn-ip-permitida', fn () => response()->json(['ok' => true]));

    $this->getJson('/__test/vpn-ip-permitida')->assertStatus(200)->assertJson(['ok' => true]);
});

it('bloquea con 403 una IP fuera del CIDR configurado', function (): void {
    config(['security.vpn_allowed_cidrs' => ['10.0.0.0/24']]);
    Route::middleware(['api', 'vpn'])->get('/__test/vpn-ip-bloqueada', fn () => response()->json(['ok' => true]));

    // El cliente de pruebas de Laravel llega como 127.0.0.1, fuera de 10.0.0.0/24.
    $this->getJson('/__test/vpn-ip-bloqueada')
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
