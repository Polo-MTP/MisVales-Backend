<?php

declare(strict_types=1);

use App\Models\ConfiguracionFechas;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * dia_corte y dia_corte_2 son los dos días de corte quincenales y siempre van en pareja: no
 * tiene sentido configurar uno sin el otro (dejaría el corte a medias), ni que sean el mismo
 * día (eso en la práctica es un solo corte al mes, no dos).
 */
function crearGerenteGeneralQuincena(): User
{
    $role = Role::firstOrCreate(['name' => 'Gerente General']);

    return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
}

it('el Gerente General puede configurar los dos días de corte quincenales', function (): void {
    Sanctum::actingAs(crearGerenteGeneralQuincena());

    $response = $this->postJson('/api/v1/configuraciones/fechas', [
        'sucursal_id' => null,
        'dia_corte' => 10,
        'dia_corte_2' => 25,
        'dia_limite_pago' => 27,
        'dias_pago_anticipado' => 3,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.dia_corte', 10)
        ->assertJsonPath('data.dia_corte_2', 25);
});

it('rechaza configurar dia_corte sin dia_corte_2', function (): void {
    Sanctum::actingAs(crearGerenteGeneralQuincena());

    $response = $this->postJson('/api/v1/configuraciones/fechas', [
        'sucursal_id' => null,
        'dia_corte' => 10,
        'dia_limite_pago' => 27,
        'dias_pago_anticipado' => 3,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['dia_corte_2']);
});

it('rechaza configurar dia_corte_2 sin dia_corte', function (): void {
    Sanctum::actingAs(crearGerenteGeneralQuincena());

    $response = $this->postJson('/api/v1/configuraciones/fechas', [
        'sucursal_id' => null,
        'dia_corte_2' => 25,
        'dia_limite_pago' => 27,
        'dias_pago_anticipado' => 3,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['dia_corte']);
});

it('rechaza que los dos días de corte sean el mismo -- eso dejaría un solo corte al mes', function (): void {
    Sanctum::actingAs(crearGerenteGeneralQuincena());

    $response = $this->postJson('/api/v1/configuraciones/fechas', [
        'sucursal_id' => null,
        'dia_corte' => 15,
        'dia_corte_2' => 15,
        'dia_limite_pago' => 16,
        'dias_pago_anticipado' => 3,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['dia_corte', 'dia_corte_2']);
});

it('un día mayor a los días reales del mes se capa al último día calendario', function (): void {
    Sanctum::actingAs(crearGerenteGeneralQuincena());

    $this->postJson('/api/v1/configuraciones/fechas', [
        'sucursal_id' => null,
        'dia_corte' => 15,
        'dia_corte_2' => 31,
        'dia_limite_pago' => 16,
        'dias_pago_anticipado' => 3,
    ])->assertStatus(201);

    $config = ConfiguracionFechas::query()->whereNull('vigente_hasta')->whereNull('sucursal_id')->first();

    expect($config)->not->toBeNull()
        ->and($config->dia_corte)->toBe(15)
        ->and($config->dia_corte_2)->toBe(31);
});
