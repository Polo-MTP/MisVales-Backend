<?php

declare(strict_types=1);

use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Evidencia;
use App\Models\Role;
use App\Models\SolicitudProveedor;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Mismo criterio que EvidenciaController::store(): 's3' solo si el bucket está configurado,
 * si no 'public'. Fijar 's3' a fuerzas rompía este test en cualquier entorno sin bucket
 * (como local) -- el código real ahí sube a 'public', así que Storage::fake('s3') fake-eaba
 * un disco que la petición real nunca tocaba y el assertExists() fallaba.
 */
function discoEvidenciasReal(): string
{
    return (config('filesystems.default') === 's3' || ! empty(config('filesystems.disks.s3.bucket')))
        ? 's3'
        : 'public';
}

it('un coordinador sube el archivo real de una evidencia y queda con URL pública', function (): void {
    $disco = discoEvidenciasReal();
    Storage::fake($disco);

    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $role = Role::create(['name' => 'Coordinador']);
    $coordinador = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Prospecto', 'apellido_paterno' => 'Nuevo', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);

    $solicitud = SolicitudProveedor::create([
        'datos_id' => $datos->id, 'sucursal_id' => $sucursal->id, 'coordinador_id' => $coordinador->id,
        'estado' => 'pendiente_verificacion', 'rfc' => 'ABCD010101ABC',
    ]);

    Sanctum::actingAs($coordinador);

    $archivo = UploadedFile::fake()->image('fachada.jpg');

    $response = $this->postJson("/api/v1/alta-proveedor/solicitudes/{$solicitud->id}/evidencias", [
        'archivo' => $archivo,
        'tipo_documento' => 'foto_fachada',
    ]);

    $response->assertStatus(201)
        ->assertJson(['success' => true]);

    $evidencia = Evidencia::first();
    expect($evidencia)->not->toBeNull()
        ->and($evidencia->solicitud_id)->toBe($solicitud->id)
        ->and($evidencia->tipo_documento)->toBe('foto_fachada')
        ->and($evidencia->url_archivo)->toContain('/evidencias/');

    $rutaRelativa = preg_replace('#^storage/#', '', ltrim((string) parse_url((string) $evidencia->url_archivo, PHP_URL_PATH), '/'));
    Storage::disk($disco)->assertExists($rutaRelativa);
});

it('un coordinador no puede subir evidencia a una solicitud de otra sucursal', function (): void {
    Storage::fake(discoEvidenciasReal());

    $sucursalA = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-A-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $sucursalB = Sucursal::create(['nombre' => 'Otra', 'codigo' => 'SUC-B-'.uniqid(), 'is_active' => true]);
    $role = Role::firstOrCreate(['name' => 'Coordinador']);
    $coordinadorDeA = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursalA->id, 'is_active' => true]);

    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Prospecto', 'apellido_paterno' => 'Nuevo', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);

    // La solicitud pertenece a la sucursal B -- el coordinador que intenta subir es de la A.
    $solicitud = SolicitudProveedor::create([
        'datos_id' => $datos->id, 'sucursal_id' => $sucursalB->id, 'coordinador_id' => null,
        'estado' => 'pendiente_verificacion', 'rfc' => 'WXYZ010101ABC',
    ]);

    Sanctum::actingAs($coordinadorDeA);

    $this->postJson("/api/v1/alta-proveedor/solicitudes/{$solicitud->id}/evidencias", [
        'archivo' => UploadedFile::fake()->image('fachada.jpg'),
        'tipo_documento' => 'foto_fachada',
    ])->assertStatus(403);

    expect(Evidencia::count())->toBe(0);
});
