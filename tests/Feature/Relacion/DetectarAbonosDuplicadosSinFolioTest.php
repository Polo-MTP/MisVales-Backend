<?php

declare(strict_types=1);

use App\Models\AbonoConciliacion;
use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Relacion;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function crearRelacionParaAbonos(): Relacion
{
    $sucursal = Sucursal::create(['nombre' => 'Suc-'.uniqid(), 'codigo' => 'SUC-'.uniqid(), 'es_matriz' => true, 'is_active' => true]);
    $categoria = CategoriaDistribuidora::create(['nombre' => 'PLATA-'.uniqid(), 'porcentaje_comision' => 6, 'activo' => true]);
    $role = Role::firstOrCreate(['name' => 'Distribuidora']);
    $usuario = User::factory()->create(['role_id' => $role->id, 'sucursal_id' => $sucursal->id, 'is_active' => true]);

    $distribuidora = Distribuidora::create([
        'usuario_id' => $usuario->id, 'numero_distribuidora' => 'DIST-'.uniqid(), 'limite_credito' => 20000,
        'categoria_id' => $categoria->id, 'puntos_acumulados' => 0, 'estado' => 'ACTIVO', 'sucursal_id' => $sucursal->id,
    ]);

    return Relacion::create([
        'distribuidora_id' => $distribuidora->id, 'sucursal_id' => $sucursal->id,
        'referencia_pago' => 'REF-'.uniqid(), 'fecha_corte' => '2026-02-15', 'fecha_limite_pago' => '2026-02-16',
        'limite_credito_snapshot' => 20000, 'total_a_pagar' => 5424, 'total_abonado' => 5424, 'estado' => 'liquidada',
    ]);
}

function crearAbonoSinFolio(Relacion $relacion, float $monto, string $fechaPago, string $horaPago): AbonoConciliacion
{
    return AbonoConciliacion::create([
        'relacion_id' => $relacion->id, 'referencia_leida' => $relacion->referencia_pago,
        'monto' => $monto, 'folio_pago' => null, 'fecha_pago' => $fechaPago, 'hora_pago' => $horaPago,
        'tipo_pago' => 'transferencia', 'estado' => 'conciliado',
    ]);
}

it('no reporta nada si no hay abonos sin folio duplicados', function (): void {
    $relacion = crearRelacionParaAbonos();
    crearAbonoSinFolio($relacion, 2712, '2026-02-13', '14:00:00');

    $this->artisan('conciliaciones:detectar-duplicados-sin-folio')
        ->expectsOutputToContain('No se encontraron abonos sin folio que parezcan duplicados.')
        ->assertExitCode(0);
});

it('reporta un grupo de abonos sin folio con referencia+monto+fecha+hora iguales, sin tocar la base de datos', function (): void {
    $relacion = crearRelacionParaAbonos();
    crearAbonoSinFolio($relacion, 2712, '2026-02-13', '14:00:00');
    crearAbonoSinFolio($relacion, 2712, '2026-02-13', '14:00:00');

    $this->artisan('conciliaciones:detectar-duplicados-sin-folio')
        ->expectsOutputToContain('2,712.00')
        ->expectsOutputToContain('SOLO un reporte')
        ->assertExitCode(0);

    // No corrige nada -- los dos abonos y el total_abonado siguen exactamente igual.
    expect(AbonoConciliacion::count())->toBe(2)
        ->and((float) $relacion->fresh()->total_abonado)->toBe(5424.0);
});

it('NO reporta abonos con montos, fechas u horas distintas como duplicados entre sí', function (): void {
    $relacion = crearRelacionParaAbonos();
    crearAbonoSinFolio($relacion, 2712, '2026-02-13', '14:00:00');
    crearAbonoSinFolio($relacion, 2712, '2026-02-13', '15:00:00');
    crearAbonoSinFolio($relacion, 1000, '2026-02-13', '14:00:00');

    $this->artisan('conciliaciones:detectar-duplicados-sin-folio')
        ->expectsOutputToContain('No se encontraron abonos sin folio que parezcan duplicados.');
});

it('un abono con folio de pago nunca se reporta, aunque coincida en todo lo demás con otro', function (): void {
    $relacion = crearRelacionParaAbonos();
    crearAbonoSinFolio($relacion, 2712, '2026-02-13', '14:00:00');
    AbonoConciliacion::create([
        'relacion_id' => $relacion->id, 'referencia_leida' => $relacion->referencia_pago,
        'monto' => 2712, 'folio_pago' => 'F001', 'fecha_pago' => '2026-02-13', 'hora_pago' => '14:00:00',
        'tipo_pago' => 'transferencia', 'estado' => 'conciliado',
    ]);

    $this->artisan('conciliaciones:detectar-duplicados-sin-folio')
        ->expectsOutputToContain('No se encontraron abonos sin folio que parezcan duplicados.');
});
