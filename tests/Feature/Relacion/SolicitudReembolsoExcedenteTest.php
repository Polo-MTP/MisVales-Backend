<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\ExcedenteMovimiento;
use App\Models\Notificacion;
use App\Models\Role;
use App\Models\SolicitudReembolsoExcedente;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use App\Services\Relacion\SolicitudReembolsoExcedenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Un vale ya 'pagado' (liquidó su última cuota) que le sobra saldo a favor ya no tiene ninguna
 * cuota futura de ÉL MISMO que lo consuma solo (ExcedenteConciliacionService solo aplica el
 * saldo a las cuotas pendientes del mismo vale). La cajera pide el reembolso; el Gerente lo
 * autoriza. El dinero real se mueve fuera del sistema -- esto solo deja constancia.
 */
function crearSucursalConCajeraYGerente(): array
{
    $sucursal = Sucursal::create(['nombre' => 'Suc-'.uniqid(), 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);

    $rolCajera = Role::firstOrCreate(['name' => 'Cajera'], ['factor_count' => 1]);
    $rolGerente = Role::firstOrCreate(['name' => 'Gerente de Sucursal'], ['factor_count' => 3]);

    $cajera = User::factory()->create(['role_id' => $rolCajera->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $gerente = User::factory()->create(['role_id' => $rolGerente->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return [$sucursal, $cajera, $gerente];
}

function crearValePagadoConExcedente(Sucursal $sucursal, float $saldoExcedente): Vale
{
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $rolDist = Role::firstOrCreate(['name' => 'Distribuidora'], ['factor_count' => 2]);
    $usuarioDist = User::factory()->create(['role_id' => $rolDist->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $distribuidora = Distribuidora::create([
        'usuario_id' => $usuarioDist->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Pagado', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => 5000,
        'quincenas' => 1, 'tipo' => 'vale-digital', 'estado' => 'pagado', 'fecha_autorizacion' => now(),
        'saldo_excedente' => $saldoExcedente,
    ]);
}

it('la cajera solicita el reembolso de un vale ya pagado con saldo a favor', function (): void {
    [$sucursal, $cajera, $gerente] = crearSucursalConCajeraYGerente();
    $vale = crearValePagadoConExcedente($sucursal, 150.75);

    Sanctum::actingAs($cajera);

    $response = $this->postJson("/api/v1/vales/{$vale->id}/reembolso-excedente", ['motivo' => 'Vale liquidado, ya no tiene más cuotas.']);

    $response->assertStatus(201)
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.monto', 150.75);

    expect(Notificacion::where('destinatario_id', $gerente->id)->where('accion', 'reembolso_excedente_solicitado')->exists())->toBeTrue();
});

it('no permite solicitar el reembolso de un vale que todavía tiene cuotas pendientes -- el saldo se le aplica solo', function (): void {
    [, $cajera] = crearSucursalConCajeraYGerente();
    $sucursal = Sucursal::first();
    $vale = crearValePagadoConExcedente($sucursal, 100);
    $vale->update(['estado' => 'autorizado']);

    Sanctum::actingAs($cajera);

    $this->postJson("/api/v1/vales/{$vale->id}/reembolso-excedente", [])
        ->assertStatus(422);
});

it('no permite solicitar el reembolso de un vale sin saldo a favor', function (): void {
    [$sucursal, $cajera] = crearSucursalConCajeraYGerente();
    $vale = crearValePagadoConExcedente($sucursal, 0);

    Sanctum::actingAs($cajera);

    $this->postJson("/api/v1/vales/{$vale->id}/reembolso-excedente", [])
        ->assertStatus(422);
});

it('una cajera no puede solicitar el reembolso de un vale de otra sucursal', function (): void {
    [, $cajera] = crearSucursalConCajeraYGerente();
    $otraSucursal = Sucursal::create(['nombre' => 'Otra', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $vale = crearValePagadoConExcedente($otraSucursal, 100);

    Sanctum::actingAs($cajera);

    $this->postJson("/api/v1/vales/{$vale->id}/reembolso-excedente", [])
        ->assertStatus(403);
});

it('no permite una segunda solicitud de reembolso mientras la primera sigue pendiente', function (): void {
    [$sucursal, $cajera] = crearSucursalConCajeraYGerente();
    $vale = crearValePagadoConExcedente($sucursal, 100);

    Sanctum::actingAs($cajera);
    $this->postJson("/api/v1/vales/{$vale->id}/reembolso-excedente", [])->assertStatus(201);
    $this->postJson("/api/v1/vales/{$vale->id}/reembolso-excedente", [])->assertStatus(422);
});

it('el gerente aprueba: el saldo del vale queda en cero, se notifica a la cajera y a la distribuidora, y queda registrado en el ledger', function (): void {
    [$sucursal, $cajera, $gerente] = crearSucursalConCajeraYGerente();
    $vale = crearValePagadoConExcedente($sucursal, 150.75);

    $solicitud = app(SolicitudReembolsoExcedenteService::class)->solicitar($vale, $cajera, 'motivo');

    Sanctum::actingAs($gerente);
    $response = $this->putJson("/api/v1/vales/reembolso-excedente/{$solicitud->id}/decidir", ['decision' => 'aprobada']);

    $response->assertStatus(200)
        ->assertJsonPath('data.estado', 'aprobada');

    expect((float) $vale->fresh()->saldo_excedente)->toBe(0.0);

    $movimiento = ExcedenteMovimiento::where('vale_id', $vale->id)->where('tipo', 'reembolsado')->first();
    expect($movimiento)->not->toBeNull()
        ->and((float) $movimiento->monto)->toBe(-150.75);

    expect(Notificacion::where('destinatario_id', $cajera->id)->where('accion', 'reembolso_excedente_aprobado')->exists())->toBeTrue()
        ->and(Notificacion::where('destinatario_id', $vale->distribuidora->usuario_id)->where('accion', 'reembolso_excedente_aprobado')->exists())->toBeTrue();
});

it('el gerente rechaza: el saldo del vale NO se toca y se notifica a la cajera', function (): void {
    [$sucursal, $cajera, $gerente] = crearSucursalConCajeraYGerente();
    $vale = crearValePagadoConExcedente($sucursal, 150.75);

    $solicitud = app(SolicitudReembolsoExcedenteService::class)->solicitar($vale, $cajera, null);

    Sanctum::actingAs($gerente);
    $this->putJson("/api/v1/vales/reembolso-excedente/{$solicitud->id}/decidir", ['decision' => 'rechazada', 'comentario' => 'No procede'])
        ->assertStatus(200)
        ->assertJsonPath('data.estado', 'rechazada');

    expect((float) $vale->fresh()->saldo_excedente)->toBe(150.75)
        ->and(Notificacion::where('destinatario_id', $cajera->id)->where('accion', 'reembolso_excedente_rechazado')->exists())->toBeTrue();
});

it('un gerente de otra sucursal no puede decidir la solicitud', function (): void {
    [$sucursal, $cajera] = crearSucursalConCajeraYGerente();
    $vale = crearValePagadoConExcedente($sucursal, 100);
    $solicitud = app(SolicitudReembolsoExcedenteService::class)->solicitar($vale, $cajera, null);

    $otraSucursal = Sucursal::create(['nombre' => 'Otra', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $rolGerente = Role::firstOrCreate(['name' => 'Gerente de Sucursal'], ['factor_count' => 3]);
    $gerenteAjeno = User::factory()->create(['role_id' => $rolGerente->id, 'sucursal_id' => $otraSucursal->id, 'is_active' => true]);

    Sanctum::actingAs($gerenteAjeno);
    $this->putJson("/api/v1/vales/reembolso-excedente/{$solicitud->id}/decidir", ['decision' => 'aprobada'])
        ->assertStatus(403);
});

it('un Coordinador no puede decidir -- solo el Gerente', function (): void {
    [$sucursal, $cajera] = crearSucursalConCajeraYGerente();
    $vale = crearValePagadoConExcedente($sucursal, 100);
    $solicitud = app(SolicitudReembolsoExcedenteService::class)->solicitar($vale, $cajera, null);

    $rolCoordinador = Role::firstOrCreate(['name' => 'Coordinador'], ['factor_count' => 2]);
    $coordinador = User::factory()->create(['role_id' => $rolCoordinador->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    Sanctum::actingAs($coordinador);
    $this->putJson("/api/v1/vales/reembolso-excedente/{$solicitud->id}/decidir", ['decision' => 'aprobada'])->assertStatus(403);
});

it('reembolsa lo que REALMENTE hay al momento de decidir, no el monto snapshoteado al solicitar', function (): void {
    [$sucursal, $cajera, $gerente] = crearSucursalConCajeraYGerente();
    $vale = crearValePagadoConExcedente($sucursal, 100);

    $solicitud = app(SolicitudReembolsoExcedenteService::class)->solicitar($vale, $cajera, null);
    expect((float) $solicitud->monto)->toBe(100.0);

    // Algo más le agregó saldo a este vale DESPUÉS de solicitar (ej. otro abono con concepto).
    $vale->increment('saldo_excedente', 30);

    Sanctum::actingAs($gerente);
    $this->putJson("/api/v1/vales/reembolso-excedente/{$solicitud->id}/decidir", ['decision' => 'aprobada'])
        ->assertStatus(200)
        ->assertJsonPath('data.monto', 130);

    expect((float) $vale->fresh()->saldo_excedente)->toBe(0.0);
});

it('la cajera solo ve las solicitudes que ella misma pidió', function (): void {
    [$sucursal, $cajera] = crearSucursalConCajeraYGerente();
    $rolCajera = Role::firstOrCreate(['name' => 'Cajera']);
    $otraCajera = User::factory()->create(['role_id' => $rolCajera->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $valeMio = crearValePagadoConExcedente($sucursal, 50);
    app(SolicitudReembolsoExcedenteService::class)->solicitar($valeMio, $cajera, null);

    $valeAjeno = crearValePagadoConExcedente($sucursal, 60);
    app(SolicitudReembolsoExcedenteService::class)->solicitar($valeAjeno, $otraCajera, null);

    Sanctum::actingAs($cajera);
    $response = $this->getJson('/api/v1/vales/reembolso-excedente');

    $response->assertStatus(200);
    $ids = collect($response->json('data.data'))->pluck('vale_id')->all();
    expect($ids)->toContain($valeMio->id)->not->toContain($valeAjeno->id);
});
