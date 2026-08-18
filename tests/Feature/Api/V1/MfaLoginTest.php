<?php

declare(strict_types=1);

use App\Models\MfaMethod;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Mail::fake();
    // El store de caché de pruebas (array) vive durante todo el proceso, no por test; sin
    // limpiarlo, el throttle:auth (5/min) compartido entre tests de este archivo se agota
    // antes de llegar a los últimos casos.
    Cache::flush();
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
 * mfa/setup ahora exige una URL firmada (emitida solo tras validar la contraseña en
 * /login) en vez de aceptar cualquier ?email= sin más, así que el helper primero hace
 * login para obtener ese setup_url y solo entonces lo sigue.
 *
 * @return array{0: string, 1: string}
 */
function configurarSegundoFactor(Tests\TestCase $test, User $user, string $password = 'Password123!'): array
{
    $loginData = $test->postJson('/api/v1/login', ['email' => $user->email, 'password' => $password, 'recaptcha' => 'bypass-recaptcha'])
        ->assertStatus(200)
        ->assertJson(['data' => ['requires_setup' => true]])
        ->json('data');

    $setup = $test->getJson($loginData['setup_url'])
        ->assertStatus(200)
        ->json('data');

    $codigo = (new Google2FA())->getCurrentOtp($setup['secretKey']);

    $test->postJson('/api/v1/mfa/setup/confirm', [
        'mfa_method_id' => $setup['mfa_method_id'],
        'code' => $codigo,
        'recaptcha' => 'bypass-recaptcha',
    ])->assertStatus(200)->assertJson(['success' => true]);

    // El setup en sí ya consumió 3 de los 5 cupos del throttle:auth (login + mfa/setup +
    // mfa/setup/confirm), y no es lo que estos tests están verificando; se libera el cupo
    // para que el login/verify que hace el test después de llamar a este helper no choque
    // con el límite de 5/min.
    Cache::flush();

    return [$setup['mfa_method_id'], $setup['secretKey']];
}

it('un usuario nuevo con rol de 2 factores debe configurar MFA antes de poder iniciar sesión', function (): void {
    $user = crearUsuarioConRol('Cajera');

    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Password123!', 'recaptcha' => 'bypass-recaptcha'])
        ->assertStatus(200)
        ->assertJson(['success' => true, 'data' => ['requires_setup' => true, 'email' => $user->email]]);
});

it('flujo completo de 2 factores: setup, confirmar y login con código entrega el token', function (): void {
    $user = crearUsuarioConRol('Cajera');

    [$mfaMethodId, $secretKey] = configurarSegundoFactor($this, $user);

    $login = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Password123!', 'recaptcha' => 'bypass-recaptcha'])
        ->assertStatus(200)
        ->assertJson(['success' => true, 'data' => ['requires_mfa' => true, 'mfa_method_id' => $mfaMethodId]]);

    $codigo = (new Google2FA())->getCurrentOtp($secretKey);

    $this->postJson('/api/v1/mfa/verify', ['mfa_method_id' => $mfaMethodId, 'code' => $codigo, 'recaptcha' => 'bypass-recaptcha'])
        ->assertStatus(200)
        ->assertJsonStructure(['success', 'message', 'data' => ['user' => ['id', 'email'], 'token']])
        ->assertJson(['success' => true]);
});

it('rechaza un código TOTP incorrecto en la verificación de login', function (): void {
    $user = crearUsuarioConRol('Cajera');
    [$mfaMethodId] = configurarSegundoFactor($this, $user);

    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Password123!', 'recaptcha' => 'bypass-recaptcha'])
        ->assertStatus(200);

    $this->postJson('/api/v1/mfa/verify', ['mfa_method_id' => $mfaMethodId, 'code' => '000000', 'recaptcha' => 'bypass-recaptcha'])
        ->assertStatus(401)
        ->assertJson(['success' => false, 'message' => 'El código de la App es incorrecto.']);
});

it('flujo completo de 3 factores: tras el TOTP pide un código por correo antes de entregar el token', function (): void {
    $user = crearUsuarioConRol('Gerente General');
    [$mfaMethodId, $secretKey] = configurarSegundoFactor($this, $user);

    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Password123!', 'recaptcha' => 'bypass-recaptcha'])
        ->assertStatus(200)
        ->assertJson(['data' => ['requires_mfa' => true]]);

    $codigo = (new Google2FA())->getCurrentOtp($secretKey);

    $this->postJson('/api/v1/mfa/verify', ['mfa_method_id' => $mfaMethodId, 'code' => $codigo, 'recaptcha' => 'bypass-recaptcha'])
        ->assertStatus(200)
        ->assertJson(['success' => true, 'data' => ['requires_email_otp' => true, 'user_id' => $user->id]]);

    $codigoCorreo = Cache::get('email_otp_'.$user->id);
    expect($codigoCorreo)->not->toBeNull();

    $this->postJson('/api/v1/mfa/email/verify', ['user_id' => $user->id, 'code' => $codigoCorreo, 'recaptcha' => 'bypass-recaptcha'])
        ->assertStatus(200)
        ->assertJsonStructure(['success', 'message', 'data' => ['user' => ['id', 'email'], 'token']])
        ->assertJson(['success' => true]);
});

it('rechaza pedir el setup de MFA con solo el email, sin la firma emitida por /login', function (): void {
    $user = crearUsuarioConRol('Cajera');

    $this->getJson('/api/v1/mfa/setup?email='.urlencode($user->email))
        ->assertStatus(403);
});

it('el secreto TOTP se guarda cifrado en la base de datos, no en texto plano', function (): void {
    $user = crearUsuarioConRol('Cajera');
    [$mfaMethodId, $secretKey] = configurarSegundoFactor($this, $user);

    $valorCrudoEnBd = DB::table('mfa_methods')->where('id', $mfaMethodId)->value('secret');
    $valorViaEloquent = MfaMethod::query()->find($mfaMethodId)->secret;

    expect($valorCrudoEnBd)->not->toBe($secretKey)
        ->and($valorViaEloquent)->toBe($secretKey);
});

it('no reexpone ni regenera el secreto TOTP de una cuenta cuyo segundo factor ya está verificado', function (): void {
    $user = crearUsuarioConRol('Cajera');
    configurarSegundoFactor($this, $user);

    $loginData = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Password123!', 'recaptcha' => 'bypass-recaptcha'])
        ->assertStatus(200)
        ->assertJson(['data' => ['requires_mfa' => true]])
        ->json('data');

    expect($loginData)->not->toHaveKey('requires_setup');
});
