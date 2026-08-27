<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Sucursal;
use Illuminate\Database\Seeder;

final class SucursalSeeder extends Seeder
{
    /**
     * Una sola sucursal (la matriz) -- todos los usuarios de sucursal (Cajera, Coordinador,
     * Verificador, Gerente de Sucursal) del equipo caen aquí, ver EquipoSeeder.
     */
    public function run(): void
    {
        Sucursal::query()->firstOrCreate(
            ['nombre' => 'Matriz Torreón'],
            [
                'codigo' => 'SUC-001',
                'es_matriz' => true,
                'is_active' => true,
            ]
        );
    }
}
