<?php

declare(strict_types=1);

use App\Mail\PersonalCredencialesMail;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AltaProveedor\SolicitudProveedorService;
use Illuminate\Support\Facades\Mail;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
});

/**
 * @return array{0: SolicitudProveedorService, 1: \App\Models\SolicitudProveedor, 2: User, 3: User}
 */
function crearSolicitudParaTransiciones(): array
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $coordinador = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Coordinador'])->id, 'sucursal_id' => $sucursal->id]);
    $verificador = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Verificador'])->id, 'sucursal_id' => $sucursal->id]);
    $gerente = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Gerente General'])->id, 'sucursal_id' => $sucursal->id]);

    $service = app(SolicitudProveedorService::class);
    $solicitud = $service->crearSolicitud([
        'calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000',
        'estado' => 'Coahuila', 'ciudad' => 'Torreón',
        'nombre' => 'Juan', 'apellido_paterno' => 'Perez', 'curp' => 'PEGJ850101HDGRZN05', 'rfc' => 'PEGJ850101ABC',
    ], $coordinador);

    return [$service, $solicitud, $verificador, $gerente];
}

it('no se puede aprobar una solicitud que nunca fue verificada', function (): void {
    [$service, $solicitud, , $gerente] = crearSolicitudParaTransiciones();

    expect($solicitud->estado)->toBe('pendiente_verificacion');

    expect(fn () => $service->aprobarORechazar($solicitud, [
        'decision' => 'aprobado',
        'limite_credito_asignado' => 20000,
        'email' => 'nueva.distribuidora@correo.com',
    ], $gerente))->toThrow(DomainException::class);

    Mail::assertNothingSent();
    expect(User::where('email', 'nueva.distribuidora@correo.com')->exists())->toBeFalse();
});

it('no se puede volver a verificar una solicitud que el verificador ya dictaminó', function (): void {
    [$service, $solicitud, $verificador] = crearSolicitudParaTransiciones();

    $service->verificarSolicitud($solicitud, [
        'cumple' => true,
        'comentario_verificador' => 'Todo en orden.',
    ], $verificador);

    expect(fn () => $service->verificarSolicitud($solicitud->fresh(), [
        'cumple' => false,
        'comentario_verificador' => 'Intento de sobreescribir el dictamen.',
    ], $verificador))->toThrow(DomainException::class);

    expect($solicitud->fresh()->estado)->toBe('verificado')
        ->and($solicitud->fresh()->cumple)->toBeTrue();
});

it('no se puede aprobar una solicitud que el verificador rechazó', function (): void {
    [$service, $solicitud, $verificador, $gerente] = crearSolicitudParaTransiciones();

    $service->verificarSolicitud($solicitud, [
        'cumple' => false,
        'comentario_verificador' => 'No cumple los requisitos.',
    ], $verificador);

    expect(fn () => $service->aprobarORechazar($solicitud->fresh(), [
        'decision' => 'aprobado',
        'limite_credito_asignado' => 20000,
        'email' => 'no.deberia.crearse@correo.com',
    ], $gerente))->toThrow(DomainException::class);

    Mail::assertNothingSent();
    expect(User::where('email', 'no.deberia.crearse@correo.com')->exists())->toBeFalse();
});

it('no se puede reaprobar una solicitud que ya fue aprobada -- no manda un segundo correo ni intenta crear otra cuenta', function (): void {
    [$service, $solicitud, $verificador, $gerente] = crearSolicitudParaTransiciones();

    $service->verificarSolicitud($solicitud, [
        'cumple' => true,
        'comentario_verificador' => 'Todo en orden.',
    ], $verificador);

    $aprobada = $service->aprobarORechazar($solicitud->fresh(), [
        'decision' => 'aprobado',
        'limite_credito_asignado' => 20000,
        'email' => 'distribuidora.real@correo.com',
    ], $gerente);

    Mail::assertSent(PersonalCredencialesMail::class, 1);
    expect($aprobada->estado)->toBe('aprobado');

    expect(fn () => $service->aprobarORechazar($aprobada->fresh(), [
        'decision' => 'aprobado',
        'limite_credito_asignado' => 30000,
        'email' => 'otro.correo@correo.com',
    ], $gerente))->toThrow(DomainException::class);

    // Sigue habiendo un solo correo mandado en total (el de la primera aprobación real) y una
    // sola cuenta creada -- la segunda llamada nunca llegó a intentar nada.
    Mail::assertSent(PersonalCredencialesMail::class, 1);
    expect(User::where('email', 'otro.correo@correo.com')->exists())->toBeFalse();
});
