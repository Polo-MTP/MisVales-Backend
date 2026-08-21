<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\SeguroTabla;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearGerenteGeneralSeguro(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente General']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

function crearGerenteDeSucursalSeguro(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente de Sucursal']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

it('el Gerente General puede crear un rango de seguro', function (): void {
    Sanctum::actingAs(crearGerenteGeneralSeguro());

    $response = $this->postJson('/api/v1/configuraciones/seguros', [
        'monto_desde' => 0,
        'monto_hasta' => 10000,
        'seguro_monto' => 100,
    ]);

    $response->assertStatus(201)->assertJsonPath('data.seguro_monto', '100.00');
    expect(SeguroTabla::count())->toBe(1);
});

it('el Gerente de Sucursal no puede crear un rango de seguro', function (): void {
    Sanctum::actingAs(crearGerenteDeSucursalSeguro());

    $response = $this->postJson('/api/v1/configuraciones/seguros', [
        'monto_desde' => 0,
        'seguro_monto' => 100,
    ]);

    $response->assertStatus(403);
});

it('rechaza un monto_hasta menor que monto_desde', function (): void {
    Sanctum::actingAs(crearGerenteGeneralSeguro());

    $response = $this->postJson('/api/v1/configuraciones/seguros', [
        'monto_desde' => 10000,
        'monto_hasta' => 5000,
        'seguro_monto' => 100,
    ]);

    $response->assertStatus(422);
});

it('el Gerente General puede actualizar un rango de seguro existente', function (): void {
    $seguro = SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);
    Sanctum::actingAs(crearGerenteGeneralSeguro());

    $response = $this->putJson("/api/v1/configuraciones/seguros/{$seguro->id}", [
        'monto_desde' => 0,
        'monto_hasta' => 20000,
        'seguro_monto' => 150,
    ]);

    $response->assertStatus(200)->assertJsonPath('data.seguro_monto', '150.00');
});

it('el Gerente General puede desactivar un rango de seguro sin borrarlo', function (): void {
    $seguro = SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);
    Sanctum::actingAs(crearGerenteGeneralSeguro());

    $response = $this->deleteJson("/api/v1/configuraciones/seguros/{$seguro->id}");

    $response->assertStatus(200);
    expect($seguro->fresh())
        ->activo->toBeFalse()
        ->and(SeguroTabla::find($seguro->id))->not->toBeNull();
});

it('el listado por defecto no incluye rangos de seguro desactivados', function (): void {
    SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);
    SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 50, 'activo' => false]);
    Sanctum::actingAs(crearGerenteGeneralSeguro());

    $response = $this->getJson('/api/v1/configuraciones/seguros');

    $response->assertStatus(200)->assertJsonCount(1, 'data');
});
