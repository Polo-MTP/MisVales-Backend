<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\SeguroTabla;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use App\Services\Relacion\RelacionCalculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Antes, el JSON de una Relación solo traía distribuidora_id (un número), sin nombre ni
 * número de distribuidora — el frontend no tenía forma de mostrar a quién pertenecía la
 * relación en Conciliación Bancaria / /gerente/relaciones sin este dato.
 */
it('el JSON de una relación incluye el número y la razón social de la distribuidora, no solo su id', function (): void {
    $admin = User::factory()->create();
    foreach ([
        'comision_base_pct' => '10',
        'interes_pct_quincena' => '5',
        'multa_no_pago' => '300',
        'puntos_divisor' => '1200',
        'puntos_multiplicador' => '3',
        'puntos_penalizacion_pct' => '20',
        'regla_50_pct' => '50',
    ] as $clave => $valor) {
        Configuracion::create(['clave' => $clave, 'valor' => $valor, 'tipo_dato' => 'decimal', 'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id]);
    }
    SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);

    $sucursal = Sucursal::create(['nombre' => 'Matriz', 'codigo' => 'SUC-001', 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA', 'porcentaje_comision' => 6, 'activo' => true]);
    $roleDist = Role::create(['name' => 'Distribuidora', 'factor_count' => 1]);
    $userDist = User::factory()->create(['role_id' => $roleDist->id, 'sucursal_id' => $sucursal->id]);
    $distribuidora = Distribuidora::create([
        'usuario_id' => $userDist->id, 'numero_distribuidora' => 'DIST-90099', 'nombre' => 'Distribuidora de Prueba SA de CV',
        'limite_credito' => 20000, 'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);
    Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => 15000, 'quincenas' => 8,
        'tipo' => 'vale-digital', 'estado' => 'autorizado', 'fecha_autorizacion' => '2026-02-01',
    ]);

    app(RelacionCalculoService::class)->generarParaDistribuidora($distribuidora, '2026-02-15');

    $roleGerente = Role::firstOrCreate(['name' => 'Gerente General'], ['factor_count' => 1]);
    $gerente = User::factory()->create(['role_id' => $roleGerente->id, 'is_active' => true]);
    Sanctum::actingAs($gerente);

    $this->getJson('/api/v1/relaciones')
        ->assertStatus(200)
        ->assertJsonPath('data.data.0.distribuidora.numero_distribuidora', 'DIST-90099')
        ->assertJsonPath('data.data.0.distribuidora.nombre', 'Distribuidora de Prueba SA de CV');
});
