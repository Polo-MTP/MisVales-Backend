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
        ->toThrow(DomainException::class, 'Solo se pueden autorizar vales ya validados (actual: solicitado). Valida los datos del cliente primero.');
});

it('valida un vale solicitado y registra quién y cuándo lo validó', function (): void {
    [$distribuidora, $cliente, $cajera] = crearDistribuidoraConClienteYCajera();
    $producto = Producto::create(['monto' => 500, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    $vale = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    $vale = app(ValeService::class)->validar($vale, $cajera, '032180000118359719');

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

    $vale = app(ValeService::class)->validar($vale, $cajera, '032180000118359719');
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

    app(ValeService::class)->validar($vale, $cajera, '032180000118359719');

    expect(fn () => app(ValeService::class)->validar($vale->fresh(), $cajera))
        ->toThrow(DomainException::class, "Solo se pueden validar vales en estado 'solicitado' (actual: validado).");
});

it('exige CLABE interbancaria la primera vez que se valida un vale del cliente', function (): void {
    [$distribuidora, $cliente, $cajera] = crearDistribuidoraConClienteYCajera();
    $producto = Producto::create(['monto' => 500, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    $vale = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    expect(fn () => app(ValeService::class)->validar($vale, $cajera))
        ->toThrow(DomainException::class, 'Este cliente no tiene CLABE interbancaria registrada. Captúrala para poder validar y transferirle el pago.');

    expect($cliente->fresh()->clabe)->toBeNull();
});

it('no permite validar si la cajera marca que la INE o el comprobante no coinciden', function (): void {
    [$distribuidora, $cliente, $cajera] = crearDistribuidoraConClienteYCajera();
    $producto = Producto::create(['monto' => 500, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    $vale = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    expect(fn () => app(ValeService::class)->validar($vale, $cajera, '032180000118359719', false, true))
        ->toThrow(DomainException::class, 'No se puede validar el vale: la INE y el comprobante de domicilio del cliente deben coincidir. Corrige los datos o pide autorización para editarlos antes de continuar.');

    expect($vale->fresh()->estado)->toBe('solicitado');
});

it('registra que la cajera revisó INE y comprobante de domicilio al validar', function (): void {
    [$distribuidora, $cliente, $cajera] = crearDistribuidoraConClienteYCajera();
    $producto = Producto::create(['monto' => 500, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    $vale = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    $vale = app(ValeService::class)->validar($vale, $cajera, '032180000118359719', true, true);

    expect($vale->ine_verificada)->toBeTrue()
        ->and($vale->comprobante_domicilio_verificado)->toBeTrue();
});

it('guarda la CLABE cifrada en el cliente y no la vuelve a pedir en un vale futuro', function (): void {
    [$distribuidora, $cliente, $cajera] = crearDistribuidoraConClienteYCajera();
    $producto = Producto::create(['monto' => 500, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    $primerVale = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    app(ValeService::class)->validar($primerVale, $cajera, '032180000118359719');

    expect($cliente->fresh()->clabe)->toBe('032180000118359719');

    // Se guarda cifrada en crudo (no en texto plano) — como MfaMethod.secret.
    $crudo = Illuminate\Support\Facades\DB::table('clientes')->where('id', $cliente->id)->value('clabe');
    expect($crudo)->not->toBe('032180000118359719');

    // Liquida el primer vale para poder solicitar uno nuevo, y valida sin mandar tarjeta otra vez.
    $primerVale->fresh()->update(['estado' => 'pagado']);

    $segundoVale = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    $segundoVale = app(ValeService::class)->validar($segundoVale, $cajera);

    expect($segundoVale->estado)->toBe('validado');
});

it('no permite autorizar un vale si ya no hay crédito disponible suficiente (el crédito se revisa al autorizar, no solo al solicitar)', function (): void {
    [$distribuidora, $clienteUno, $cajera] = crearDistribuidoraConClienteYCajera(1000);

    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '2', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Dos', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $clienteDos = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);
    HistorialClienteDistr::create(['distribuidor_id' => $distribuidora->id, 'cliente_id' => $clienteDos->id, 'fecha_inicio' => now(), 'fecha_fin' => null]);

    // El primero es el "primer vale" de la distribuidora: la regla del 50% limita su monto a
    // la mitad del límite total (1000 * 50% = 500), sin importar el crédito disponible.
    $productoUno = Producto::create(['monto' => 500, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);
    $productoDos = Producto::create(['monto' => 600, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    // Ambos vales pasan solicitar() sin problema: 'solicitado' todavía no cuenta contra el
    // crédito disponible.
    $valeUno = app(ValeService::class)->solicitar(['cliente_id' => $clienteUno->id, 'producto_id' => $productoUno->id], $distribuidora->usuario);
    $valeDos = app(ValeService::class)->solicitar(['cliente_id' => $clienteDos->id, 'producto_id' => $productoDos->id], $distribuidora->usuario);

    $valeUno = app(ValeService::class)->validar($valeUno, $cajera, '032180000118359719');
    $valeDos = app(ValeService::class)->validar($valeDos, $cajera, '032180000118359720');

    // El primero sí cabe (500 <= 1000) y al autorizarse empieza a contar contra el crédito.
    app(ValeService::class)->autorizar($valeUno, $cajera);

    // El segundo ya no cabe: crédito disponible ahora es 1000 - 500 = 500, y el vale es de 600.
    expect(fn () => app(ValeService::class)->autorizar($valeDos, $cajera))
        ->toThrow(DomainException::class, 'La distribuidora ya no tiene crédito disponible suficiente para autorizar este vale.');

    expect($valeDos->fresh()->estado)->toBe('validado');
});
