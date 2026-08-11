<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Distribuidora\PuntoCanjeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function crearDistribuidoraConPuntos(int $puntos): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA', 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::create(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-TEST', 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => $puntos, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
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
