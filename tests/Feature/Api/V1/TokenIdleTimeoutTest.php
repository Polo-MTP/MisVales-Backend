<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

function crearUsuarioConToken(): array
{
    $role = Role::firstOrCreate(['name' => 'Gerente General']);
    $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    $plainTextToken = $user->createToken('auth-token')->plainTextToken;

    return [$user, $plainTextToken];
}

it('la primera petición tras el login no se rechaza (no hay actividad previa que comparar)', function (): void {
    [, $plainTextToken] = crearUsuarioConToken();

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plainTextToken}"])
        ->assertStatus(200);

    $token = PersonalAccessToken::findToken($plainTextToken);
    expect($token->last_activity_at)->not->toBeNull();
});

it('sigue viva mientras haya actividad dentro de la ventana de inactividad permitida', function (): void {
    [, $plainTextToken] = crearUsuarioConToken();

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plainTextToken}"])->assertStatus(200);

    $this->travel(10)->minutes();

    // A los 10 min (< 15 configurados) sigue viva, y esta petición vuelve a correr el reloj.
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plainTextToken}"])->assertStatus(200);

    $this->travel(10)->minutes();

    // Otros 10 min desde la ÚLTIMA actividad (no desde el login) -> sigue dentro de la ventana.
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plainTextToken}"])->assertStatus(200);
});

it('se cierra por inactividad tras pasar el límite configurado sin actividad', function (): void {
    [, $plainTextToken] = crearUsuarioConToken();

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plainTextToken}"])->assertStatus(200);

    $this->travel((int) config('security.idle_timeout_minutes') + 1)->minutes();

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plainTextToken}"])
        ->assertStatus(401)
        ->assertJson([
            'success' => false,
            'message' => 'Tu sesión se cerró por inactividad. Inicia sesión de nuevo.',
        ]);

    expect(PersonalAccessToken::findToken($plainTextToken))->toBeNull();
});

it('el token deja de servir tras pasar su expiración absoluta', function (): void {
    config(['sanctum.expiration' => 5]);

    [, $plainTextToken] = crearUsuarioConToken();

    // Se atrasa el created_at directo en vez de viajar en el tiempo entre dos peticiones: el
    // guard de Sanctum queda cacheado dentro de un mismo test (Illuminate\Auth\RequestGuard
    // memoiza el usuario ya resuelto), así que una segunda petición en el mismo proceso de
    // prueba no vuelve a evaluar la expiración — no pasa en producción real, donde cada
    // request es un proceso nuevo. Con una sola petición se prueba la misma condición sin
    // depender de ese artefacto del entorno de pruebas.
    PersonalAccessToken::findToken($plainTextToken)->forceFill(['created_at' => now()->subMinutes(6)])->save();

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plainTextToken}"])
        ->assertStatus(401)
        ->assertJson([
            'success' => false,
            'message' => 'No autenticado. Inicia sesión para continuar.',
        ]);
});
