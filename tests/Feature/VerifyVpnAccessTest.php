<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * VerifyVpnAccess es la segunda capa de defensa sobre el firewall/DNS real del droplet —
 * mientras config('security.vpn_host') esté vacío (infra aún no da el dominio real), no
 * debe bloquear nada, para no tumbar estas rutas en dev/test. La detección es por el host
 * de la petición, no por IP: evita depender de X-Forwarded-For/TRUSTED_PROXIES.
 *
 * Solo exige VPN a Gerente General y Gerente de Sucursal — son quienes de verdad autorizan.
 * Otros roles que comparten la misma ruta (Verificador, Coordinador) pasan sin más.
 */
function crearUsuarioConRolVpn(string $rol): User
{
    $role = Role::firstOrCreate(['name' => $rol]);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

it('deja pasar cualquier host cuando no hay VPN_HOST configurado', function (): void {
    config(['security.vpn_host' => '']);
    Route::middleware(['api', 'auth:sanctum', 'vpn'])->get('/__test/vpn-sin-configurar', fn () => response()->json(['ok' => true]));
    Sanctum::actingAs(crearUsuarioConRolVpn('Gerente General'));

    $this->getJson('/__test/vpn-sin-configurar')->assertStatus(200)->assertJson(['ok' => true]);
});

it('deja pasar una petición que llega por el host de la VPN', function (): void {
    config(['security.vpn_host' => 'vpn.misvales.test']);
    Route::middleware(['api', 'auth:sanctum', 'vpn'])->get('/__test/vpn-host-permitido', fn () => response()->json(['ok' => true]));
    Sanctum::actingAs(crearUsuarioConRolVpn('Gerente General'));

    // Se simula el host con una URL absoluta: Request::create() toma el host de la propia
    // URI, así que un header 'Host' por separado no basta (Symfony lo sobreescribe con el
    // host de la URI si esta trae uno).
    $this->getJson('http://vpn.misvales.test/__test/vpn-host-permitido')
        ->assertStatus(200)->assertJson(['ok' => true]);
});

it('la comparación de host no distingue mayúsculas/minúsculas', function (): void {
    config(['security.vpn_host' => 'vpn.misvales.test']);
    Route::middleware(['api', 'auth:sanctum', 'vpn'])->get('/__test/vpn-host-mayusculas', fn () => response()->json(['ok' => true]));
    Sanctum::actingAs(crearUsuarioConRolVpn('Gerente General'));

    $this->getJson('http://VPN.MISVALES.TEST/__test/vpn-host-mayusculas')
        ->assertStatus(200)->assertJson(['ok' => true]);
});

it('bloquea con 403 al Gerente General que llega por el host público', function (): void {
    config(['security.vpn_host' => 'vpn.misvales.test']);
    Route::middleware(['api', 'auth:sanctum', 'vpn'])->get('/__test/vpn-host-bloqueado-gg', fn () => response()->json(['ok' => true]));
    Sanctum::actingAs(crearUsuarioConRolVpn('Gerente General'));

    $this->getJson('http://api.misvales.test/__test/vpn-host-bloqueado-gg')
        ->assertStatus(403)
        ->assertJson([
            'success' => false,
            'message' => 'Esta acción solo está disponible desde la red autorizada.',
        ]);
});

it('bloquea con 403 al Gerente de Sucursal que llega por el host público', function (): void {
    config(['security.vpn_host' => 'vpn.misvales.test']);
    Route::middleware(['api', 'auth:sanctum', 'vpn'])->get('/__test/vpn-host-bloqueado-gs', fn () => response()->json(['ok' => true]));
    Sanctum::actingAs(crearUsuarioConRolVpn('Gerente de Sucursal'));

    $this->getJson('http://api.misvales.test/__test/vpn-host-bloqueado-gs')
        ->assertStatus(403);
});

it('no bloquea al Verificador aunque llegue por el host público', function (): void {
    config(['security.vpn_host' => 'vpn.misvales.test']);
    Route::middleware(['api', 'auth:sanctum', 'vpn'])->get('/__test/vpn-verificador-publico', fn () => response()->json(['ok' => true]));
    Sanctum::actingAs(crearUsuarioConRolVpn('Verificador'));

    $this->getJson('http://api.misvales.test/__test/vpn-verificador-publico')
        ->assertStatus(200)->assertJson(['ok' => true]);
});

it('no bloquea al Coordinador aunque llegue por el host público', function (): void {
    config(['security.vpn_host' => 'vpn.misvales.test']);
    Route::middleware(['api', 'auth:sanctum', 'vpn'])->get('/__test/vpn-coordinador-publico', fn () => response()->json(['ok' => true]));
    Sanctum::actingAs(crearUsuarioConRolVpn('Coordinador'));

    $this->getJson('http://api.misvales.test/__test/vpn-coordinador-publico')
        ->assertStatus(200)->assertJson(['ok' => true]);
});

it('las dos rutas de decisión confirmadas por infra tienen el middleware vpn adjunto', function (): void {
    $decidirConciliacion = Route::getRoutes()->getByName('api.v1.conciliaciones.decidir_autorizacion');
    $decidirEdicion = Route::getRoutes()->getByName('api.v1.distribuidora.clientes.decidir_edicion');

    expect($decidirConciliacion)->not->toBeNull()
        ->and($decidirConciliacion->middleware())->toContain('vpn')
        ->and($decidirEdicion)->not->toBeNull()
        ->and($decidirEdicion->middleware())->toContain('vpn');
});

/**
 * Confirmado con el equipo: dar de alta cuentas de staff (Gerente de Sucursal, Administrador,
 * Coordinador/Verificador/Cajera) NO exige VPN -- a diferencia de las decisiones de
 * autorización (aprobar/rechazar solicitudes, cambiar parámetros de negocio), que sí. Debe
 * poder crearse personal también desde la red pública.
 */
it('la alta de Gerente de Sucursal, Administrador, Gerente General y Personal de Sucursal NO exige VPN', function (): void {
    $rutas = [
        'api.v1.usuarios.crear_gerente_sucursal',
        'api.v1.usuarios.crear_administrador',
        'api.v1.usuarios.crear_gerente_general',
        'api.v1.usuarios.crear_personal_sucursal',
    ];

    foreach ($rutas as $nombre) {
        $ruta = Route::getRoutes()->getByName($nombre);

        expect($ruta)->not->toBeNull("La ruta '{$nombre}' no existe.")
            ->and($ruta->middleware())->not->toContain('vpn');
    }
});

/**
 * Cambiar los parámetros de la fórmula (comisión, %quincena, multa, categoría, seguro, fechas
 * de corte) afecta a TODOS los vales que se generen de ahí en adelante — mayor radio de
 * impacto que una decisión puntual sobre un solo caso, así que también exige VPN.
 */
it('los endpoints que modifican parámetros de negocio (comisión/categoría/seguro/fechas) tienen el middleware vpn adjunto', function (): void {
    $nombres = [
        'api.v1.configuraciones.store',
        'api.v1.configuraciones.fechas.store',
        'api.v1.configuraciones.seguros.store',
        'api.v1.configuraciones.seguros.update',
        'api.v1.configuraciones.seguros.destroy',
        'api.v1.categorias_distribuidoras.store',
        'api.v1.categorias_distribuidoras.update',
        'api.v1.categorias_distribuidoras.destroy',
    ];

    foreach ($nombres as $nombre) {
        $route = Route::getRoutes()->getByName($nombre);

        expect($route)->not->toBeNull("La ruta '{$nombre}' no existe.");
        expect($route->middleware())->toContain('vpn');
    }
});
