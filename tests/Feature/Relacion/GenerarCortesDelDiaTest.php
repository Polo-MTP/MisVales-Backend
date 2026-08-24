<?php

declare(strict_types=1);

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\ConfiguracionFechas;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\Role;
use App\Models\SeguroTabla;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use App\Services\Relacion\RelacionCalculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedConfiguracionBaseCortes(): void
{
    $admin = User::factory()->create();

    foreach (['comision_base_pct' => '10', 'interes_pct_quincena' => '5', 'multa_no_pago' => '300'] as $clave => $valor) {
        Configuracion::create([
            'clave' => $clave, 'valor' => $valor, 'tipo_dato' => 'decimal',
            'vigente_desde' => '2025-01-01', 'modificado_por' => $admin->id,
        ]);
    }
}

function crearDistribuidoraParaCorte(string $numero): Distribuidora
{
    $sucursal = Sucursal::create(['nombre' => 'Suc-'.$numero, 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $user = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    return Distribuidora::create([
        'usuario_id' => $user->id, 'numero_distribuidora' => $numero, 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);
}

function crearValeParaCorte(Distribuidora $distribuidora, float $monto, int $quincenas): Vale
{
    $direccion = Direccion::create(['calle' => 'Test', 'colonia' => 'Test', 'numero_ext' => '1', 'codigo_postal' => '00000', 'estado' => 'Coahuila', 'ciudad' => 'Torreón']);
    $datos = DatosPersonales::create(['nombre' => 'Cliente', 'apellido_paterno' => 'De Prueba', 'curp' => 'CUPD'.uniqid(), 'direccion_id' => $direccion->id]);
    $cliente = Cliente::create(['datos_id' => $datos->id, 'estado' => true]);

    return Vale::create([
        'distribuidora_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'monto' => $monto,
        'quincenas' => $quincenas, 'tipo' => 'vale-digital', 'estado' => 'autorizado', 'fecha_autorizacion' => now(),
    ]);
}

beforeEach(function (): void {
    seedConfiguracionBaseCortes();
    SeguroTabla::create(['monto_desde' => 0, 'monto_hasta' => null, 'seguro_monto' => 100, 'activo' => true]);
});

it('no genera nada si la fecha indicada no es día de corte (ni el 15 ni fin de mes)', function (): void {
    $distribuidora = crearDistribuidoraParaCorte('DIST-NOCORTE');
    crearValeParaCorte($distribuidora, 15000, 8);

    $resultado = app(RelacionCalculoService::class)->generarCortesDelDia('2026-02-20');

    expect($resultado['generadas'])->toBeEmpty()
        ->and($resultado['errores'])->toBeEmpty()
        ->and(Relacion::query()->count())->toBe(0);
});

it('genera el corte en el último día del mes, el segundo corte quincenal', function (): void {
    $distribuidora = crearDistribuidoraParaCorte('DIST-FINMES');
    crearValeParaCorte($distribuidora, 15000, 8);

    // Febrero 2026 no es bisiesto: el último día del mes es el 28, no el 15.
    $resultado = app(RelacionCalculoService::class)->generarCortesDelDia('2026-02-28');

    expect($resultado['generadas'])->toHaveCount(1)
        ->and($resultado['generadas'])->toHaveKey($distribuidora->id);
});

it('respeta los días de corte propios de la sucursal, no los globales por defecto', function (): void {
    $distribuidora = crearDistribuidoraParaCorte('DIST-SUC-PROPIA');
    crearValeParaCorte($distribuidora, 15000, 8);

    $admin = User::factory()->create();
    ConfiguracionFechas::create([
        'sucursal_id' => $distribuidora->sucursal_id,
        'dia_corte' => 10,
        'dia_corte_2' => 25,
        'dia_limite_pago' => 27,
        'dias_pago_anticipado' => 2,
        'vigente_desde' => '2025-01-01',
        'modificado_por' => $admin->id,
    ]);

    // El 15 es día de corte por defecto (global), pero esta sucursal tiene sus propios días
    // (10 y 25) -- no debe generar nada el 15.
    $resultadoDia15 = app(RelacionCalculoService::class)->generarCortesDelDia('2026-02-15');
    expect($resultadoDia15['generadas'])->toBeEmpty();

    $resultadoDia10 = app(RelacionCalculoService::class)->generarCortesDelDia('2026-02-10');
    expect($resultadoDia10['generadas'])->toHaveCount(1)
        ->and($resultadoDia10['generadas'])->toHaveKey($distribuidora->id);
});

it('si una distribuidora falla al generar su corte, las demás igual se generan y el error queda reportado', function (): void {
    $distA = crearDistribuidoraParaCorte('DIST-A');
    $distB = crearDistribuidoraParaCorte('DIST-B');
    crearValeParaCorte($distA, 15000, 8);
    crearValeParaCorte($distB, 15000, 8);

    // Fuerza el conflicto en A: ya existe una relación de A para esa fecha de corte.
    Relacion::create([
        'distribuidora_id' => $distA->id, 'sucursal_id' => $distA->sucursal_id,
        'referencia_pago' => 'REF-PRECREADA-A', 'fecha_corte' => '2026-02-15',
        'fecha_limite_pago' => '2026-02-16', 'limite_credito_snapshot' => 20000, 'estado' => 'pendiente',
    ]);

    $resultado = app(RelacionCalculoService::class)->generarCortesDelDia('2026-02-15');

    expect($resultado['generadas'])->toHaveCount(1)
        ->and($resultado['generadas'])->toHaveKey($distB->id)
        ->and($resultado['errores'])->toHaveCount(1)
        ->and($resultado['errores'])->toHaveKey($distA->id);
});
