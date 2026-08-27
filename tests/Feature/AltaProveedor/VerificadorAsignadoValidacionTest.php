<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * verificador_id solo comprobaba 'exists:users,id' -- el Coordinador podía "asignar" como
 * verificador a cualquier cuenta del sistema, sin importar su rol ni su sucursal (incluida una
 * Distribuidora). Confirma que ahora sí se valida ambas cosas.
 */
function datosSolicitudConVerificador(?int $verificadorId): array
{
    return [
        'calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000',
        'estado' => 'Coahuila', 'ciudad' => 'Torreón',
        'nombre' => 'Juan', 'apellido_paterno' => 'Pérez',
        'curp' => 'PEGJ850101HDGRZN05', 'rfc' => 'PEGJ850101ABC',
        'verificador_id' => $verificadorId,
    ];
}

it('rechaza asignar como verificador a un usuario que no tiene el rol Verificador', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $coordinador = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Coordinador'])->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $distribuidora = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Distribuidora'])->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    Sanctum::actingAs($coordinador);

    $this->postJson('/api/v1/alta-proveedor/solicitudes', datosSolicitudConVerificador($distribuidora->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('verificador_id');
});

it('rechaza asignar como verificador a alguien de otra sucursal', function (): void {
    $sucursalA = Sucursal::create(['nombre' => 'A', 'codigo' => 'SUC-A-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $sucursalB = Sucursal::create(['nombre' => 'B', 'codigo' => 'SUC-B-'.uniqid(), 'is_active' => true]);
    $coordinador = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Coordinador'])->id, 'sucursal_id' => $sucursalA->id, 'is_active' => true]);
    $verificadorDeOtraSucursal = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Verificador'])->id, 'sucursal_id' => $sucursalB->id, 'is_active' => true]);

    Sanctum::actingAs($coordinador);

    $this->postJson('/api/v1/alta-proveedor/solicitudes', datosSolicitudConVerificador($verificadorDeOtraSucursal->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('verificador_id');
});

it('sí permite asignar a un Verificador real de la misma sucursal', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $coordinador = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Coordinador'])->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $verificador = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Verificador'])->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    Sanctum::actingAs($coordinador);

    $this->postJson('/api/v1/alta-proveedor/solicitudes', datosSolicitudConVerificador($verificador->id))
        ->assertStatus(201);
});
