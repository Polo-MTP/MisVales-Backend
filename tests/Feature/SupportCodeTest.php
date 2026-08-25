<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * AppendSupportCode es un middleware global (ver bootstrap/app.php) -- estos tests confirman
 * que de verdad alcanza CUALQUIER camino por el que puede salir un error: un renderer de
 * bootstrap/app.php, un middleware que arma su propio json a mano, y el login (que ni
 * siquiera pasa por el catálogo central de errores, arma su propio {message, code} en
 * LoginService). Si alguno de estos tres no trajera el código, significaría que el
 * middleware NO es tan global como se documentó.
 *
 * 'message' se queda intacto a propósito -- el código viaja aparte, en 'support_code'. Es el
 * frontend quien lo pega junto al mensaje al mostrarlo (ver auth.interceptor.ts).
 */
it('agrega support_code a una respuesta armada por un renderer de bootstrap/app.php (VALIDATION_ERROR)', function (): void {
    $role = Role::firstOrCreate(['name' => 'Gerente General']);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

    $response = $this->postJson('/api/v1/usuarios/gerente-sucursal', []);

    $response->assertStatus(422)
        ->assertJsonPath('support_code', ApiErrorCode::VALIDATION_ERROR->supportCode())
        ->assertJsonPath('message', 'Los datos enviados no son válidos.');
});

it('agrega support_code a una respuesta armada a mano por un middleware (ACCOUNT_INACTIVE)', function (): void {
    $role = Role::firstOrCreate(['name' => 'Cajera']);
    $user = User::factory()->create(['role_id' => $role->id, 'is_active' => false]);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/me');

    $response->assertStatus(403)
        ->assertJsonPath('support_code', ApiErrorCode::ACCOUNT_INACTIVE->supportCode());
});

it('agrega support_code incluso a un login fallido, que ni pasa por el catálogo central', function (): void {
    $response = $this->postJson('/api/v1/login', [
        'email' => 'no-existe@example.com', 'password' => 'lo-que-sea', 'recaptcha' => 'bypass-recaptcha',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('support_code', ApiErrorCode::UNAUTHENTICATED->supportCode());
});

it('no toca en absoluto una respuesta exitosa', function (): void {
    $response = $this->getJson('/api/v1/status');

    $response->assertStatus(200)->assertJsonMissingPath('support_code');
});
