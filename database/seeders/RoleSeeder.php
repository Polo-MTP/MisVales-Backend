<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::query()->firstOrCreate(['name' => 'Distribuidora'], ['factor_count' => 2]);
        Role::query()->firstOrCreate(['name' => 'Coordinador'], ['factor_count' => 2]);
        Role::query()->firstOrCreate(['name' => 'Verificador'], ['factor_count' => 2]);
        Role::query()->firstOrCreate(['name' => 'Cajera'], ['factor_count' => 2]);
        Role::query()->firstOrCreate(['name' => 'Gerente de Sucursal'], ['factor_count' => 3]);
        Role::query()->firstOrCreate(['name' => 'Gerente General'], ['factor_count' => 3]);
        Role::query()->firstOrCreate(['name' => 'Administrador'], ['factor_count' => 3]);
    }
}
