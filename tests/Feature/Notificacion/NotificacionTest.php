<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Notificacion;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearDistribuidoraObservada(Sucursal $sucursal): Distribuidora
{
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $roleDistribuidora = Role::firstOrCreate(['name' => 'Distribuidora']);
    $usuario = User::factory()->create(['role_id' => $roleDistribuidora->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $usuario->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

it('crear una distribuidora genera una notificación con la sucursal resuelta', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Sucursal', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $distribuidora = crearDistribuidoraObservada($sucursal);

    $notificacion = Notificacion::where('recurso', 'Distribuidora#'.$distribuidora->id)->first();

    expect($notificacion)->not->toBeNull()
        ->and($notificacion->accion)->toBe('Distribuidora.creado')
        ->and($notificacion->sucursal_id)->toBe($sucursal->id);
});

/**
 * AuditLogObserver está registrado sobre catálogos/configuración (Sucursal,
 * CategoriaDistribuidora, Producto, etc. -- ver AppServiceProvider::configureAuditLog()) para
 * que la bitácora forense (AuditLog) los cubra, pero esos altas NO deben generar Notificacion:
 * un Gerente de Sucursal no necesita enterarse de que alguien dio de alta una categoría o una
 * sucursal nueva, y antes de este fix sí se generaba, inflando el feed (ver
 * AuditLogObserver::MODELOS_CON_NOTIFICACION).
 */
it('crear un catálogo (Sucursal/CategoriaDistribuidora) deja rastro en AuditLog pero no genera Notificacion', function (): void {
    $sucursal = Sucursal::create(['nombre' => 'Solo Catálogo', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'BRONCE-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);

    expect(AuditLog::where('resource', 'Sucursal#'.$sucursal->id)->exists())->toBeTrue()
        ->and(Notificacion::where('recurso', 'Sucursal#'.$sucursal->id)->exists())->toBeFalse()
        ->and(AuditLog::where('resource', 'CategoriaDistribuidora#'.$categoria->id)->exists())->toBeTrue()
        ->and(Notificacion::where('recurso', 'CategoriaDistribuidora#'.$categoria->id)->exists())->toBeFalse();
});

it('el gerente de sucursal solo ve por HTTP las notificaciones de su propia sucursal', function (): void {
    $sucursalA = Sucursal::create(['nombre' => 'Sucursal A', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $sucursalB = Sucursal::create(['nombre' => 'Sucursal B', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    crearDistribuidoraObservada($sucursalA);
    crearDistribuidoraObservada($sucursalB);

    $roleGerente = Role::firstOrCreate(['name' => 'Gerente de Sucursal']);
    $gerenteA = User::factory()->create(['role_id' => $roleGerente->id, 'sucursal_id' => $sucursalA->id, 'is_active' => true]);

    Sanctum::actingAs($gerenteA);

    $this->getJson('/api/v1/notificaciones')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.sucursal.id', $sucursalA->id);
});

it('el gerente general y el administrador ven por HTTP todas las notificaciones sin filtrar', function (): void {
    $sucursalA = Sucursal::create(['nombre' => 'Sucursal A', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    $sucursalB = Sucursal::create(['nombre' => 'Sucursal B', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => false, 'is_active' => true]);
    crearDistribuidoraObservada($sucursalA);
    crearDistribuidoraObservada($sucursalB);

    $roleGerenteGeneral = Role::firstOrCreate(['name' => 'Gerente General']);
    $gerenteGeneral = User::factory()->create(['role_id' => $roleGerenteGeneral->id, 'is_active' => true]);

    Sanctum::actingAs($gerenteGeneral);
    $this->getJson('/api/v1/notificaciones')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data.data');

    $roleAdmin = Role::firstOrCreate(['name' => 'Administrador']);
    $admin = User::factory()->create(['role_id' => $roleAdmin->id, 'is_active' => true]);

    Sanctum::actingAs($admin);
    $this->getJson('/api/v1/notificaciones')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data.data');
});
