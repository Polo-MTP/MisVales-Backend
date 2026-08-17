<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

/**
 * lang/es/ no existía y APP_LOCALE=en: cualquier regla nativa de Laravel (no escrita a
 * mano por nosotros) salía en inglés, a veces con detalle técnico. Con APP_LOCALE=es y
 * los archivos de idioma publicados, esto se resuelve para cualquier regla presente y
 * futura sin tener que tocar cada FormRequest.
 */
it('un campo requerido faltante devuelve el mensaje nativo de validación en español', function (): void {
    // ForgotPasswordRequest no sobreescribe messages() (a diferencia de LoginRequest, que
    // sí trae sus propios mensajes a mano) — este 'required' sale tal cual de lang/es/.
    $this->postJson('/api/v1/forgot-password', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'El campo correo electrónico es obligatorio.');
});

it('la regla exists de "olvidé mi contraseña" con un correo inexistente sale en español', function (): void {
    $this->postJson('/api/v1/forgot-password', ['email' => 'no-existe@correo.com'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'El correo electrónico seleccionado no es válido.');
});

it('la regla de complejidad de contraseña (mixedCase) sale en español', function (): void {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->postJson('/api/v1/reset-password', [
        'email' => $user->email,
        'token' => $token,
        'password' => 'todominusculas123',
        'password_confirmation' => 'todominusculas123',
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'El campo contraseña debe contener al menos una mayúscula y una minúscula.');
});
