<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
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

function crearSucursalTest(): Sucursal
{
    return Sucursal::create(['nombre' => 'Sucursal', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
}

function crearDistribuidoraEnSucursal(Sucursal $sucursal): Distribuidora
{
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearClienteDe(Distribuidora $distribuidora): Cliente
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'De Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    HistorialClienteDistr::create([
        'distribuidor_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'fecha_inicio' => now(), 'fecha_fin' => null,
    ]);

    return $cliente;
}

function crearCajeraEnSucursal(Sucursal $sucursal): User
{
    $role = Role::firstOrCreate(['name' => 'Cajera']);

    return User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
}

it('la cajera lista por HTTP los clientes de distribuidoras de su propia sucursal', function (): void {
    $sucursalA = crearSucursalTest();
    $sucursalB = crearSucursalTest();
    $distribuidoraA = crearDistribuidoraEnSucursal($sucursalA);
    $distribuidoraB = crearDistribuidoraEnSucursal($sucursalB);
    crearClienteDe($distribuidoraA);
    crearClienteDe($distribuidoraB);

    $cajera = crearCajeraEnSucursal($sucursalA);
    Sanctum::actingAs($cajera);

    $this->getJson('/api/v1/distribuidora/clientes')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.data');
});

it('la cajera puede ver el detalle de un cliente de su sucursal pero no de otra', function (): void {
    $sucursalA = crearSucursalTest();
    $sucursalB = crearSucursalTest();
    $distribuidoraA = crearDistribuidoraEnSucursal($sucursalA);
    $distribuidoraB = crearDistribuidoraEnSucursal($sucursalB);
    $clienteA = crearClienteDe($distribuidoraA);
    $clienteB = crearClienteDe($distribuidoraB);

    $cajera = crearCajeraEnSucursal($sucursalA);
    Sanctum::actingAs($cajera);

    $this->getJson("/api/v1/distribuidora/clientes/{$clienteA->id}")->assertStatus(200);
    $this->getJson("/api/v1/distribuidora/clientes/{$clienteB->id}")->assertStatus(403);
});

it('busca por nombre completo (nombre + apellido en un solo término), no solo por una palabra', function (): void {
    $sucursal = crearSucursalTest();
    $distribuidora = crearDistribuidoraEnSucursal($sucursal);

    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Juana', 'apellido_paterno' => 'Reyes', 'apellido_materno' => 'Ibarra', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);
    HistorialClienteDistr::create(['distribuidor_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'fecha_inicio' => now(), 'fecha_fin' => null]);

    $cajera = crearCajeraEnSucursal($sucursal);
    Sanctum::actingAs($cajera);

    // Antes: buscar solo "Juana" encontraba (coincide en una sola columna), pero "Juana Reyes"
    // no encontraba nada -- ninguna columna por separado contiene las dos palabras juntas.
    $this->getJson('/api/v1/distribuidora/clientes?search=Juana')->assertJsonCount(1, 'data.data');
    $this->getJson('/api/v1/distribuidora/clientes?search=Juana+Reyes')->assertJsonCount(1, 'data.data');
    $this->getJson('/api/v1/distribuidora/clientes?search=Reyes+Ibarra')->assertJsonCount(1, 'data.data');
    $this->getJson('/api/v1/distribuidora/clientes?search=Juana+Perez')->assertJsonCount(0, 'data.data');
});

it('listar o ver clientes como cajera no crea una distribuidora fantasma para su usuario', function (): void {
    $sucursal = crearSucursalTest();
    $distribuidora = crearDistribuidoraEnSucursal($sucursal);
    $cliente = crearClienteDe($distribuidora);

    $cajera = crearCajeraEnSucursal($sucursal);
    Sanctum::actingAs($cajera);

    $this->getJson('/api/v1/distribuidora/clientes')->assertStatus(200);
    $this->getJson("/api/v1/distribuidora/clientes/{$cliente->id}")->assertStatus(200);

    expect(Distribuidora::where('usuario_id', $cajera->id)->exists())->toBeFalse();
});
