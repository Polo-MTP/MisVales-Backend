<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

describe('Login', function (): void {
    it('nunca loguea la contraseña ni expone un token de sesión en la respuesta', function (): void {
        // La sesión ahora se autentica por cookie httpOnly (ver auditoría de seguridad,
        // hallazgo H-02) -- ya no hay un token en texto plano que devolver ni que filtrar de
        // los logs; lo que sí se sigue verificando es que la contraseña nunca aparezca logueada.
        $user = User::factory()->create([
            'password' => bcrypt('Password123!'),
            'role_id' => null,
        ]);

        $contextosLogueados = [];
        Log::listen(function ($log) use (&$contextosLogueados): void {
            $contextosLogueados[] = $log->context;
        });

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'Password123!',
            'recaptcha' => 'bypass-recaptcha',
        ]);

        $response->assertJsonMissingPath('data.token');

        foreach ($contextosLogueados as $contexto) {
            expect(json_encode($contexto))->not->toContain('Password123!');
        }
    });

    it('logs in with valid credentials for a role with no MFA requirement', function (): void {
        $user = User::factory()->create([
            'password' => bcrypt('Password123!'),
            'role_id' => null,
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'Password123!',
            'recaptcha' => 'bypass-recaptcha',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email'],
                ],
            ])
            ->assertJsonMissingPath('data.token')
            ->assertJson([
                'success' => true,
                'message' => 'Login exitoso',
            ]);
    });

    it('requires MFA setup on first login for a role that needs a second factor', function (): void {
        $user = User::factory()->create([
            'password' => bcrypt('Password123!'),
            'role_id' => 1,
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'Password123!',
            'recaptcha' => 'bypass-recaptcha',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'requires_setup' => true,
                    'email' => $user->email,
                ],
            ]);
    });

    it('fails login with invalid credentials', function (): void {
        $user = User::factory()->create([
            'password' => bcrypt('Password123!'),
            'role_id' => 1,
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrongpassword',
            'recaptcha' => 'bypass-recaptcha',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Credenciales incorrectas.',
            ]);
    });

    it('fails login with non-existent user', function (): void {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'Password123!',
            'recaptcha' => 'bypass-recaptcha',
        ]);

        $response->assertStatus(401);
    });
});

describe('Logout', function (): void {
    it('logs out authenticated user', function (): void {
        $user = User::factory()->create(['role_id' => 1]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/logout');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Sesión cerrada exitosamente.',
            ]);
    });

    it('fails logout without authentication', function (): void {
        $response = $this->postJson('/api/v1/logout');

        $response->assertStatus(401);
    });
});

describe('Me', function (): void {
    it('returns authenticated user data', function (): void {
        $user = User::factory()->create(['role_id' => 1]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'name', 'email'],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'email' => $user->email,
                ],
            ]);
    });

    it('fails without authentication', function (): void {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
    });
});
