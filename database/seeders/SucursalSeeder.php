<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Sucursal;
use Illuminate\Database\Seeder;

final class SucursalSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Sucursal Matriz
        Sucursal::query()->firstOrCreate(
            ['nombre' => 'Matriz Torreón'],
            [
                'codigo' => 'SUC-001',
                'es_matriz' => true,
                'is_active' => true,
            ]
        );

        // 2. Sucursales Locales
        Sucursal::query()->firstOrCreate(
            ['nombre' => 'Sucursal Gómez Palacio'],
            [
                'codigo' => 'SUC-002',
                'es_matriz' => false,
                'is_active' => true,
            ]
        );

        Sucursal::query()->firstOrCreate(
            ['nombre' => 'Sucursal Durango'],
            [
                'codigo' => 'SUC-003',
                'es_matriz' => false,
                'is_active' => true,
            ]
        );
    }
}
