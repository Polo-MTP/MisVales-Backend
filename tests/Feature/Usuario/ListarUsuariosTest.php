<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'Administrador']);
    Role::firstOrCreate(['name' => 'Gerente General']);
    Role::firstOrCreate(['name' => 'Cajera']);
});

it('el Administrador puede listar usuarios de todas las sucursales, igual que Gerente General', function (): void {
    $sucursalA = Sucursal::create(['nombre' => 'A', 'codigo' => 'SUC-A', 'es_matriz' => true, 'is_active' => true]);
    $sucursalB = Sucursal::create(['nombre' => 'B', 'codigo' => 'SUC-B', 'es_matriz' => false, 'is_active' => true]);
    $rolCajera = Role::where('name', 'Cajera')->first();
    User::factory()->create(['role_id' => $rolCajera->id, 'sucursal_id' => $sucursalA->id, 'is_active' => true]);
    User::factory()->create(['role_id' => $rolCajera->id, 'sucursal_id' => $sucursalB->id, 'is_active' => true]);

    $admin = User::factory()->create(['role_id' => Role::where('name', 'Administrador')->first()->id, 'is_active' => true]);
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/usuarios');

    $response->assertStatus(200);
    // El propio admin + las 2 cajeras de ambas sucursales -- sin filtrar por sucursal.
    expect(count($response->json('data')))->toBeGreaterThanOrEqual(3);
});

it('un rol sin acceso al endpoint (Cajera) no puede listar usuarios', function (): void {
    $cajera = User::factory()->create(['role_id' => Role::where('name', 'Cajera')->first()->id, 'is_active' => true]);
    Sanctum::actingAs($cajera);

    $this->getJson('/api/v1/usuarios')->assertStatus(403);
});
