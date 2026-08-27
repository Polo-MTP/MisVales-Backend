<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\RelacionDetalle;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Ejemplo del enunciado: cliente A no paga su vale de 500 en el corte 1; en el corte 2 (otro
 * vale de la MISMA distribuidora, de cliente B) se le agrega la multa al de A. El estado de
 * cuenta debe mostrar el saldo de A ya con multa ($800) y el de B ($500) sumando $1300 en
 * total, sin tener que ir corte por corte.
 */
function crearDistribuidoraEstadoCuenta(): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearClienteYValeEstadoCuenta(Distribuidora $distribuidora, string $nombre): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => $nombre, 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => 500, 'quincenas' => 1,
        'tipo' => 'vale-digital', 'estado' => 'autorizado', 'fecha_autorizacion' => now(),
    ]);
}

function crearCorteEstadoCuenta(Distribuidora $distribuidora, string $fechaCorte, Vale $vale, float $total, float $recargo, string $estado, float $pago = 0): void
{
    $relacion = Relacion::create([
        'distribuidora_id' => $distribuidora->id, 'sucursal_id' => $distribuidora->sucursal_id,
        'referencia_pago' => 'REF-'.uniqid(), 'fecha_corte' => $fechaCorte, 'fecha_limite_pago' => $fechaCorte,
        'limite_credito_snapshot' => 20000, 'estado' => 'pendiente',
    ]);

    RelacionDetalle::create([
        'relacion_id' => $relacion->id, 'vale_id' => $vale->id, 'concepto' => sprintf('%05d%04d', $vale->id, 1),
        'cliente_id' => $vale->cliente_id,
        'cuota_numero' => 1, 'cuotas_totales' => 1, 'capital' => 500, 'comision' => 0,
        'interes' => 0, 'seguro' => 0, 'categoria' => 0, 'recargo' => $recargo, 'pago' => $pago,
        'total' => $total, 'estado' => $estado,
    ]);
}

it('agrupa por cliente el saldo pendiente a través de todos los cortes, con el total general', function (): void {
    $distribuidora = crearDistribuidoraEstadoCuenta();
    $valeA = crearClienteYValeEstadoCuenta($distribuidora, 'Cliente A');
    $valeB = crearClienteYValeEstadoCuenta($distribuidora, 'Cliente B');

    // Corte 1: vale de A, $500, sin pagar -> ya vencido con multa de $300 -> total $800.
    crearCorteEstadoCuenta($distribuidora, '2026-01-15', $valeA, 800, 300, 'vencida');
    // Corte 2 (otro vale, cliente B): $500, recién generado, sin pagar.
    crearCorteEstadoCuenta($distribuidora, '2026-01-31', $valeB, 500, 0, 'pendiente');

    $gerente = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Gerente General'])->id, 'is_active' => true]);
    Sanctum::actingAs($gerente);

    $response = $this->getJson("/api/v1/distribuidoras/{$distribuidora->id}/estado-cuenta");

    $response->assertStatus(200);
    $data = $response->json('data');

    expect((float) $data['total_pendiente'])->toBe(1300.0)
        ->and($data['clientes'])->toHaveCount(2);

    $porNombre = collect($data['clientes'])->keyBy('nombre');
    expect((float) $porNombre['Cliente A Prueba']['saldo_pendiente'])->toBe(800.0)
        ->and((float) $porNombre['Cliente B Prueba']['saldo_pendiente'])->toBe(500.0);
});

it('una cuota ya pagada no cuenta en el estado de cuenta', function (): void {
    $distribuidora = crearDistribuidoraEstadoCuenta();
    $vale = crearClienteYValeEstadoCuenta($distribuidora, 'Cliente Pagado');
    crearCorteEstadoCuenta($distribuidora, '2026-01-15', $vale, 500, 0, 'pagado', 500);

    $gerente = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Gerente General'])->id, 'is_active' => true]);
    Sanctum::actingAs($gerente);

    $response = $this->getJson("/api/v1/distribuidoras/{$distribuidora->id}/estado-cuenta");

    expect((float) $response->json('data.total_pendiente'))->toBe(0.0)
        ->and($response->json('data.clientes'))->toBe([]);
});

it('una distribuidora puede ver su propio estado de cuenta', function (): void {
    $distribuidora = crearDistribuidoraEstadoCuenta();
    $vale = crearClienteYValeEstadoCuenta($distribuidora, 'Cliente A');
    crearCorteEstadoCuenta($distribuidora, '2026-01-15', $vale, 500, 0, 'pendiente');

    Sanctum::actingAs($distribuidora->usuario);

    $response = $this->getJson("/api/v1/distribuidoras/{$distribuidora->id}/estado-cuenta")
        ->assertStatus(200);

    expect((float) $response->json('data.total_pendiente'))->toBe(500.0);
});

it('una distribuidora NO puede ver el estado de cuenta de otra', function (): void {
    $distribuidoraA = crearDistribuidoraEstadoCuenta();
    $distribuidoraB = crearDistribuidoraEstadoCuenta();

    Sanctum::actingAs($distribuidoraA->usuario);

    $this->getJson("/api/v1/distribuidoras/{$distribuidoraB->id}/estado-cuenta")
        ->assertStatus(403);
});
