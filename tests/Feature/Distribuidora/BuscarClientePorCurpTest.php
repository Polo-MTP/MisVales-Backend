<?php

declare(strict_types=1);

use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\HistorialClienteDistr;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('una distribuidora encuentra por CURP exacta a un cliente de OTRA distribuidora (para pedir su transferencia)', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);

    $usuarioAjeno = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $distribuidoraAjena = Distribuidora::create([
        'usuario_id' => $usuarioAjeno->id, 'numero_distribuidora' => 'DIST-A-'.uniqid(),
        'limite_credito' => 10000, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    $direccion = Direccion::create(['calle' => 'Calle 1', 'colonia' => 'Centro', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Ajeno', 'curp' => 'CURPBUSCADA1234567', 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    HistorialClienteDistr::create([
        'distribuidor_id' => $distribuidoraAjena->id, 'cliente_id' => $cliente->id, 'fecha_inicio' => now(), 'fecha_fin' => null,
    ]);

    $usuarioBuscador = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    Distribuidora::create([
        'usuario_id' => $usuarioBuscador->id, 'numero_distribuidora' => 'DIST-B-'.uniqid(),
        'limite_credito' => 10000, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    Sanctum::actingAs($usuarioBuscador);

    $response = $this->getJson('/api/v1/distribuidora/clientes/buscar-por-curp?curp=CURPBUSCADA1234567');

    $response->assertStatus(200)->assertJsonPath('data.id', $cliente->id);
});

it('regresa 404 si no existe ningún cliente con esa CURP', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $usuario = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    Distribuidora::create([
        'usuario_id' => $usuario->id, 'numero_distribuidora' => 'DIST-'.uniqid(),
        'limite_credito' => 10000, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    Sanctum::actingAs($usuario);

    $response = $this->getJson('/api/v1/distribuidora/clientes/buscar-por-curp?curp=NOEXISTE0000000001');

    $response->assertStatus(404);
});

it('exige la CURP completa de 18 caracteres', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $usuario = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    Distribuidora::create([
        'usuario_id' => $usuario->id, 'numero_distribuidora' => 'DIST-'.uniqid(),
        'limite_credito' => 10000, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    Sanctum::actingAs($usuario);

    $response = $this->getJson('/api/v1/distribuidora/clientes/buscar-por-curp?curp=CORTA');

    $response->assertStatus(422);
});
