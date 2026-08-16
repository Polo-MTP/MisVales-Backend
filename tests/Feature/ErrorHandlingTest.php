<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * bootstrap/app.php's withExceptions() estaba vacío: cualquier excepción no atrapada
 * explícitamente por un controller cae en el renderer default de Laravel — otro formato
 * de JSON que el resto de la API, y en debug=true con el stack trace completo expuesto.
 */
it('una petición inválida devuelve los errores en el mismo formato {success, message} del resto de la API', function (): void {
    $this->postJson('/api/v1/login', [])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'message', 'errors' => ['email', 'password']]);
});

it('un recurso inexistente devuelve 404 en el formato unificado, no el default de Laravel', function (): void {
    $role = Role::firstOrCreate(['name' => 'Gerente General'], ['factor_count' => 1]);
    $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/relaciones/999999')
        ->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'message']);
});

it('una DomainException no atrapada localmente por ningún controller sale en el formato unificado', function (): void {
    Route::get('/__test/domain-exception', function (): void {
        throw new DomainException('mensaje de negocio pensado para el usuario');
    });

    $this->getJson('/__test/domain-exception')
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'mensaje de negocio pensado para el usuario']);
});

it('una excepción inesperada nunca expone detalles técnicos por la API, ni siquiera con APP_DEBUG=true', function (): void {
    Route::get('/__test/boom', function (): void {
        throw new RuntimeException('detalle técnico secreto: falló la conexión a la tabla xyz en Servicio.php linea 123');
    });

    config(['app.debug' => true]);

    $response = $this->getJson('/__test/boom');

    $response->assertStatus(500)
        ->assertJson([
            'success' => false,
            'message' => 'Ocurrió un error interno. Intenta de nuevo más tarde.',
        ]);

    expect($response->getContent())
        ->not->toContain('detalle técnico secreto')
        ->and($response->getContent())->not->toContain('RuntimeException')
        ->and($response->getContent())->not->toContain('Servicio.php');
});
