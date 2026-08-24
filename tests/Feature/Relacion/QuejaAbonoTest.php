<?php

declare(strict_types=1);

use App\Models\AbonoConciliacion;
use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Notificacion;
use App\Models\Relacion;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Relacion\ConciliacionBancariaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Mismo criterio que ConciliacionBancariaService::levantarQueja(): 's3' solo si el bucket
 * está configurado, si no 'public'. Fijar 's3' a fuerzas rompía este test en cualquier
 * entorno sin bucket (como local) -- el código real ahí sube a 'public', así que
 * Storage::fake('s3') fake-eaba un disco que la petición real nunca tocaba.
 */
function discoConciliacionReal(): string
{
    return (config('filesystems.default') === 's3' || ! empty(config('filesystems.disks.s3.bucket')))
        ? 's3'
        : 'public';
}

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

it('avisa a las Cajeras de la sucursal cuando se levanta una queja, para que inicien la conciliación manual', function (): void {
    [$distribuidora, $abono, $usuario] = crearDistribuidoraConRelacionYAbono();

    $rolCajera = Role::firstOrCreate(['name' => 'Cajera']);
    $cajera = User::factory()->create(['role_id' => $rolCajera->id, 'sucursal_id' => $distribuidora->sucursal_id, 'is_active' => true]);
    // Cajera de otra sucursal: no debe enterarse de una queja que no le toca.
    $otraSucursal = Sucursal::create(['nombre' => 'Otra', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $cajeraDeOtraSucursal = User::factory()->create(['role_id' => $rolCajera->id, 'sucursal_id' => $otraSucursal->id, 'is_active' => true]);

    Sanctum::actingAs($usuario);

    $this->postJson("/api/v1/conciliaciones/{$abono->id}/queja", ['motivo' => 'Yo pagué 2000, no 1500.'])
        ->assertStatus(200);

    expect(Notificacion::where('destinatario_id', $cajera->id)->where('accion', 'abono_con_queja')->exists())->toBeTrue()
        ->and(Notificacion::where('destinatario_id', $cajeraDeOtraSucursal->id)->where('accion', 'abono_con_queja')->exists())->toBeFalse();
});

it('una distribuidora no puede quejarse de un abono que no es de ella', function (): void {
    [$distribuidoraA, $abono] = crearDistribuidoraConRelacionYAbono();
    [$distribuidoraB, , $usuarioB] = crearDistribuidoraConRelacionYAbono();

    expect(fn () => app(ConciliacionBancariaService::class)->levantarQueja($abono, $usuarioB, 'No es mío'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, 'Este abono no pertenece a tu distribuidora.');
});

it('la distribuidora puede adjuntar una captura de la transferencia al levantar la queja', function (): void {
    $disco = discoConciliacionReal();
    Storage::fake($disco);

    [$distribuidora, $abono, $usuario] = crearDistribuidoraConRelacionYAbono();

    Sanctum::actingAs($usuario);

    $response = $this->post("/api/v1/conciliaciones/{$abono->id}/queja", [
        'motivo' => 'Yo pagué 2000, no 1500.',
        'evidencia' => UploadedFile::fake()->image('transferencia.jpg'),
    ]);

    $response->assertStatus(200);

    $urlEvidencia = $response->json('data.queja.evidencia_url');
    expect($urlEvidencia)->not->toBeNull();

    $ruta = preg_replace('#^storage/#', '', ltrim((string) parse_url($urlEvidencia, PHP_URL_PATH), '/'));
    Storage::disk($disco)->assertExists($ruta);
});

it('la cajera de la sucursal ve la queja (con su evidencia) al listar los abonos', function (): void {
    Storage::fake(discoConciliacionReal());

    [$distribuidora, $abono, $usuario] = crearDistribuidoraConRelacionYAbono();

    $rolCajera = Role::firstOrCreate(['name' => 'Cajera']);
    $cajera = User::factory()->create(['role_id' => $rolCajera->id, 'sucursal_id' => $distribuidora->sucursal_id, 'is_active' => true]);

    Sanctum::actingAs($usuario);
    $this->post("/api/v1/conciliaciones/{$abono->id}/queja", [
        'motivo' => 'Yo pagué 2000, no 1500.',
        'evidencia' => UploadedFile::fake()->image('transferencia.jpg'),
    ])->assertStatus(200);

    Sanctum::actingAs($cajera);

    $this->getJson('/api/v1/conciliaciones')
        ->assertStatus(200)
        ->assertJsonPath('data.data.0.queja.motivo', 'Yo pagué 2000, no 1500.')
        ->assertJsonPath('data.data.0.queja.reportado_por', $usuario->name)
        ->assertJsonFragment(['evidencia_url' => AbonoConciliacion::find($abono->id)->queja_evidencia_url]);
});
