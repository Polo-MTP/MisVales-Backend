<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

describe('Registration', function (): void {
    it('registers a new user successfully', function (): void {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'token',
                ],
            ])
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);
    });

    it('fails registration with invalid data', function (): void {
        $response = $this->postJson('/api/v1/register', [
            'name' => '',
            'email' => 'invalid-email',
            'password' => 'short',
        ]);

        $response->assertStatus(422);
    });

    it('fails registration with duplicate email', function (): void {
        User::factory()->create([
            'email' => 'existing@example.com',
            'role_id' => 1,
        ]);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Test User',
            'email' => 'existing@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(422);
    });
});

describe('Login', function (): void {
    it('logs in with valid credentials', function (): void {
        $user = User::factory()->create([
            'password' => bcrypt('Password123!'),
            'role_id' => 1, // Invitado
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'token',
                ],
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Login exitoso',
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
