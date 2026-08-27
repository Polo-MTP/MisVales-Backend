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
use App\Services\Relacion\RelacionCalculoService;
use App\Services\Vale\ValeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Antes, el seguro de un vale se recalculaba en vivo en CADA corte contra seguros_tabla -- un
 * cambio de tarifa a medio plazo afectaba retroactivamente las cuotas que faltaban por cobrar
 * de vales que ya llevaban tiempo autorizados. Ahora ValeService::solicitar() congela el
 * seguro (seguro_tabla_id/seguro_monto) desde que se genera el vale, y ese valor es el que se
 * usa en TODOS los cortes de ese vale sin importar qué cambie después en la configuración.
 */
function crearDistribuidoraConClienteParaSeguro(float $limiteCredito = 20000): array
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
    foreach ([
        'regla_50_pct' => '50',
        'comision_base_pct' => '10',
        'interes_pct_quincena' => '5',
        'multa_no_pago' => '300',
    ] as $clave => $valor) {
        Configuracion::create(['clave' => $clave, 'valor' => $valor, 'tipo_dato' => 'decimal', 'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id]);
    }
});

it('el vale solicitado congela el seguro vigente por rango de monto al momento de generarse', function (): void {
    [$distribuidora, $cliente] = crearDistribuidoraConClienteParaSeguro();
    $seguro = SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);
    $producto = Producto::create(['monto' => 8000, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    $vale = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);

    expect($vale->seguro_tabla_id)->toBe($seguro->id)
        ->and((float) $vale->seguro_monto)->toBe(100.0);
});

it('no se puede solicitar un vale si ningún rango de seguro activo cubre su monto', function (): void {
    [$distribuidora, $cliente] = crearDistribuidoraConClienteParaSeguro();
    // Sin ningún SeguroTabla creado -- no hay nada que cubra el monto del producto.
    $producto = Producto::create(['monto' => 8000, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    expect(fn () => app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario))->toThrow(DomainException::class, 'No hay una tarifa de seguro configurada para este monto. No se puede generar el vale sin seguro.');
});

it('REGRESION: el seguro con el que se generó el vale permanece igual en cortes posteriores aunque la tabla de seguros cambie', function (): void {
    [$distribuidora, $cliente] = crearDistribuidoraConClienteParaSeguro();
    $seguro = SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);
    $producto = Producto::create(['monto' => 8000, 'quincenas' => 4, 'descripcion' => 'Test', 'activo' => true, 'created_by' => $distribuidora->usuario_id]);

    $vale = app(ValeService::class)->solicitar([
        'cliente_id' => $cliente->id,
        'producto_id' => $producto->id,
    ], $distribuidora->usuario);
    $vale->update(['estado' => 'validado']);
    $vale = app(ValeService::class)->autorizar($vale, $distribuidora->usuario);

    // Corte 1: el seguro del vale es 100/4 = 25.
    $relacion1 = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-03-15');
    expect((float) $relacion1->total_seguro)->toBe(25.0);

    // El Gerente General cambia la tarifa de seguro DESPUÉS de que el vale ya fue autorizado.
    $seguro->update(['seguro_monto' => 500]);

    // Corte 2 (misma cuota del mismo vale, siguiente quincena): debe seguir usando el 100
    // original congelado al solicitarse, NO el 500 nuevo -- antes de este cambio, este corte
    // habría dado 500/4 = 125 en vez de 25.
    $relacion2 = app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-03-31');
    expect((float) $relacion2->total_seguro)->toBe(25.0);
});
