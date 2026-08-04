<?php

namespace Database\Seeders;

use App\Models\CategoriaDistribuidora;
use Illuminate\Database\Seeder;

class CategoriaDistribuidoraSeeder extends Seeder
{
    public function run(): void
    {
        $categorias = [
            ['nombre' => 'BRONCE', 'porcentaje_comision' => 1.5, 'descripcion' => 'Categoría inicial', 'activo' => true],
            ['nombre' => 'PLATA', 'porcentaje_comision' => 2.5, 'descripcion' => 'Categoría intermedia', 'activo' => true],
            ['nombre' => 'ORO', 'porcentaje_comision' => 4.0, 'descripcion' => 'Categoría premium', 'activo' => true],
        ];

        foreach ($categorias as $categoria) {
            CategoriaDistribuidora::create($categoria);
        }
    }
}