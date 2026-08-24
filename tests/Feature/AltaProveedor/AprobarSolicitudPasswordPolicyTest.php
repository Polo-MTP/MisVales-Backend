<?php

declare(strict_types=1);

use App\Mail\PersonalCredencialesMail;
use App\Models\AuditLog;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Role;
use App\Models\SolicitudProveedor;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
});

/**
 * AprobarSolicitudProveedorRequest solo exigía password 'min:8' — cualquier cosa de 8+
 * caracteres pasaba, incluida una palabra de diccionario en minúsculas. Ahora usa la
 * misma política central (Password::defaults(), ver AppServiceProvider) que el reset.
 */
function crearSolicitudProveedorPendiente(Sucursal $sucursal): SolicitudProveedor
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Nuevo', 'apellido_paterno' => 'Proveedor', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);

    return SolicitudProveedor::create([
        'datos_id' => $datos->id,
        'sucursal_id' => $sucursal->id,
        'rfc' => 'RFC'.uniqid(),
        'estado' => 'verificado',
        'decision_gerente' => 'pendiente',
    ]);
}

function crearGerenteGeneralActivo(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente General'], ['factor_count' => 1]);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

it('rechaza aprobar una solicitud con un password débil (sin mayúscula/número)', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $solicitud = crearSolicitudProveedorPendiente($sucursal);
    $gerente = crearGerenteGeneralActivo();
    Sanctum::actingAs($gerente);

    $this->postJson("/api/v1/alta-proveedor/solicitudes/{$solicitud->id}/aprobar", [
        'decision' => 'aprobado',
        'limite_credito_asignado' => 20000,
        'email' => 'nuevo.proveedor@correo.com',
        'password' => 'contrasena',
    ])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonValidationErrors(['password']);

    expect(User::where('email', 'nuevo.proveedor@correo.com')->exists())->toBeFalse();
});

it('acepta un password fuerte, crea la cuenta y deja un solo rastro de auditoría del alta', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $solicitud = crearSolicitudProveedorPendiente($sucursal);
    $gerente = crearGerenteGeneralActivo();
    Sanctum::actingAs($gerente);

    $this->postJson("/api/v1/alta-proveedor/solicitudes/{$solicitud->id}/aprobar", [
        'decision' => 'aprobado',
        'limite_credito_asignado' => 20000,
        'email' => 'nuevo.proveedor@correo.com',
        'password' => 'Passw0rd1',
    ])->assertStatus(200);

    $nuevoUsuario = User::where('email', 'nuevo.proveedor@correo.com')->first();
    expect($nuevoUsuario)->not->toBeNull()
        // email_verified_at no está en $fillable de User -- mandarlo dentro de un create()
        // se ignora en silencio. Regresión: la cuenta debe quedar realmente verificada.
        ->and($nuevoUsuario->email_verified_at)->not->toBeNull();

    expect(AuditLog::where('action', 'User.registrado')->where('resource', 'User#'.$nuevoUsuario->id)->count())->toBe(1);

    $auditDistribuidora = AuditLog::where('action', 'Distribuidora.creado')->latest()->first();
    expect($auditDistribuidora)->not->toBeNull()
        ->and($auditDistribuidora->ip_address)->not->toBeNull();
});

it('le envía la contraseña por correo a la distribuidora aprobada -- si no, no tiene forma de saberla', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $solicitud = crearSolicitudProveedorPendiente($sucursal);
    $gerente = crearGerenteGeneralActivo();
    Sanctum::actingAs($gerente);

    $this->postJson("/api/v1/alta-proveedor/solicitudes/{$solicitud->id}/aprobar", [
        'decision' => 'aprobado',
        'limite_credito_asignado' => 20000,
        'email' => 'nuevo.proveedor@correo.com',
        'password' => 'Passw0rd1',
    ])->assertStatus(200);

    Mail::assertSent(PersonalCredencialesMail::class, fn ($mail) => $mail->hasTo('nuevo.proveedor@correo.com') && $mail->password === 'Passw0rd1');
});
