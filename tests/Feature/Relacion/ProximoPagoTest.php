<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function crearDistribuidoraProximoPago(): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearValeProximoPago(Distribuidora $distribuidora, float $monto = 5000): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => $monto, 'quincenas' => 4,
        'tipo' => 'vale-digital', 'estado' => 'autorizado', 'fecha_autorizacion' => now(),
    ]);
}

it('calcula la próxima fecha de corte de este mes si el día de corte todavía no pasa', function (): void {
    $this->travelTo('2026-02-10');
    $distribuidora = crearDistribuidoraProximoPago();
    crearValeProximoPago($distribuidora, 5000);
    Sanctum::actingAs($distribuidora->usuario);

    $response = $this->getJson('/api/v1/relaciones/proximo-pago');

    $response->assertStatus(200)
        ->assertJsonPath('data.fecha_corte', '2026-02-15')
        ->assertJsonPath('data.fecha_limite_pago', '2026-02-16')
        ->assertJsonPath('data.referencia_pago', sprintf('%09d%09d', $distribuidora->id, 20260215));
});

it('salta al corte del siguiente mes si el día de corte de este mes ya pasó', function (): void {
    $this->travelTo('2026-02-20');
    $distribuidora = crearDistribuidoraProximoPago();
    crearValeProximoPago($distribuidora, 5000);
    Sanctum::actingAs($distribuidora->usuario);

    $response = $this->getJson('/api/v1/relaciones/proximo-pago');

    $response->assertStatus(200)->assertJsonPath('data.fecha_corte', '2026-03-15');
});

it('el mismo día del corte cuenta como "todavía no pasó" -- no salta al mes siguiente', function (): void {
    $this->travelTo('2026-02-15');
    $distribuidora = crearDistribuidoraProximoPago();
    crearValeProximoPago($distribuidora, 5000);
    Sanctum::actingAs($distribuidora->usuario);

    $response = $this->getJson('/api/v1/relaciones/proximo-pago');

    $response->assertStatus(200)->assertJsonPath('data.fecha_corte', '2026-02-15');
});

it('suma el pago estimado de todos los vales activos de la distribuidora', function (): void {
    $this->travelTo('2026-02-10');
    $distribuidora = crearDistribuidoraProximoPago();
    crearValeProximoPago($distribuidora, 5000);
    crearValeProximoPago($distribuidora, 5000);
    Sanctum::actingAs($distribuidora->usuario);

    $response = $this->getJson('/api/v1/relaciones/proximo-pago');

    $response->assertStatus(200);
    // Cada vale: capital=1250, comisión=125, interés=250, seguro=0, categoría=75 -> 1550.
    // Dos vales -> 3100.
    expect((float) $response->json('data.monto_estimado'))->toBe(3100.0)
        ->and($response->json('data.vales'))->toHaveCount(2);
});

it('el staff puede consultar el próximo pago de una distribuidora específica', function (): void {
    $this->travelTo('2026-02-10');
    $distribuidora = crearDistribuidoraProximoPago();
    crearValeProximoPago($distribuidora, 5000);

    $role = Role::firstOrCreate(['name' => 'Cajera']);
    Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

    $response = $this->getJson('/api/v1/relaciones/proximo-pago?distribuidora_id='.$distribuidora->id);

    $response->assertStatus(200)->assertJsonPath('data.fecha_corte', '2026-02-15');
});

it('sin vales autorizados/parciales/vencidos no regresa referencia -- una solicitud aún se puede cancelar', function (): void {
    $this->travelTo('2026-02-10');
    $distribuidora = crearDistribuidoraProximoPago();
    // Vale en 'solicitado', no 'autorizado': todavía no hay nada firme detrás de un posible corte.
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);
    Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => 5000, 'quincenas' => 4,
        'tipo' => 'vale-digital', 'estado' => 'solicitado',
    ]);
    Sanctum::actingAs($distribuidora->usuario);

    $response = $this->getJson('/api/v1/relaciones/proximo-pago');

    $response->assertStatus(200)
        ->assertJsonPath('data.referencia_pago', null)
        ->assertJsonPath('data.monto_estimado', 0)
        ->assertJsonPath('data.vales', []);
});
