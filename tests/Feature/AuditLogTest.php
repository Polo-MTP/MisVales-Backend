<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AltaProveedor\SolicitudProveedorService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('un intento de login fallido no genera ruido en audit_log (User no está en el observer genérico)', function (): void {
    $role = Role::query()->where('name', 'Cajera')->firstOrFail();
    $user = User::factory()->create(['password' => bcrypt('Passw0rd1'), 'role_id' => $role->id]);

    // El propio User::factory()->create() ya deja UNA fila (User.creado, vía el
    // listener dedicado de creación) — ese es el rastro que sí queremos conservar.
    $auditCountTrasCrear = AuditLog::where('resource', 'User#'.$user->id)->count();
    expect($auditCountTrasCrear)->toBe(1);

    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'incorrecta', 'recaptcha' => 'bypass-recaptcha'])->assertStatus(401);
    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'incorrecta', 'recaptcha' => 'bypass-recaptcha'])->assertStatus(401);

    // failed_attempts sube y el user se guarda en cada intento — si User estuviera en el
    // observer genérico (created/updated/deleted), esto habría agregado dos filas más
    // "User.actualizado" en audit_log. El conteo debe quedarse igual que antes de loguear.
    expect($user->fresh()->failed_attempts)->toBe(2)
        ->and(AuditLog::where('resource', 'User#'.$user->id)->count())->toBe($auditCountTrasCrear);
});

/**
 * DatosPersonales/Direccion no estaban en el observer genérico -- cuando el Verificador
 * corregía el nombre/CURP/dirección de una solicitud (SolicitudProveedorService::
 * verificarSolicitud()), ese cambio quedaba SIN rastro en audit_log: el 'updated' de
 * SolicitudProveedor solo trae sus propios campos (estado, cumple...), nunca los de estas
 * dos tablas relacionadas. Confirma que ahora sí quedan registrados, con el módulo agrupador
 * "Datos Personales" ya que los usan varios flujos (alta de proveedor, clientes, personal).
 */
it('la corrección del Verificador sobre DatosPersonales/Direccion sí queda en audit_log', function (): void {
    $this->seed(RoleSeeder::class);

    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $coordinador = User::factory()->create(['role_id' => Role::where('name', 'Coordinador')->firstOrFail()->id, 'sucursal_id' => $sucursal->id]);
    $verificador = User::factory()->create(['role_id' => Role::where('name', 'Verificador')->firstOrFail()->id, 'sucursal_id' => $sucursal->id]);

    $service = app(SolicitudProveedorService::class);
    $solicitud = $service->crearSolicitud([
        'calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000',
        'estado' => 'Coahuila', 'ciudad' => 'Torreón',
        'nombre' => 'Juan', 'apellido_paterno' => 'Perez', 'curp' => 'PEGJ850101HDGRZN05', 'rfc' => 'PEGJ850101ABC',
    ], $coordinador);

    $service->verificarSolicitud($solicitud, [
        'cumple' => true,
        'comentario_verificador' => 'Corregido en visita.',
        'datos_personales' => ['apellido_materno' => 'Gomez'],
        'direccion' => ['calle' => 'Av. Revolucion 456'],
    ], $verificador);

    expect(AuditLog::where('modulo', 'Datos Personales')->where('action', 'DatosPersonales.actualizado')->exists())->toBeTrue()
        ->and(AuditLog::where('modulo', 'Datos Personales')->where('action', 'Direccion.actualizado')->exists())->toBeTrue();
});
