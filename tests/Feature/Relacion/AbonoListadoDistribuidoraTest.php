<?php

declare(strict_types=1);

use App\Models\AbonoConciliacion;
use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearDistribuidoraConAbono(): array
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $usuario = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $distribuidora = Distribuidora::create([
        'usuario_id' => $usuario->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    $relacion = Relacion::create([
        'distribuidora_id' => $distribuidora->id, 'sucursal_id' => $sucursal->id, 'referencia_pago' => 'REF-'.uniqid(),
        'fecha_corte' => '2026-02-15', 'fecha_limite_pago' => '2026-02-16',
        'fecha_pago_anticipado_desde' => '2026-02-13', 'fecha_pago_anticipado_hasta' => '2026-02-15',
        'limite_credito_snapshot' => $distribuidora->limite_credito,
        'total_a_pagar' => 2000, 'total_abonado' => 0, 'estado' => 'pendiente',
    ]);

    $abono = AbonoConciliacion::create([
        'relacion_id' => $relacion->id, 'referencia_leida' => $relacion->referencia_pago, 'monto' => 1500,
        'fecha_pago' => '2026-02-14', 'tipo_pago' => 'transferencia', 'estado' => 'conciliado', 'lote_archivo' => 'test',
        'subido_por' => $usuario->id,
    ]);

    return [$distribuidora, $abono, $usuario];
}

it('una distribuidora puede listar sus propios abonos (para poder levantar una queja)', function (): void {
    [, $abono, $usuario] = crearDistribuidoraConAbono();

    Sanctum::actingAs($usuario);

    $response = $this->getJson('/api/v1/conciliaciones');

    $response->assertStatus(200);
    expect(collect($response->json('data.data'))->pluck('id'))->toContain($abono->id);
});

it('una distribuidora no ve los abonos de otra distribuidora en el listado', function (): void {
    [, , $usuarioA] = crearDistribuidoraConAbono();
    [, $abonoB] = crearDistribuidoraConAbono();

    Sanctum::actingAs($usuarioA);

    $response = $this->getJson('/api/v1/conciliaciones');

    $response->assertStatus(200);
    expect(collect($response->json('data.data'))->pluck('id'))->not->toContain($abonoB->id);
});

it('una cajera solo ve abonos de su propia sucursal, más los que aún no coinciden con ninguna relación', function (): void {
    [$distribuidoraA, $abonoA] = crearDistribuidoraConAbono();
    [, $abonoB] = crearDistribuidoraConAbono();

    // Abono que el banco reportó pero que todavía no se pudo ligar a ninguna relación: la cajera
    // sí debe verlo, es justo lo que tiene que identificar para pedir la conciliación manual.
    $sinCoincidencia = AbonoConciliacion::create([
        'relacion_id' => null, 'referencia_leida' => 'REF-DESCONOCIDA', 'monto' => 999,
        'fecha_pago' => '2026-02-14', 'tipo_pago' => 'transferencia', 'estado' => 'sin_coincidencia',
        'lote_archivo' => 'test', 'subido_por' => $distribuidoraA->usuario_id,
    ]);

    $rolCajera = Role::firstOrCreate(['name' => 'Cajera']);
    $cajera = User::factory()->create([
        'role_id' => $rolCajera->id, 'sucursal_id' => $distribuidoraA->sucursal_id, 'is_active' => true,
    ]);
    Sanctum::actingAs($cajera);

    $ids = collect($this->getJson('/api/v1/conciliaciones')->assertStatus(200)->json('data.data'))->pluck('id');

    expect($ids)->toContain($abonoA->id)
        ->and($ids)->toContain($sinCoincidencia->id)
        ->and($ids)->not->toContain($abonoB->id);
});

it('un gerente general sí ve los abonos de todas las sucursales', function (): void {
    [, $abonoA] = crearDistribuidoraConAbono();
    [, $abonoB] = crearDistribuidoraConAbono();

    $rol = Role::firstOrCreate(['name' => 'Gerente General']);
    Sanctum::actingAs(User::factory()->create(['role_id' => $rol->id, 'is_active' => true]));

    $ids = collect($this->getJson('/api/v1/conciliaciones')->assertStatus(200)->json('data.data'))->pluck('id');

    expect($ids)->toContain($abonoA->id)->and($ids)->toContain($abonoB->id);
});
