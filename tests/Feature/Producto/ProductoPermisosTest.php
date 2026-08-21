<?php

declare(strict_types=1);

use App\Models\Producto;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearUsuarioProducto(string $rol): User
{
    $role = Role::firstOrCreate(['name' => $rol]);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

it('el Gerente de Sucursal puede crear un producto sin necesidad de VPN', function (): void {
    Sanctum::actingAs(crearUsuarioProducto('Gerente de Sucursal'));

    $response = $this->postJson('/api/v1/productos', [
        'monto' => 5000,
        'quincenas' => 8,
        'descripcion' => 'Producto de prueba',
    ]);

    $response->assertStatus(201);
    expect(Producto::where('monto', 5000)->exists())->toBeTrue();
});

it('el Gerente de Sucursal puede editar un producto sin necesidad de VPN', function (): void {
    $producto = Producto::create(['monto' => 5000, 'quincenas' => 8, 'activo' => true, 'created_by' => User::factory()->create()->id]);
    Sanctum::actingAs(crearUsuarioProducto('Gerente de Sucursal'));

    $response = $this->putJson('/api/v1/productos/'.$producto->id, [
        'monto' => 5500,
        'quincenas' => 8,
    ]);

    $response->assertStatus(200);
    expect((float) $producto->fresh()->monto)->toBe(5500.0);
});

it('el Gerente de Sucursal puede desactivar un producto', function (): void {
    $producto = Producto::create(['monto' => 5000, 'quincenas' => 8, 'activo' => true, 'created_by' => User::factory()->create()->id]);
    Sanctum::actingAs(crearUsuarioProducto('Gerente de Sucursal'));

    $response = $this->deleteJson('/api/v1/productos/'.$producto->id);

    $response->assertStatus(200);
    expect($producto->fresh()->activo)->toBeFalse();
});

it('la Cajera sigue sin poder crear, editar ni desactivar productos', function (): void {
    $producto = Producto::create(['monto' => 5000, 'quincenas' => 8, 'activo' => true, 'created_by' => User::factory()->create()->id]);
    Sanctum::actingAs(crearUsuarioProducto('Cajera'));

    $this->postJson('/api/v1/productos', ['monto' => 6000, 'quincenas' => 8])->assertStatus(403);
    $this->putJson('/api/v1/productos/'.$producto->id, ['monto' => 6000, 'quincenas' => 8])->assertStatus(403);
    $this->deleteJson('/api/v1/productos/'.$producto->id)->assertStatus(403);
});
