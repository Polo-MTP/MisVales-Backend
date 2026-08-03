<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SucursalSeeder::class,
            MfaSeeder::class,
            UserSeeder::class,
            ConfiguracionSeeder::class,
        ]);
    }
}
