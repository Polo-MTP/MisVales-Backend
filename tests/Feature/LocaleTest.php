<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;

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

it('la regla exists de "dar de alta Gerente de Sucursal" con una sucursal inexistente sale en español', function (): void {
    // 'forgot-password' YA NO usa 'exists' a propósito (ver auditoría de seguridad: usarlo ahí
    // era un oráculo para enumerar correos registrados). Se prueba la localización de 'exists'
    // aquí en su lugar, donde sí es seguro exponer el resultado (endpoint de staff, no público).
    $role = Role::firstOrCreate(['name' => 'Gerente General'], ['factor_count' => 3]);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

    $this->postJson('/api/v1/usuarios/gerente-sucursal', [
        'name' => 'Nuevo Gerente',
        'email' => 'nuevo.gerente.locale@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        'sucursal_id' => 999999,
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.sucursal_id.0', 'El sucursal id seleccionado no es válido.');
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
