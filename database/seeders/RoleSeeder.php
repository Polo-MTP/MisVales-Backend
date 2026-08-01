<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::query()->firstOrCreate(['name' => 'Invitado'], ['factor_count' => 1]);
        Role::query()->firstOrCreate(['name' => 'Usuario'], ['factor_count' => 2]);
        Role::query()->firstOrCreate(['name' => 'Administrador'], ['factor_count' => 3]);
    }
}
