<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * SecurityHeaders existía como middleware con alias registrado pero nunca adjunto a
 * ninguna ruta/grupo, así que ninguna respuesta llevaba estos encabezados. Ahora se
 * aplica a todo el grupo 'api'.
 */
it('toda respuesta de la API incluye los encabezados de seguridad', function (): void {
    $role = Role::firstOrCreate(['name' => 'Gerente General'], ['factor_count' => 1]);
    $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/me');

    $response->assertStatus(200)
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy')
        ->assertHeader('X-Server-Number');
});

/**
 * SERVER_NUMBER solo se exponía en el body de GET /status, llamado una sola vez al cargar el
 * login -- detrás de un balanceador con varias instancias, soporte no tenía forma de saber
 * cuál sirvió cualquier OTRA petición para reproducir un problema, ni siquiera una que falló.
 * Ahora va en el header de TODA respuesta, incluida una de error: SecurityHeaders envuelve el
 * request completo, y una excepción no atrapada ya se convirtió en Response (por
 * withExceptions() en bootstrap/app.php) antes de burbujear de vuelta hasta el middleware.
 */
it('el header X-Server-Number llega incluso en una respuesta de error (404)', function (): void {
    config(['app.server_number' => '7']);

    $response = $this->getJson('/api/v1/ruta-que-no-existe-para-nada');

    $response->assertStatus(404)
        ->assertHeader('X-Server-Number', '7');
});

it('el header X-Server-Number llega en una respuesta de error de validación (422)', function (): void {
    config(['app.server_number' => '7']);

    $response = $this->postJson('/api/v1/login', []);

    $response->assertStatus(422)
        ->assertHeader('X-Server-Number', '7');
});
