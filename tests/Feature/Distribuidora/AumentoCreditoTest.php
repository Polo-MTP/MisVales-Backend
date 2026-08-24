<?php

declare(strict_types=1);

use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Distribuidora\SolicitudAumentoCreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{gerente: User, usuarioDistribuidora: User, distribuidora: Distribuidora}
 */
function crearDistribuidoraParaAumento(): array
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $roleGerente = Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    $roleDistribuidora = Role::firstOrCreate(['name' => 'Distribuidora']);

    $gerente = User::factory()->create(['role_id' => $roleGerente->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
    $usuarioDistribuidora = User::factory()->create(['role_id' => $roleDistribuidora->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $distribuidora = Distribuidora::create([
        'usuario_id' => $usuarioDistribuidora->id, 'numero_distribuidora' => 'DIST-'.uniqid(),
        'limite_credito' => 10000, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    return compact('gerente', 'usuarioDistribuidora', 'distribuidora');
}

it('flujo completo por HTTP: solicitar y aprobar un aumento fija el limite_credito al monto otorgado (no lo suma al anterior)', function (): void {
    ['gerente' => $gerente, 'usuarioDistribuidora' => $usuarioDistribuidora, 'distribuidora' => $distribuidora] = crearDistribuidoraParaAumento();

    // Límite actual: 10000. Pide subir a 15000 ("de 10000 a 15000").
    Sanctum::actingAs($usuarioDistribuidora);
    $response = $this->postJson("/api/v1/distribuidoras/{$distribuidora->id}/aumento-credito", [
        'monto_solicitado' => 15000,
        'motivo' => 'Buen historial de pagos',
    ]);
    $response->assertStatus(201)->assertJsonPath('data.estado', 'pendiente');
    $solicitudId = $response->json('data.id');

    // El gerente negocia y solo otorga 13000 (menos de lo pedido, nunca más) -- ese 13000 es
    // el nuevo límite total, no algo que se sume a los 10000 que ya tenía.
    Sanctum::actingAs($gerente);
    $response = $this->putJson("/api/v1/distribuidoras/aumento-credito/{$solicitudId}/decidir", [
        'decision' => 'aprobada',
        'monto_otorgado' => 13000,
    ]);
    $response->assertStatus(200)
        ->assertJsonPath('data.estado', 'aprobada')
        ->assertJsonPath('data.monto_otorgado', 13000);

    expect((float) $distribuidora->fresh()->limite_credito)->toBe(13000.0);
});

it('el gerente no puede otorgar más de lo solicitado', function (): void {
    ['gerente' => $gerente, 'usuarioDistribuidora' => $usuarioDistribuidora, 'distribuidora' => $distribuidora] = crearDistribuidoraParaAumento();

    $service = app(SolicitudAumentoCreditoService::class);
    $solicitud = $service->solicitar($distribuidora, $usuarioDistribuidora, 15000, 'motivo');

    expect(fn () => $service->decidir($solicitud, 'aprobada', 20000, null, $gerente))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, 'El monto otorgado no puede ser mayor al monto solicitado.');
});

it('no se puede solicitar un segundo aumento mientras hay uno pendiente', function (): void {
    ['usuarioDistribuidora' => $usuarioDistribuidora, 'distribuidora' => $distribuidora] = crearDistribuidoraParaAumento();

    $service = app(SolicitudAumentoCreditoService::class);
    $service->solicitar($distribuidora, $usuarioDistribuidora, 5000, 'motivo');

    expect(fn () => $service->solicitar($distribuidora, $usuarioDistribuidora, 2000, 'otro motivo'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, 'Esta distribuidora ya tiene una solicitud de aumento de crédito pendiente.');
});

it('una distribuidora no puede solicitar aumento de crédito para otra distribuidora', function (): void {
    ['distribuidora' => $distribuidora] = crearDistribuidoraParaAumento();
    ['usuarioDistribuidora' => $otroUsuario] = crearDistribuidoraParaAumento();

    expect(fn () => app(SolicitudAumentoCreditoService::class)->solicitar($distribuidora, $otroUsuario, 1000, 'motivo'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class, 'No tienes autoridad para solicitar un aumento de crédito para esta distribuidora.');
});
