<?php

declare(strict_types=1);

use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Corre el DatabaseSeeder completo (el que se usa para provisionar un ambiente nuevo) y
 * confirma que respeta las reglas de negocio: un solo Administrador, un solo Gerente General,
 * una sola sucursal matriz y como mucho un Gerente de Sucursal activo por sucursal. Antes de
 * esta limpieza, varios seeders (QaUsersSeeder, KyeUsersSeeder, JonathanUsersSeeder,
 * GerenteGeneralUttSeeder) creaban cuentas adicionales de estos roles y esto habría tronado
 * contra el candado de Administrador a nivel de base de datos.
 */
it('el seeder completo respeta las reglas de unicidad de Administrador, Gerente General, matriz y Gerente de Sucursal', function (): void {
    (new DatabaseSeeder)->run();

    expect(User::query()->whereHas('role', fn ($q) => $q->where('name', 'Administrador'))->count())->toBe(1)
        ->and(User::query()->whereHas('role', fn ($q) => $q->where('name', 'Gerente General'))->count())->toBe(1)
        ->and(Sucursal::query()->where('es_matriz', true)->count())->toBe(1);

    $gerentesPorSucursal = User::query()
        ->whereHas('role', fn ($q) => $q->where('name', 'Gerente de Sucursal'))
        ->where('is_active', true)
        ->get()
        ->groupBy('sucursal_id');

    foreach ($gerentesPorSucursal as $sucursalId => $gerentes) {
        expect($gerentes)->toHaveCount(1, "La sucursal {$sucursalId} tiene más de un Gerente de Sucursal activo.");
    }
});
