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

it('un coordinador sube el archivo real de una evidencia y queda con URL pública', function (): void {
    Storage::fake('public');

    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $role = Role::create(['name' => 'Coordinador']);
    $coordinador = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Prospecto', 'apellido_paterno' => 'Nuevo', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);

    $solicitud = SolicitudProveedor::create([
        'datos_id' => $datos->id, 'sucursal_id' => $sucursal->id, 'coordinador_id' => $coordinador->id,
        'estado' => 'pendiente_verificacion', 'razon_social' => 'Negocio Test', 'rfc' => 'ABCD010101ABC',
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
        ->and($evidencia->url_archivo)->toContain('/storage/evidencias/');

    $rutaRelativa = preg_replace('#^.*/storage/#', '', (string) $evidencia->url_archivo);
    Storage::disk('public')->assertExists($rutaRelativa);
});
