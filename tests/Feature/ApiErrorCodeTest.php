<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Toda respuesta {success: false, ...} de la API trae 'error_code' -- un identificador
 * estable en inglés (ver App\Enums\ApiErrorCode) para que un cliente distinga el TIPO de
 * error sin parsear 'message' (texto en español, puede cambiar de redacción).
 */
it('una petición sin autenticar trae error_code UNAUTHENTICATED', function (): void {
    $this->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error_code', ApiErrorCode::UNAUTHENTICATED->value);
});

/**
 * getJson() siempre manda "Accept: application/json" por debajo -- igual que el frontend real
 * (ver auth.interceptor.ts), pero eso significaba que ESTE caso nunca se probaba: sin ese
 * header, Authenticate::redirectTo() de Laravel intentaba route('login') para armar un
 * redirect -- esta app es 100% API, esa ruta no existe, y construir esa URL aventaba
 * RouteNotFoundException sin capturar ANTES de que la excepción de autenticación se terminara
 * de armar, resultando en 500 SERVER_ERROR en vez de 401 UNAUTHENTICATED. Un curl suelto,
 * Postman sin configurar, o cualquier cliente que no sea este frontend lo hubiera pisado.
 * Corregido con Authenticate::redirectUsing(fn () => null) en bootstrap/app.php.
 */
it('una petición sin autenticar y SIN header Accept:application/json también trae error_code UNAUTHENTICATED (no 500)', function (): void {
    $this->get('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error_code', ApiErrorCode::UNAUTHENTICATED->value);
});

it('un rol sin permiso para la ruta trae error_code FORBIDDEN', function (): void {
    $role = Role::firstOrCreate(['name' => 'Cajera']);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

    $this->postJson('/api/v1/usuarios/administrador', ['name' => 'X', 'email' => 'x@example.com'])
        ->assertStatus(403)
        ->assertJsonPath('error_code', ApiErrorCode::FORBIDDEN->value);
});

it('una ruta inexistente trae error_code NOT_FOUND', function (): void {
    $this->getJson('/api/v1/esto-no-existe')
        ->assertStatus(404)
        ->assertJsonPath('error_code', ApiErrorCode::NOT_FOUND->value);
});

it('datos inválidos en una solicitud traen error_code VALIDATION_ERROR', function (): void {
    $role = Role::firstOrCreate(['name' => 'Gerente General']);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

    $this->postJson('/api/v1/usuarios/administrador', [])
        ->assertStatus(422)
        ->assertJsonPath('error_code', ApiErrorCode::VALIDATION_ERROR->value);
});

it('una petición bloqueada por VPN trae error_code VPN_REQUIRED', function (): void {
    config(['security.vpn_host' => 'vpn.misvales.test']);
    Route::middleware(['api', 'auth:sanctum', 'vpn'])->get('/__test/error-code-vpn', fn () => response()->json(['ok' => true]));
    $role = Role::firstOrCreate(['name' => 'Gerente General']);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

    $this->getJson('http://api.misvales.test/__test/error-code-vpn')
        ->assertStatus(403)
        ->assertJsonPath('error_code', ApiErrorCode::VPN_REQUIRED->value);
});

/**
 * NOTA: no hay aquí un test de integración HTTP completo para SESSION_IDLE_TIMEOUT --
 * ese escenario (login -> viajar en el tiempo más allá del límite -> siguiente petición
 * 401) ya tiene cobertura en TokenIdleTimeoutTest, y reproduce ahí una falla preexistente
 * de este entorno de pruebas sin relación con error_code (confirmado: falla igual en la
 * rama sin estos cambios). El código en sí es una sola línea agregada a la respuesta ya
 * existente de EnsureTokenNotIdle -- ver ese archivo.
 */

it('una cuenta desactivada trae error_code ACCOUNT_INACTIVE', function (): void {
    $role = Role::firstOrCreate(['name' => 'Cajera']);
    $user = User::factory()->create(['role_id' => $role->id, 'is_active' => false]);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')
        ->assertStatus(403)
        ->assertJsonPath('error_code', ApiErrorCode::ACCOUNT_INACTIVE->value);
});

it('un DomainException (regla de negocio) trae error_code DOMAIN_ERROR', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $roleGerente = Role::firstOrCreate(['name' => 'Gerente General']);
    $roleDist = Role::firstOrCreate(['name' => 'Distribuidora']);
    $gerente = User::factory()->create(['role_id' => $roleGerente->id, 'is_active' => true]);
    $usuarioDist = User::factory()->create(['role_id' => $roleDist->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $distribuidora = \App\Models\Distribuidora::create([
        'usuario_id' => $usuarioDist->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 10000,
        'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    // Solicitud ya resuelta -- decidir() sobre ella dispara DomainException('Esta solicitud ya fue resuelta.').
    $solicitud = \App\Models\SolicitudAumentoCredito::create([
        'distribuidora_id' => $distribuidora->id, 'solicitado_por' => $usuarioDist->id,
        'limite_credito_anterior' => 10000, 'monto_solicitado' => 15000, 'monto_otorgado' => 15000,
        'motivo' => 'test', 'estado' => 'aprobada', 'fecha_decision' => now(), 'decidido_por' => $gerente->id,
    ]);

    Sanctum::actingAs($gerente);

    // El controller atrapa el DomainException y responde vía $this->error() sin status
    // explícito -- default 400 (Response::HTTP_BAD_REQUEST), no 422.
    $this->putJson("/api/v1/distribuidoras/aumento-credito/{$solicitud->id}/decidir", ['decision' => 'aprobada', 'monto_otorgado' => 100])
        ->assertStatus(400)
        ->assertJsonPath('error_code', ApiErrorCode::DOMAIN_ERROR->value);
});
