<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use App\Services\Vale\ValeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearSucursalListado(string $codigo): Sucursal
{
    return Sucursal::create(['nombre' => 'Sucursal '.$codigo, 'codigo' => $codigo, 'es_matriz' => false, 'is_active' => true]);
}

function crearDistribuidoraValeListado(Sucursal $sucursal, string $numero): Distribuidora
{
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => $numero, 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearValeListado(Distribuidora $distribuidora, float $monto = 5000): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => $monto, 'quincenas' => 4,
        'tipo' => 'vale-digital', 'estado' => 'solicitado', 'fecha_solicitud' => now(),
    ]);
}

function crearUsuarioDeSucursal(string $rol, Sucursal $sucursal): User
{
    $role = Role::firstOrCreate(['name' => $rol]);

    return User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
}

it('una cajera solo ve vales de distribuidoras de su propia sucursal', function (): void {
    $sucursalA = crearSucursalListado('SUC-A');
    $sucursalB = crearSucursalListado('SUC-B');
    $distA = crearDistribuidoraValeListado($sucursalA, 'DIST-A');
    $distB = crearDistribuidoraValeListado($sucursalB, 'DIST-B');
    crearValeListado($distA);
    crearValeListado($distB);

    $cajera = crearUsuarioDeSucursal('Cajera', $sucursalA);

    $vales = app(ValeService::class)->listar($cajera);

    expect($vales->total())->toBe(1)
        ->and($vales->first()->distribuidora_id)->toBe($distA->id);
});

it('un gerente de sucursal solo ve vales de distribuidoras de su propia sucursal', function (): void {
    $sucursalA = crearSucursalListado('SUC-A');
    $sucursalB = crearSucursalListado('SUC-B');
    $distA = crearDistribuidoraValeListado($sucursalA, 'DIST-A');
    $distB = crearDistribuidoraValeListado($sucursalB, 'DIST-B');
    crearValeListado($distA);
    crearValeListado($distB);

    $gerente = crearUsuarioDeSucursal('Gerente de Sucursal', $sucursalA);

    $vales = app(ValeService::class)->listar($gerente);

    expect($vales->total())->toBe(1)
        ->and($vales->first()->distribuidora_id)->toBe($distA->id);
});

it('un coordinador sigue viendo vales de todas las sucursales', function (): void {
    $sucursalA = crearSucursalListado('SUC-A');
    $sucursalB = crearSucursalListado('SUC-B');
    $distA = crearDistribuidoraValeListado($sucursalA, 'DIST-A');
    $distB = crearDistribuidoraValeListado($sucursalB, 'DIST-B');
    crearValeListado($distA);
    crearValeListado($distB);

    $coordinador = crearUsuarioDeSucursal('Coordinador', $sucursalA);

    $vales = app(ValeService::class)->listar($coordinador);

    expect($vales->total())->toBe(2);
});

it('la cajera puede llegar por HTTP a GET /vales (antes solo Distribuidora/Coordinador/Gerentes)', function (): void {
    $sucursal = crearSucursalListado('SUC-A');
    $distribuidora = crearDistribuidoraValeListado($sucursal, 'DIST-A');
    crearValeListado($distribuidora);

    $cajera = crearUsuarioDeSucursal('Cajera', $sucursal);
    Sanctum::actingAs($cajera);

    $this->getJson('/api/v1/vales')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.data');
});
