<?php

declare(strict_types=1);

use App\Models\LogNuevoProveedor;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AltaProveedor\SolicitudProveedorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function crearSolicitudConFechaNacimiento(): array
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $coordinador = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Coordinador'])->id, 'sucursal_id' => $sucursal->id]);
    $verificador = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Verificador'])->id, 'sucursal_id' => $sucursal->id]);

    $service = app(SolicitudProveedorService::class);
    $solicitud = $service->crearSolicitud([
        'calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000',
        'estado' => 'Coahuila', 'ciudad' => 'Torreón',
        'nombre' => 'Juan', 'apellido_paterno' => 'Perez', 'curp' => 'PEGJ850101HDGRZN05', 'rfc' => 'PEGJ850101ABC',
        'fecha_nacimiento' => '1990-05-15',
        'lugar_nacimiento' => 'Torreón',
    ], $coordinador);

    return [$service, $solicitud, $verificador];
}

/**
 * 'fecha_nacimiento' está casteado a Carbon en DatosPersonales -- comparar el objeto tal cual
 * contra el string plano que manda el Verificador siempre daría "cambió" (tipos distintos),
 * aunque reenviara exactamente la misma fecha ya guardada. Confirma que reenviar el mismo valor
 * no genera un registro de auditoría falso ni dispara una actualización innecesaria.
 */
it('reenviar la misma fecha de nacimiento no genera un cambio falso en la auditoría', function (): void {
    [$service, $solicitud, $verificador] = crearSolicitudConFechaNacimiento();

    $service->verificarSolicitud($solicitud, [
        'cumple' => true,
        'comentario_verificador' => 'Todo en orden.',
        'datos_personales' => ['fecha_nacimiento' => '1990-05-15'],
    ], $verificador);

    expect(LogNuevoProveedor::where('campo', 'fecha_nacimiento')->exists())->toBeFalse();
});

/**
 * Cuando el Verificador sí corrige la fecha de nacimiento, el log debe guardar fechas limpias
 * (YYYY-MM-DD) en 'antes'/'después', no el datetime completo que produce el cast a Carbon.
 */
it('corregir la fecha de nacimiento sí queda registrado con fechas limpias', function (): void {
    [$service, $solicitud, $verificador] = crearSolicitudConFechaNacimiento();

    $service->verificarSolicitud($solicitud, [
        'cumple' => true,
        'comentario_verificador' => 'Corrección de fecha.',
        'datos_personales' => ['fecha_nacimiento' => '1991-06-20'],
    ], $verificador);

    $log = LogNuevoProveedor::where('campo', 'fecha_nacimiento')->first();

    expect($log)->not->toBeNull()
        ->and($log->valor_anterior)->toBe('1990-05-15')
        ->and($log->valor_nuevo)->toBe('1991-06-20')
        ->and($solicitud->fresh()->datosPersonales->fecha_nacimiento->toDateString())->toBe('1991-06-20');
});
