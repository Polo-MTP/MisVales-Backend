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

function crearDistribuidoraParaPaginacion(): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Sucursal', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'ORO-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearClientesActivosPara(Distribuidora $distribuidora, int $cantidad): void
{
    for ($i = 0; $i < $cantidad; $i++) {
        $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
        $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => (string) $i, 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
        $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

        HistorialClienteDistr::create([
            'distribuidor_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'fecha_inicio' => now(), 'fecha_fin' => null,
        ]);
    }
}

/**
 * El selector de cliente al solicitar un vale necesita a TODOS los clientes activos de la
 * distribuidora, no solo los 15 más recientes (default de paginación) -- con más de 15 clientes
 * activos, el frontend no podía ofrecer los demás para elegir. El frontend ahora manda
 * ?per_page= explícito; esto confirma que el backend lo respeta.
 */
it('respeta un per_page explícito para poder listar más de los 15 clientes por defecto', function (): void {
    $distribuidora = crearDistribuidoraParaPaginacion();
    crearClientesActivosPara($distribuidora, 20);

    Sanctum::actingAs($distribuidora->usuario);

    $this->getJson('/api/v1/distribuidora/clientes?estado=true')
        ->assertStatus(200)
        ->assertJsonCount(15, 'data.data');

    $this->getJson('/api/v1/distribuidora/clientes?estado=true&per_page=1000')
        ->assertStatus(200)
        ->assertJsonCount(20, 'data.data');
});
