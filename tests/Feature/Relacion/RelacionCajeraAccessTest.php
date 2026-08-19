<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Antes, GET /relaciones y GET /relaciones/{id} estaban cerrados para Cajera — no había forma
 * de construir un buscador de relaciones para el flujo de conciliación manual (donde hoy se le
 * pide escribir el ID a mano). Ahora tiene acceso de solo lectura, filtrado a su sucursal.
 */
function crearSucursalRelCajera(string $codigo): Sucursal
{
    return Sucursal::create(['nombre' => 'Sucursal '.$codigo, 'codigo' => $codigo, 'es_matriz' => false, 'is_active' => true]);
}

function crearDistribuidoraRelCajera(Sucursal $sucursal, string $numero): Distribuidora
{
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => $numero, 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearRelacionCajera(Distribuidora $distribuidora, Sucursal $sucursal, string $referencia): Relacion
{
    return Relacion::create([
        'distribuidora_id' => $distribuidora->id,
        'sucursal_id' => $sucursal->id,
        'referencia_pago' => $referencia,
        'fecha_corte' => '2026-02-15',
        'fecha_limite_pago' => '2026-02-16',
        'limite_credito_snapshot' => 20000,
        'estado' => 'pendiente',
    ]);
}

function crearCajeraDeSucursal(Sucursal $sucursal): User
{
    $role = Role::firstOrCreate(['name' => 'Cajera']);

    return User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
}

it('la cajera lista solo las relaciones de su propia sucursal', function (): void {
    $sucursalA = crearSucursalRelCajera('SUC-A');
    $sucursalB = crearSucursalRelCajera('SUC-B');
    $distA = crearDistribuidoraRelCajera($sucursalA, 'DIST-A');
    $distB = crearDistribuidoraRelCajera($sucursalB, 'DIST-B');
    crearRelacionCajera($distA, $sucursalA, 'REF-A-001');
    crearRelacionCajera($distB, $sucursalB, 'REF-B-001');

    $cajera = crearCajeraDeSucursal($sucursalA);
    Sanctum::actingAs($cajera);

    $this->getJson('/api/v1/relaciones')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.referencia_pago', 'REF-A-001');
});

it('la cajera puede buscar una relación por referencia_pago para el flujo de conciliación manual', function (): void {
    $sucursal = crearSucursalRelCajera('SUC-A');
    $dist = crearDistribuidoraRelCajera($sucursal, 'DIST-A');
    crearRelacionCajera($dist, $sucursal, 'REF-90001');
    crearRelacionCajera($dist, $sucursal, 'REF-90002');

    $cajera = crearCajeraDeSucursal($sucursal);
    Sanctum::actingAs($cajera);

    $this->getJson('/api/v1/relaciones?referencia_pago=90001')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.referencia_pago', 'REF-90001');
});

it('la cajera no puede ver el detalle de una relación de otra sucursal', function (): void {
    $sucursalA = crearSucursalRelCajera('SUC-A');
    $sucursalB = crearSucursalRelCajera('SUC-B');
    $distB = crearDistribuidoraRelCajera($sucursalB, 'DIST-B');
    $relacionB = crearRelacionCajera($distB, $sucursalB, 'REF-B-001');

    $cajera = crearCajeraDeSucursal($sucursalA);
    Sanctum::actingAs($cajera);

    $this->getJson("/api/v1/relaciones/{$relacionB->id}")
        ->assertStatus(403);
});

it('la cajera sí puede ver el detalle de una relación de su propia sucursal', function (): void {
    $sucursal = crearSucursalRelCajera('SUC-A');
    $dist = crearDistribuidoraRelCajera($sucursal, 'DIST-A');
    $relacion = crearRelacionCajera($dist, $sucursal, 'REF-A-001');

    $cajera = crearCajeraDeSucursal($sucursal);
    Sanctum::actingAs($cajera);

    $this->getJson("/api/v1/relaciones/{$relacion->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.referencia_pago', 'REF-A-001');
});

/**
 * Antes, GET /relaciones y GET /relaciones/{id} estaban cerrados también para Distribuidora —
 * no tenía forma de ver cuánto le tocaba pagar cada quincena ni la fecha límite. Ahora ve de
 * solo lectura sus propios cortes.
 */
it('la distribuidora lista solo sus propias relaciones (lo que le toca pagar cada quincena)', function (): void {
    $sucursal = crearSucursalRelCajera('SUC-A');
    $distPropia = crearDistribuidoraRelCajera($sucursal, 'DIST-PROPIA');
    $distAjena = crearDistribuidoraRelCajera($sucursal, 'DIST-AJENA');
    crearRelacionCajera($distPropia, $sucursal, 'REF-PROPIA-001');
    crearRelacionCajera($distAjena, $sucursal, 'REF-AJENA-001');

    Sanctum::actingAs($distPropia->usuario);

    $this->getJson('/api/v1/relaciones')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.referencia_pago', 'REF-PROPIA-001');
});

it('la distribuidora no puede ver el detalle de una relación de otra distribuidora', function (): void {
    $sucursal = crearSucursalRelCajera('SUC-A');
    $distPropia = crearDistribuidoraRelCajera($sucursal, 'DIST-PROPIA');
    $distAjena = crearDistribuidoraRelCajera($sucursal, 'DIST-AJENA');
    $relacionAjena = crearRelacionCajera($distAjena, $sucursal, 'REF-AJENA-001');

    Sanctum::actingAs($distPropia->usuario);

    $this->getJson("/api/v1/relaciones/{$relacionAjena->id}")
        ->assertStatus(403);
});

it('la distribuidora sí puede ver el detalle y los totales a pagar de su propia relación', function (): void {
    $sucursal = crearSucursalRelCajera('SUC-A');
    $dist = crearDistribuidoraRelCajera($sucursal, 'DIST-PROPIA');
    $relacion = crearRelacionCajera($dist, $sucursal, 'REF-PROPIA-001');
    $relacion->update(['total_a_pagar' => 3500, 'total_abonado' => 0]);

    Sanctum::actingAs($dist->usuario);

    $this->getJson("/api/v1/relaciones/{$relacion->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.referencia_pago', 'REF-PROPIA-001')
        ->assertJsonPath('data.fecha_limite_pago', '2026-02-16')
        ->assertJsonPath('data.totales.a_pagar', '3500.00');
});
