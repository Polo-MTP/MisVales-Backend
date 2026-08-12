<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Mail::fake();
});

function crearUsuarioConRol(string $nombreRol, string $password = 'Password123!'): User
{
    $role = Role::query()->where('name', $nombreRol)->firstOrFail();

    return User::factory()->create([
        'password' => bcrypt($password),
        'role_id' => $role->id,
    ]);
}

/**
 * Recorre el setup completo del segundo factor (TOTP) para un usuario y regresa
 * [mfa_method_id, secretKey] ya confirmado y verificado.
 *
 * @return array{0: string, 1: string}
 */
function configurarSegundoFactor(Tests\TestCase $test, User $user): array
{
    $setup = $test->getJson('/api/v1/mfa/setup?email='.urlencode($user->email))
        ->assertStatus(200)
        ->json('data');

    $codigo = (new Google2FA())->getCurrentOtp($setup['secretKey']);

    $test->postJson('/api/v1/mfa/setup/confirm', [
        'mfa_method_id' => $setup['mfa_method_id'],
        'code' => $codigo,
    ])->assertStatus(200)->assertJson(['success' => true]);

    return [$setup['mfa_method_id'], $setup['secretKey']];
}

it('un usuario nuevo con rol de 2 factores debe configurar MFA antes de poder iniciar sesión', function (): void {
    $user = crearUsuarioConRol('Cajera');

    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Password123!'])
        ->assertStatus(200)
        ->assertJson(['success' => true, 'data' => ['requires_setup' => true, 'email' => $user->email]]);
});

it('flujo completo de 2 factores: setup, confirmar y login con código entrega el token', function (): void {
    $user = crearUsuarioConRol('Cajera');

    [$mfaMethodId, $secretKey] = configurarSegundoFactor($this, $user);

    $login = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Password123!'])
        ->assertStatus(200)
        ->assertJson(['success' => true, 'data' => ['requires_mfa' => true, 'mfa_method_id' => $mfaMethodId]]);

    $codigo = (new Google2FA())->getCurrentOtp($secretKey);

    $this->postJson('/api/v1/mfa/verify', ['mfa_method_id' => $mfaMethodId, 'code' => $codigo])
        ->assertStatus(200)
        ->assertJsonStructure(['success', 'message', 'data' => ['user' => ['id', 'email'], 'token']])
        ->assertJson(['success' => true]);
});

it('rechaza un código TOTP incorrecto en la verificación de login', function (): void {
    $user = crearUsuarioConRol('Cajera');
    [$mfaMethodId] = configurarSegundoFactor($this, $user);

    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Password123!'])
        ->assertStatus(200);

    $this->postJson('/api/v1/mfa/verify', ['mfa_method_id' => $mfaMethodId, 'code' => '000000'])
        ->assertStatus(401)
        ->assertJson(['success' => false, 'message' => 'El código de la App es incorrecto.']);
});

it('flujo completo de 3 factores: tras el TOTP pide un código por correo antes de entregar el token', function (): void {
    $user = crearUsuarioConRol('Gerente General');
    [$mfaMethodId, $secretKey] = configurarSegundoFactor($this, $user);

    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Password123!'])
        ->assertStatus(200)
        ->assertJson(['data' => ['requires_mfa' => true]]);

    $codigo = (new Google2FA())->getCurrentOtp($secretKey);

    $this->postJson('/api/v1/mfa/verify', ['mfa_method_id' => $mfaMethodId, 'code' => $codigo])
        ->assertStatus(200)
        ->assertJson(['success' => true, 'data' => ['requires_email_otp' => true, 'user_id' => $user->id]]);

    $codigoCorreo = Cache::get('email_otp_'.$user->id);
    expect($codigoCorreo)->not->toBeNull();

    $this->postJson('/api/v1/mfa/email/verify', ['user_id' => $user->id, 'code' => $codigoCorreo])
        ->assertStatus(200)
        ->assertJsonStructure(['success', 'message', 'data' => ['user' => ['id', 'email'], 'token']])
        ->assertJson(['success' => true]);
});
