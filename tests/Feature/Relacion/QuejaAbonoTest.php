<?php

declare(strict_types=1);

use App\Models\AbonoConciliacion;
use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Relacion\ConciliacionBancariaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearDistribuidoraConRelacionYAbono(): array
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

it('la distribuidora levanta una queja sobre un abono de su propia relación', function (): void {
    [$distribuidora, $abono, $usuario] = crearDistribuidoraConRelacionYAbono();

    Sanctum::actingAs($usuario);

    $response = $this->postJson("/api/v1/conciliaciones/{$abono->id}/queja", [
        'motivo' => 'Yo pagué 2000, no 1500.',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.queja.motivo', 'Yo pagué 2000, no 1500.')
        ->assertJsonPath('data.queja.reportado_por', $usuario->name);

    expect($abono->fresh()->queja_por)->toBe($usuario->id);
});

it('una distribuidora no puede quejarse de un abono que no es de ella', function (): void {
    [$distribuidoraA, $abono] = crearDistribuidoraConRelacionYAbono();
    [$distribuidoraB, , $usuarioB] = crearDistribuidoraConRelacionYAbono();

    expect(fn () => app(ConciliacionBancariaService::class)->levantarQueja($abono, $usuarioB, 'No es mío'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, 'Este abono no pertenece a tu distribuidora.');
});
