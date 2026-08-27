<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Evidencia;
use App\Models\Role;
use App\Models\SolicitudProveedor;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AltaProveedor\SolicitudProveedorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function crearCoordinadorParaSolicitud(): User
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $role = Role::firstOrCreate(['name' => 'Coordinador']);

    return User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);
}

/** @return array<string, mixed> */
function datosSolicitudValidos(): array
{
    return [
        'calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000',
        'estado' => 'Coahuila', 'ciudad' => 'Torreón',
        'nombre' => 'Juan', 'apellido_paterno' => 'Pérez', 'curp' => 'CURP'.uniqid(), 'rfc' => 'RFC'.uniqid(),
    ];
}

it('crea la dirección, los datos personales y la solicitud en una sola operación', function (): void {
    $coordinador = crearCoordinadorParaSolicitud();

    $solicitud = app(SolicitudProveedorService::class)->crearSolicitud(datosSolicitudValidos(), $coordinador);

    expect($solicitud->id)->toBeGreaterThan(0)
        ->and(Direccion::count())->toBe(1)
        ->and(DatosPersonales::count())->toBe(1)
        ->and(SolicitudProveedor::count())->toBe(1);
});

/**
 * Si algo truena a la mitad de crearSolicitud() -- una conexión a la BD que se cae, o
 * cualquier otra falla -- no debe quedar NADA a medio crear: ni la dirección, ni los datos
 * personales, ni la solicitud, aunque esas escrituras ya hubieran "corrido" antes de que
 * tronara la última. Todo o nada, nunca una solicitud a medias que reaparezca sola cuando
 * la BD "vuelva a jalar".
 *
 * Se simula la caída disparando una excepción justo en el último paso (crear la evidencia,
 * que ya viene después de dirección/datos personales/solicitud/log en el código real) --
 * desde el punto de vista de la transacción, una conexión perdida y cualquier otra excepción
 * a mitad de camino se comportan igual: ninguna de las dos deja nada comprometido.
 */
it('si algo truena a la mitad (ej. se cae la conexión a la BD), no queda nada creado -- todo o nada', function (): void {
    $coordinador = crearCoordinadorParaSolicitud();

    Evidencia::creating(function (): void {
        throw new RuntimeException('Conexión a la base de datos perdida (simulado).');
    });

    $data = datosSolicitudValidos();
    $data['evidencias'] = [['tipo_documento' => 'ine', 'url_archivo' => 'http://ejemplo.com/foto.jpg']];

    expect(fn () => app(SolicitudProveedorService::class)->crearSolicitud($data, $coordinador))
        ->toThrow(RuntimeException::class);

    expect(Direccion::count())->toBe(0)
        ->and(DatosPersonales::count())->toBe(0)
        ->and(SolicitudProveedor::count())->toBe(0)
        ->and(Evidencia::count())->toBe(0);
});

it('guarda la categoría elegida por el Coordinador al capturar la solicitud', function (): void {
    $coordinador = crearCoordinadorParaSolicitud();
    $categoria = CategoriaDistribuidora::create(['nombre' => 'ORO', 'porcentaje_comision' => 4, 'activo' => true]);

    $data = datosSolicitudValidos();
    $data['categoria_id'] = $categoria->id;

    $solicitud = app(SolicitudProveedorService::class)->crearSolicitud($data, $coordinador);

    expect($solicitud->categoria_id)->toBe($categoria->id)
        ->and($solicitud->categoria->nombre)->toBe('ORO');
});

it('reintentar después de una falla crea la solicitud normalmente -- no queda bloqueada por el intento fallido', function (): void {
    $coordinador = crearCoordinadorParaSolicitud();

    Evidencia::creating(function (): void {
        throw new RuntimeException('Conexión a la base de datos perdida (simulado).');
    });

    $data = datosSolicitudValidos();
    $data['evidencias'] = [['tipo_documento' => 'ine', 'url_archivo' => 'http://ejemplo.com/foto.jpg']];

    expect(fn () => app(SolicitudProveedorService::class)->crearSolicitud($data, $coordinador))->toThrow(RuntimeException::class);
    expect(SolicitudProveedor::count())->toBe(0);

    // "La BD vuelve a jalar": el siguiente intento (sin el listener que simula la caída, y
    // con CURP/RFC nuevos porque los anteriores nunca llegaron a guardarse) debe funcionar
    // normal -- no debe crear un duplicado ni heredar nada del intento que falló.
    Evidencia::flushEventListeners();

    $data2 = datosSolicitudValidos();
    $data2['curp'] = 'CURP'.uniqid();
    $data2['rfc'] = 'RFC'.uniqid();

    $solicitud = app(SolicitudProveedorService::class)->crearSolicitud($data2, $coordinador);

    expect($solicitud->id)->toBeGreaterThan(0)
        ->and(SolicitudProveedor::count())->toBe(1);
});
