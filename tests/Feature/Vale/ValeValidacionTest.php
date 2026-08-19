<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\HistorialClienteDistr;
use App\Models\Producto;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Vale\ValeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function crearDistribuidoraConClienteYCajera(float $limiteCredito = 20000): array
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $roleDistribuidora = Role::firstOrCreate(['name' => 'Distribuidora']);
    $roleCajera = Role::firstOrCreate(['name' => 'Cajera']);

    $usuarioDistribuidora = User::factory()->create(['role_id' => $roleDistribuidora->id, 'sucursal_id' => $sucursal->id]);
    $cajera = User::factory()->create(['role_id' => $roleCajera->id, 'sucursal_id' => $sucursal->id]);

    $distribuidora = Distribuidora::create([
        'usuario_id' => $usuarioDistribuidora->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => $limiteCredito,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    HistorialClienteDistr::create([
        'distribuidor_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'fecha_inicio' => now(), 'fecha_fin' => null,
    ]);

    return [$distribuidora, $cliente, $cajera];
}

beforeEach(function (): void {
    $admin = User::factory()->create();
    Configuracion::create(['clave' => 'regla_50_pct', 'valor' => '50', 'tipo_dato' => 'decimal', 'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id]);
});

it('no permite autorizar un vale solicitado que aún no ha sido validado', function (): void {
    [$distribuidora, $cliente, $cajera] = crearDistribuidoraConClienteYCajera();
    $producto = Producto::create(['monto' => 500, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    $vale = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    expect(fn () => app(ValeService::class)->autorizar($vale, $cajera))
        ->toThrow(HttpException::class, 'Solo se pueden autorizar vales ya validados (actual: solicitado). Valida los datos del cliente primero.');
});

it('valida un vale solicitado y registra quién y cuándo lo validó', function (): void {
    [$distribuidora, $cliente, $cajera] = crearDistribuidoraConClienteYCajera();
    $producto = Producto::create(['monto' => 500, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    $vale = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    $vale = app(ValeService::class)->validar($vale, $cajera);

    expect($vale->estado)->toBe('validado')
        ->and($vale->validado_por)->toBe($cajera->id)
        ->and($vale->fecha_validacion)->not->toBeNull();
});

it('permite autorizar un vale ya validado', function (): void {
    [$distribuidora, $cliente, $cajera] = crearDistribuidoraConClienteYCajera();
    $producto = Producto::create(['monto' => 500, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    $vale = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    $vale = app(ValeService::class)->validar($vale, $cajera);
    $vale = app(ValeService::class)->autorizar($vale, $cajera);

    expect($vale->estado)->toBe('autorizado')
        ->and($vale->fecha_autorizacion)->not->toBeNull();
});

it('no permite validar un vale que no está en estado solicitado', function (): void {
    [$distribuidora, $cliente, $cajera] = crearDistribuidoraConClienteYCajera();
    $producto = Producto::create(['monto' => 500, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    $vale = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    app(ValeService::class)->validar($vale, $cajera);

    expect(fn () => app(ValeService::class)->validar($vale->fresh(), $cajera))
        ->toThrow(HttpException::class, "Solo se pueden validar vales en estado 'solicitado' (actual: validado).");
});
