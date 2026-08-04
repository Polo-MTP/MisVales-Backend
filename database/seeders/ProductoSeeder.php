<?php

namespace Database\Seeders;

use App\Models\Producto;
use Illuminate\Database\Seeder;

class ProductoSeeder extends Seeder
{
    public function run(): void
    {
        $montos = [500, 1000, 1500, 2000, 2500, 3000, 5000, 10000];
        foreach ($montos as $monto) {
            Producto::create([
                'monto' => $monto,
                'descripcion' => 'Vale de $' . number_format($monto, 2),
                'activo' => true,
                'created_by' => 1, // Asumiendo que el usuario ID 1 es Gerente General
            ]);
        }
    }
}