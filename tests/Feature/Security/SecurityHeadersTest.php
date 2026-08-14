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
        ->assertHeader('Content-Security-Policy');
});
