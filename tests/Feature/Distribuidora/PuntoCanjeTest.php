<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Distribuidora\PuntoCanjeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearDistribuidoraConPuntos(int $puntos): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => $puntos, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearCajeraActiva(): User
{
    $role = Role::firstOrCreate(['name' => 'Cajera']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

it('la cajera canjea puntos: descuenta el saldo y registra el movimiento redimido', function (): void {
    $distribuidora = crearDistribuidoraConPuntos(100);
    $cajera = User::factory()->create();

    $movimiento = app(PuntoCanjeService::class)->canjear($distribuidora, 30, 'Canje en caja', $cajera);

    expect($movimiento->tipo)->toBe('redimido')
        ->and($movimiento->cantidad)->toBe(-30)
        ->and($movimiento->registrado_por)->toBe($cajera->id)
        ->and($distribuidora->fresh()->puntos_acumulados)->toBe(70);
});

it('no permite canjear más puntos de los que tiene acumulados la distribuidora', function (): void {
    $distribuidora = crearDistribuidoraConPuntos(10);
    $cajera = User::factory()->create();

    app(PuntoCanjeService::class)->canjear($distribuidora, 50, 'Canje excesivo', $cajera);
})->throws(DomainException::class);

it('no permite canjear una cantidad de cero o negativa', function (): void {
    $distribuidora = crearDistribuidoraConPuntos(10);
    $cajera = User::factory()->create();

    app(PuntoCanjeService::class)->canjear($distribuidora, 0, 'Canje inválido', $cajera);
})->throws(DomainException::class);

it('la cajera lista por HTTP el historial de movimientos de puntos de una distribuidora', function (): void {
    $distribuidora = crearDistribuidoraConPuntos(100);
    $cajera = crearCajeraActiva();

    app(PuntoCanjeService::class)->canjear($distribuidora, 30, 'Canje en caja', $cajera);

    Sanctum::actingAs($cajera);

    $response = $this->getJson("/api/v1/distribuidoras/{$distribuidora->id}/puntos");

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.tipo', 'redimido')
        ->assertJsonPath('data.data.0.cantidad', -30);
});

it('la distribuidora puede ver su propio historial de puntos pero no el de otra', function (): void {
    $distribuidoraA = crearDistribuidoraConPuntos(100);
    $distribuidoraB = crearDistribuidoraConPuntos(50);
    $cajera = crearCajeraActiva();
    app(PuntoCanjeService::class)->canjear($distribuidoraA, 10, 'Canje A', $cajera);

    Sanctum::actingAs($distribuidoraA->usuario->fresh());
    $this->getJson("/api/v1/distribuidoras/{$distribuidoraA->id}/puntos")
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.data');

    Sanctum::actingAs($distribuidoraB->usuario->fresh());
    $this->getJson("/api/v1/distribuidoras/{$distribuidoraA->id}/puntos")
        ->assertStatus(403);
});

it('filtra el historial de puntos por tipo', function (): void {
    $distribuidora = crearDistribuidoraConPuntos(100);
    $cajera = crearCajeraActiva();
    app(PuntoCanjeService::class)->canjear($distribuidora, 10, 'Canje 1', $cajera);
    App\Models\PuntoMovimiento::query()->create([
        'distribuidora_id' => $distribuidora->id,
        'tipo' => 'generado',
        'cantidad' => 50,
        'motivo' => 'Pago anticipado',
    ]);

    Sanctum::actingAs($cajera);

    $this->getJson("/api/v1/distribuidoras/{$distribuidora->id}/puntos?tipo=generado")
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.tipo', 'generado');
});
