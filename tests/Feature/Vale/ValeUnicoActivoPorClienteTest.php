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
use App\Models\SeguroTabla;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use App\Services\Vale\ValeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function crearDistribuidoraConCliente(float $limiteCredito = 20000): array
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id]);

    $distribuidora = Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => $limiteCredito,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    HistorialClienteDistr::create([
        'distribuidor_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'fecha_inicio' => now(), 'fecha_fin' => null,
    ]);

    return [$distribuidora, $cliente];
}

beforeEach(function (): void {
    $admin = User::factory()->create();
    Configuracion::create(['clave' => 'regla_50_pct', 'valor' => '50', 'tipo_dato' => 'decimal', 'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id]);
    SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);
});

it('no permite solicitar un segundo vale para un cliente que ya tiene uno sin liquidar', function (): void {
    [$distribuidora, $cliente] = crearDistribuidoraConCliente();
    $producto = Producto::create(['monto' => 500, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    expect(fn () => app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario))->toThrow(DomainException::class, 'Este cliente ya tiene un vale activo o pendiente. Debe liquidarse antes de poder solicitar otro.');
});

it('permite solicitar un vale nuevo una vez que el anterior del cliente ya fue liquidado (pagado)', function (): void {
    [$distribuidora, $cliente] = crearDistribuidoraConCliente();
    $producto = Producto::create(['monto' => 500, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    $valeAnterior = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    $valeAnterior->update(['estado' => 'pagado']);

    $valeNuevo = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    expect($valeNuevo->id)->not->toBe($valeAnterior->id)
        ->and(Vale::where('cliente_id', $cliente->id)->count())->toBe(2);
});

it('un vale desactivado por la distribuidora no cuenta como "sin liquidar" y no bloquea uno nuevo', function (): void {
    [$distribuidora, $cliente] = crearDistribuidoraConCliente();
    $producto = Producto::create(['monto' => 500, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    $valeAnterior = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    app(ValeService::class)->desactivar($valeAnterior, $distribuidora->usuario);

    $valeNuevo = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    expect($valeNuevo->id)->not->toBe($valeAnterior->id);
});
