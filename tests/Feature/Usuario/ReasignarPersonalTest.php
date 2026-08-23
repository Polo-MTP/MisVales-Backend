<?php

declare(strict_types=1);

use App\Models\Notificacion;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'Gerente General']);
    Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    Role::firstOrCreate(['name' => 'Coordinador']);
    Role::firstOrCreate(['name' => 'Verificador']);
    Role::firstOrCreate(['name' => 'Cajera']);
});

function crearGerenteGeneralReasignar(): User
{
    return User::factory()->create(['role_id' => Role::where('name', 'Gerente General')->first()->id, 'is_active' => true]);
}

function crearGerenteDeSucursalReasignar(Sucursal $sucursal, bool $activo = true): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'Gerente de Sucursal')->first()->id,
        'sucursal_id' => $sucursal->id,
        'is_active' => $activo,
    ]);
}

it('el Gerente General mueve todo el personal de un Gerente de Sucursal a otro de la misma sucursal', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $gerenteOrigen = crearGerenteDeSucursalReasignar($sucursal);
    $gerenteDestino = crearGerenteDeSucursalReasignar($sucursal);

    $rolCajera = Role::where('name', 'Cajera')->first();
    $cajera1 = User::factory()->create(['role_id' => $rolCajera->id, 'sucursal_id' => $sucursal->id, 'gerente_id' => $gerenteOrigen->id]);
    $cajera2 = User::factory()->create(['role_id' => $rolCajera->id, 'sucursal_id' => $sucursal->id, 'gerente_id' => $gerenteOrigen->id]);
    // De otro gerente -- no debe tocarse.
    $otroGerente = crearGerenteDeSucursalReasignar($sucursal);
    $cajeraDeOtroGerente = User::factory()->create(['role_id' => $rolCajera->id, 'sucursal_id' => $sucursal->id, 'gerente_id' => $otroGerente->id]);

    Sanctum::actingAs(crearGerenteGeneralReasignar());

    $response = $this->postJson('/api/v1/usuarios/reasignar-gerente', [
        'gerente_origen_id' => $gerenteOrigen->id,
        'gerente_destino_id' => $gerenteDestino->id,
    ]);

    $response->assertStatus(200)->assertJsonPath('data.personal_reasignado', 2);

    expect($cajera1->fresh()->gerente_id)->toBe($gerenteDestino->id)
        ->and($cajera2->fresh()->gerente_id)->toBe($gerenteDestino->id)
        ->and($cajeraDeOtroGerente->fresh()->gerente_id)->toBe($otroGerente->id);

    expect(Notificacion::where('destinatario_id', $gerenteDestino->id)->where('accion', 'personal_asignado')->exists())->toBeTrue();
});

it('rechaza reasignar si el gerente destino es de otra sucursal', function (): void {
    $sucursalA = Sucursal::create(['nombre' => 'A', 'codigo' => 'SUC-A', 'es_matriz' => true, 'is_active' => true]);
    $sucursalB = Sucursal::create(['nombre' => 'B', 'codigo' => 'SUC-B', 'es_matriz' => false, 'is_active' => true]);
    $gerenteOrigen = crearGerenteDeSucursalReasignar($sucursalA);
    $gerenteDestino = crearGerenteDeSucursalReasignar($sucursalB);

    Sanctum::actingAs(crearGerenteGeneralReasignar());

    $this->postJson('/api/v1/usuarios/reasignar-gerente', [
        'gerente_origen_id' => $gerenteOrigen->id,
        'gerente_destino_id' => $gerenteDestino->id,
    ])->assertStatus(422);
});

it('rechaza reasignar a un gerente destino deshabilitado', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $gerenteOrigen = crearGerenteDeSucursalReasignar($sucursal);
    $gerenteDestino = crearGerenteDeSucursalReasignar($sucursal, activo: false);

    Sanctum::actingAs(crearGerenteGeneralReasignar());

    $this->postJson('/api/v1/usuarios/reasignar-gerente', [
        'gerente_origen_id' => $gerenteOrigen->id,
        'gerente_destino_id' => $gerenteDestino->id,
    ])->assertStatus(422);
});

it('ningún otro rol puede reasignar personal entre gerentes', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $gerenteOrigen = crearGerenteDeSucursalReasignar($sucursal);
    $gerenteDestino = crearGerenteDeSucursalReasignar($sucursal);

    Sanctum::actingAs($gerenteOrigen);

    $this->postJson('/api/v1/usuarios/reasignar-gerente', [
        'gerente_origen_id' => $gerenteOrigen->id,
        'gerente_destino_id' => $gerenteDestino->id,
    ])->assertStatus(403);
});
