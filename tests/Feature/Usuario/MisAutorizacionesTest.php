<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\HistorialEstadoDistribuidora;
use App\Models\Relacion;
use App\Models\RelacionPerdon;
use App\Models\Role;
use App\Models\SolicitudAumentoCredito;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearGerenteGeneralAut(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente General']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

function crearDistribuidoraAut(): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 10000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

it('junta en un solo feed movimientos de distintas tablas, ordenados del más reciente al más viejo', function (): void {
    $gerente = crearGerenteGeneralAut();
    $distribuidora = crearDistribuidoraAut();

    $relacion = Relacion::create([
        'distribuidora_id' => $distribuidora->id, 'sucursal_id' => $distribuidora->sucursal_id,
        'referencia_pago' => 'REF-'.uniqid(), 'fecha_corte' => '2026-02-15', 'fecha_limite_pago' => '2026-02-16',
        'limite_credito_snapshot' => 10000, 'estado' => 'vencida',
    ]);

    $perdon = RelacionPerdon::create([
        'distribuidora_id' => $distribuidora->id, 'relacion_id' => $relacion->id,
        'numero_perdon' => 1, 'autorizado_por' => $gerente->id, 'motivo' => 'primera falta',
    ]);
    $perdon->created_at = now()->subDay();
    $perdon->save();

    $aumento = SolicitudAumentoCredito::create([
        'distribuidora_id' => $distribuidora->id, 'solicitado_por' => $distribuidora->usuario_id,
        'limite_credito_anterior' => 10000, 'monto_solicitado' => 5000, 'monto_otorgado' => 5000,
        'estado' => 'aprobada', 'decidido_por' => $gerente->id, 'fecha_decision' => now(),
    ]);

    Sanctum::actingAs($gerente);

    $response = $this->getJson('/api/v1/usuarios/mis-autorizaciones');

    $response->assertStatus(200);
    $tipos = collect($response->json('data.data'))->pluck('tipo');

    expect($tipos->all())->toBe(['aumento_credito', 'perdon_relacion']);
});

it('no muestra movimientos autorizados por otro usuario', function (): void {
    $gerente = crearGerenteGeneralAut();
    $otroGerente = crearGerenteGeneralAut();
    $distribuidora = crearDistribuidoraAut();

    HistorialEstadoDistribuidora::create([
        'distribuidora_id' => $distribuidora->id, 'estado_anterior' => 'ACTIVO', 'estado_nuevo' => 'MOROSO',
        'motivo' => 'prueba', 'cambiado_por' => $otroGerente->id, 'fecha' => now(),
    ]);

    Sanctum::actingAs($gerente);

    $response = $this->getJson('/api/v1/usuarios/mis-autorizaciones');

    $response->assertStatus(200)->assertJsonCount(0, 'data.data');
});

it('respeta la paginación (per_page)', function (): void {
    $gerente = crearGerenteGeneralAut();
    $distribuidora = crearDistribuidoraAut();

    for ($i = 0; $i < 3; $i++) {
        HistorialEstadoDistribuidora::create([
            'distribuidora_id' => $distribuidora->id, 'estado_anterior' => 'ACTIVO', 'estado_nuevo' => 'MOROSO',
            'motivo' => 'prueba '.$i, 'cambiado_por' => $gerente->id, 'fecha' => now()->subMinutes($i),
        ]);
    }

    Sanctum::actingAs($gerente);

    $response = $this->getJson('/api/v1/usuarios/mis-autorizaciones?per_page=2');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data.data')
        ->assertJsonPath('data.total', 3);
});
