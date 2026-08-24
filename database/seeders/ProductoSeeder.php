<?php

namespace Database\Seeders;

use App\Models\Producto;
use Illuminate\Database\Seeder;

class ProductoSeeder extends Seeder
{
    public function run(): void
    {
        // Placeholder: quincenas=10 en todos por falta del catálogo real (ver "Relación" de ejemplo,
        // que muestra planes distintos como "2/10 Plus", "4/8", "4/12 Normal"). Ajustar cuando se defina.
        $montos = [500, 1000, 1500, 2000, 2500, 3000, 5000, 10000];
        foreach ($montos as $monto) {
            Producto::query()->firstOrCreate(
                [
                    'monto' => $monto,
                    'quincenas' => 10,
                ],
                [
                    'descripcion' => 'Vale de $' . number_format($monto, 2),
                    'activo' => true,
                    'created_by' => 1,
                ]
            );
        }
    }
}