<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Services\Auth\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function intentarLoginFallido(User $user): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'contraseña-incorrecta',
        'recaptcha' => 'bypass-recaptcha',
    ]);
}

/**
 * procesarContrasenaIncorrecta() hacía failed_attempts += 1 sobre la instancia ya cargada y
 * guardaba, sin lockForUpdate() -- bajo intentos concurrentes para la MISMA cuenta, dos
 * peticiones podían leer el mismo valor viejo y la segunda en guardar pisaba el conteo de la
 * primera (se perdía un intento). No es reproducible con concurrencia real en un solo proceso
 * de test, así que esto confirma el comportamiento secuencial normal sigue intacto tras
 * envolver el incremento en una transacción con lock.
 */
it('bloquea la cuenta al quinto intento fallido seguido, y el sexto ya no revisa la contraseña', function (): void {
    $role = Role::firstOrCreate(['name' => 'Cajera']);
    $user = User::factory()->create(['role_id' => $role->id, 'password' => bcrypt('correcta123'), 'is_active' => true]);

    for ($i = 1; $i <= 4; $i++) {
        intentarLoginFallido($user)->assertStatus(401);
        expect($user->fresh()->failed_attempts)->toBe($i)
            ->and($user->fresh()->is_locked)->toBeFalse();
    }

    // 5o intento: cruza el umbral y bloquea.
    intentarLoginFallido($user)->assertStatus(401);
    expect($user->fresh()->failed_attempts)->toBe(5)
        ->and($user->fresh()->is_locked)->toBeTrue()
        ->and($user->fresh()->locked_until)->not->toBeNull();

    // Ya bloqueada: aunque mande la contraseña CORRECTA, se rechaza sin ni siquiera revisarla.
    // Vía el servicio directo (no HTTP) para no mezclar esta aserción con throttle:auth
    // (5/min por IP), que es un límite aparte, ya cubierto por sus propios tests.
    $resultado = app(LoginService::class)->login(
        ['email' => $user->email, 'password' => 'correcta123'],
        '127.0.0.1'
    );

    expect($resultado['success'])->toBeFalse()
        ->and($resultado['code'])->toBe(403);
});

it('un login exitoso resetea failed_attempts y el bloqueo', function (): void {
    $role = Role::firstOrCreate(['name' => 'Cajera']);
    $user = User::factory()->create(['role_id' => $role->id, 'password' => bcrypt('correcta123'), 'is_active' => true]);

    intentarLoginFallido($user)->assertStatus(401);
    intentarLoginFallido($user)->assertStatus(401);
    expect($user->fresh()->failed_attempts)->toBe(2);

    test()->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'correcta123',
        'recaptcha' => 'bypass-recaptcha',
    ])->assertStatus(200);

    expect($user->fresh()->failed_attempts)->toBe(0)
        ->and($user->fresh()->is_locked)->toBeFalse();
});
