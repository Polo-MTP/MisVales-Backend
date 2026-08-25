<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'Gerente General']);
    Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
});

function crearGerenteGeneralMover(): User
{
    return User::factory()->create(['role_id' => Role::where('name', 'Gerente General')->first()->id, 'is_active' => true]);
}

function crearGerenteDeSucursalMover(Sucursal $sucursal, bool $activo = true): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'Gerente de Sucursal')->first()->id,
        'sucursal_id' => $sucursal->id,
        'is_active' => $activo,
    ]);
}

it('el Gerente General mueve a un Gerente de Sucursal a una sucursal sin gerente activo', function (): void {
    $origen = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $destino = Sucursal::create(['nombre' => 'Durango', 'codigo' => 'SUC-002', 'is_active' => true]);
    $gerente = crearGerenteDeSucursalMover($origen);

    Sanctum::actingAs(crearGerenteGeneralMover());

    $response = $this->putJson("/api/v1/usuarios/gerente-sucursal/{$gerente->id}/mover", [
        'sucursal_id' => $destino->id,
    ]);

    $response->assertStatus(200)->assertJsonPath('data.sucursal_id', $destino->id);
    expect($gerente->fresh()->sucursal_id)->toBe($destino->id);
});

it('rechaza mover a una sucursal que ya tiene un Gerente de Sucursal activo', function (): void {
    $origen = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $destino = Sucursal::create(['nombre' => 'Durango', 'codigo' => 'SUC-002', 'is_active' => true]);
    $gerente = crearGerenteDeSucursalMover($origen);
    crearGerenteDeSucursalMover($destino);

    Sanctum::actingAs(crearGerenteGeneralMover());

    $this->putJson("/api/v1/usuarios/gerente-sucursal/{$gerente->id}/mover", [
        'sucursal_id' => $destino->id,
    ])->assertStatus(422)->assertJsonValidationErrors('sucursal_id');

    expect($gerente->fresh()->sucursal_id)->toBe($origen->id);
});

it('sí permite mover a una sucursal cuyo gerente anterior ya está desactivado', function (): void {
    $origen = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $destino = Sucursal::create(['nombre' => 'Durango', 'codigo' => 'SUC-002', 'is_active' => true]);
    $gerente = crearGerenteDeSucursalMover($origen);
    crearGerenteDeSucursalMover($destino, activo: false);

    Sanctum::actingAs(crearGerenteGeneralMover());

    $this->putJson("/api/v1/usuarios/gerente-sucursal/{$gerente->id}/mover", [
        'sucursal_id' => $destino->id,
    ])->assertStatus(200);

    expect($gerente->fresh()->sucursal_id)->toBe($destino->id);
});

it('rechaza mover a un Gerente de Sucursal a la sucursal en la que ya está', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $gerente = crearGerenteDeSucursalMover($sucursal);

    Sanctum::actingAs(crearGerenteGeneralMover());

    $this->putJson("/api/v1/usuarios/gerente-sucursal/{$gerente->id}/mover", [
        'sucursal_id' => $sucursal->id,
    ])->assertStatus(422)->assertJsonValidationErrors('sucursal_id');
});

it('rechaza mover a una sucursal deshabilitada', function (): void {
    $origen = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $destino = Sucursal::create(['nombre' => 'Durango', 'codigo' => 'SUC-002', 'is_active' => false]);
    $gerente = crearGerenteDeSucursalMover($origen);

    Sanctum::actingAs(crearGerenteGeneralMover());

    $this->putJson("/api/v1/usuarios/gerente-sucursal/{$gerente->id}/mover", [
        'sucursal_id' => $destino->id,
    ])->assertStatus(422)->assertJsonValidationErrors('sucursal_id');
});

it('rechaza mover a un usuario que no es Gerente de Sucursal', function (): void {
    $destino = Sucursal::create(['nombre' => 'Durango', 'codigo' => 'SUC-002', 'is_active' => true]);
    $gerenteGeneral = crearGerenteGeneralMover();

    Sanctum::actingAs(crearGerenteGeneralMover());

    $this->putJson("/api/v1/usuarios/gerente-sucursal/{$gerenteGeneral->id}/mover", [
        'sucursal_id' => $destino->id,
    ])->assertStatus(422);
});

it('ningún otro rol puede mover a un Gerente de Sucursal', function (): void {
    $origen = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $destino = Sucursal::create(['nombre' => 'Durango', 'codigo' => 'SUC-002', 'is_active' => true]);
    $gerente = crearGerenteDeSucursalMover($origen);

    Sanctum::actingAs($gerente);

    $this->putJson("/api/v1/usuarios/gerente-sucursal/{$gerente->id}/mover", [
        'sucursal_id' => $destino->id,
    ])->assertStatus(403);
});
