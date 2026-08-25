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
use App\Models\SolicitudAumentoCredito;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Vale\ValeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/**
 * Regla del 50% + margen: el PRIMER vale de una distribuidora (o el primero desde un aumento
 * de crédito recién aprobado y aún no "estrenado") no puede pasar del porcentaje configurado
 * ('regla_50_pct', default 50%) del crédito relevante, más un margen fijo editable
 * ('margen_aumento_credito', default $500) -- mismo margen en los dos casos. Cualquier vale
 * posterior a ese ya solo lo limita el crédito disponible.
 */
function crearDistribuidoraQA50(float $limiteCredito = 10000): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'BRONCE-'.uniqid(), 'porcentaje_comision' => 2, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => $limiteCredito,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearClienteQA50(Distribuidora $distribuidora): Cliente
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'QA50-'.uniqid(), 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);
    HistorialClienteDistr::create(['distribuidor_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'fecha_inicio' => now(), 'fecha_fin' => null]);

    return $cliente;
}

function productoQA50(float $monto, int $creadoPor): Producto
{
    return Producto::create(['monto' => $monto, 'quincenas' => 4, 'descripcion' => 'QA50-'.uniqid(), 'activo' => true, 'created_by' => $creadoPor]);
}

/**
 * Siempre trae al usuario fresco de la BD (nunca $distribuidora->usuario) -- si se reutiliza
 * la relación ya cargada de una llamada anterior, ValeService::solicitar() hace
 * $usuario->distribuidora internamente y puede traer un snapshot viejo de limite_credito
 * (de antes de un aumento), dando falsos negativos en las pruebas que no son el bug real.
 */
function usuarioFrescoQA50(Distribuidora $distribuidora): User
{
    return User::findOrFail($distribuidora->usuario_id);
}

/** Solicita, valida y autoriza de punta a punta -- así el monto cuenta contra el crédito. */
function darValeCompletoQA50(ValeService $svc, Distribuidora $distribuidora, Cliente $cliente, float $monto): void
{
    $producto = productoQA50($monto, $distribuidora->usuario_id);
    $vale = $svc->solicitar(['cliente_id' => $cliente->id, 'producto_id' => $producto->id], usuarioFrescoQA50($distribuidora));
    $vale = $svc->validar($vale, usuarioFrescoQA50($distribuidora), '012345678901234567', true, true);
    $svc->autorizar($vale, usuarioFrescoQA50($distribuidora));
}

beforeEach(function (): void {
    $admin = User::factory()->create();
    Configuracion::create(['clave' => 'regla_50_pct', 'valor' => '50', 'tipo_dato' => 'decimal', 'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id]);
});

it('bloquea el primer vale de una distribuidora nueva si excede el 50% del crédito total + el margen', function (): void {
    $distribuidora = crearDistribuidoraQA50(10000);
    $cliente = crearClienteQA50($distribuidora);
    // Tope esperado: 10000 * 50% + margen (default $500) = 5500.
    $producto = productoQA50(5501, $distribuidora->usuario_id);

    expect(fn () => app(ValeService::class)->solicitar(['cliente_id' => $cliente->id, 'producto_id' => $producto->id], usuarioFrescoQA50($distribuidora)))
        ->toThrow(HttpException::class);
});

it('permite el primer vale de una distribuidora nueva justo en el tope (50% + margen)', function (): void {
    $distribuidora = crearDistribuidoraQA50(10000);
    $cliente = crearClienteQA50($distribuidora);
    // Tope esperado: 10000 * 50% + margen (default $500) = 5500.
    $producto = productoQA50(5500, $distribuidora->usuario_id);

    $vale = app(ValeService::class)->solicitar(['cliente_id' => $cliente->id, 'producto_id' => $producto->id], usuarioFrescoQA50($distribuidora));

    expect($vale->id)->toBeGreaterThan(0);
});

it('el segundo vale ya no respeta el tope del 50%, solo el credito disponible', function (): void {
    $distribuidora = crearDistribuidoraQA50(10000);
    $svc = app(ValeService::class);

    darValeCompletoQA50($svc, $distribuidora, crearClienteQA50($distribuidora), 2000);
    $distribuidora->refresh();
    expect((float) $distribuidora->credito_disponible)->toBe(8000.0);

    // $7000 es mas del 50% de los $10000 totales, pero cabe en los $8000 disponibles.
    $cliente = crearClienteQA50($distribuidora);
    $producto = productoQA50(7000, $distribuidora->usuario_id);
    $vale = $svc->solicitar(['cliente_id' => $cliente->id, 'producto_id' => $producto->id], usuarioFrescoQA50($distribuidora));
    expect($vale->id)->toBeGreaterThan(0);

    // $8001 ya no cabe en el disponible restante -- lo bloquea el credito, no el 50%.
    $cliente2 = crearClienteQA50($distribuidora);
    $producto2 = productoQA50(8001, $distribuidora->usuario_id);
    expect(fn () => $svc->solicitar(['cliente_id' => $cliente2->id, 'producto_id' => $producto2->id], usuarioFrescoQA50($distribuidora)))
        ->toThrow(HttpException::class);
});

it('el primer vale tras un aumento de credito respeta el 50% del disponible mas el margen', function (): void {
    $distribuidora = crearDistribuidoraQA50(10000);
    $svc = app(ValeService::class);
    $admin = User::factory()->create();

    darValeCompletoQA50($svc, $distribuidora, crearClienteQA50($distribuidora), 2000);
    $distribuidora->refresh();
    expect((float) $distribuidora->credito_disponible)->toBe(8000.0);

    SolicitudAumentoCredito::create([
        'distribuidora_id' => $distribuidora->id, 'solicitado_por' => $distribuidora->usuario_id,
        'limite_credito_anterior' => 10000, 'monto_solicitado' => 20000, 'monto_otorgado' => 20000,
        'motivo' => 'test', 'estado' => 'aprobada', 'fecha_decision' => now(), 'decidido_por' => $admin->id,
    ]);
    $distribuidora->update(['limite_credito' => 20000]);
    $distribuidora->refresh();
    expect((float) $distribuidora->credito_disponible)->toBe(18000.0);

    // Tope esperado: disponible(18000) * 50% + margen (default $500) = 9500.
    $cliente = crearClienteQA50($distribuidora);
    $producto = productoQA50(9501, $distribuidora->usuario_id);
    expect(fn () => $svc->solicitar(['cliente_id' => $cliente->id, 'producto_id' => $producto->id], usuarioFrescoQA50($distribuidora)))
        ->toThrow(HttpException::class);

    $cliente2 = crearClienteQA50($distribuidora);
    $producto2 = productoQA50(9500, $distribuidora->usuario_id);
    $vale = $svc->solicitar(['cliente_id' => $cliente2->id, 'producto_id' => $producto2->id], usuarioFrescoQA50($distribuidora));
    expect($vale->id)->toBeGreaterThan(0);
});

it('el segundo vale tras el aumento ya no respeta el tope del 50%, solo el disponible', function (): void {
    $distribuidora = crearDistribuidoraQA50(10000);
    $svc = app(ValeService::class);
    $admin = User::factory()->create();

    darValeCompletoQA50($svc, $distribuidora, crearClienteQA50($distribuidora), 2000);

    SolicitudAumentoCredito::create([
        'distribuidora_id' => $distribuidora->id, 'solicitado_por' => $distribuidora->usuario_id,
        'limite_credito_anterior' => 10000, 'monto_solicitado' => 20000, 'monto_otorgado' => 20000,
        'motivo' => 'test', 'estado' => 'aprobada', 'fecha_decision' => now(), 'decidido_por' => $admin->id,
    ]);
    $distribuidora->update(['limite_credito' => 20000]);

    // created_at/fecha_decision solo guardan hasta el segundo: sin cruzar un segundo real de
    // reloj entre estos pasos, el vale que "estrena" el aumento (abajo) podria quedar en el
    // mismo segundo que fecha_decision y aumentoCreditoSinConsumir() seguiria viendolo como
    // "sin estrenar" (ver el test de REGRESION mas abajo, que prueba deliberadamente ese caso).
    sleep(1);

    darValeCompletoQA50($svc, $distribuidora, crearClienteQA50($distribuidora), 9500);
    $distribuidora->refresh();
    expect((float) $distribuidora->credito_disponible)->toBe(8500.0);

    // $8000 sigue siendo mucho mas del 50% de cualquier base, pero ya no aplica el tope.
    $cliente = crearClienteQA50($distribuidora);
    $producto = productoQA50(8000, $distribuidora->usuario_id);
    $vale = $svc->solicitar(['cliente_id' => $cliente->id, 'producto_id' => $producto->id], usuarioFrescoQA50($distribuidora));
    expect($vale->id)->toBeGreaterThan(0);
});

it('REGRESION: un vale creado en el mismo segundo que un aumento aprobado no debe saltarse el tope del siguiente vale', function (): void {
    // Antes del fix, comparar con >= hacia que un vale creado justo ANTES del aumento pero
    // dentro del mismo segundo (created_at/fecha_decision solo guardan hasta el segundo) se
    // leyera como "ya se uso el aumento", saltandose por completo la caucion del 50% en el
    // vale que en realidad debia llevarla.
    $distribuidora = crearDistribuidoraQA50(10000);
    $svc = app(ValeService::class);
    $admin = User::factory()->create();

    darValeCompletoQA50($svc, $distribuidora, crearClienteQA50($distribuidora), 2000);

    $ultimoVale = $distribuidora->vales()->latest('id')->first();
    $mismoSegundo = $ultimoVale->created_at;

    SolicitudAumentoCredito::create([
        'distribuidora_id' => $distribuidora->id, 'solicitado_por' => $distribuidora->usuario_id,
        'limite_credito_anterior' => 10000, 'monto_solicitado' => 20000, 'monto_otorgado' => 20000,
        'motivo' => 'test', 'estado' => 'aprobada', 'fecha_decision' => $mismoSegundo, 'decidido_por' => $admin->id,
    ]);
    $distribuidora->update(['limite_credito' => 20000]);
    $distribuidora->refresh();

    expect($distribuidora->aumentoCreditoSinConsumir())->not->toBeNull();

    // Disponible 18000 * 50% + margen 500 = 9500 -- $9501 debe seguir bloqueado.
    $cliente = crearClienteQA50($distribuidora);
    $producto = productoQA50(9501, $distribuidora->usuario_id);
    expect(fn () => $svc->solicitar(['cliente_id' => $cliente->id, 'producto_id' => $producto->id], usuarioFrescoQA50($distribuidora)))
        ->toThrow(HttpException::class);
});
