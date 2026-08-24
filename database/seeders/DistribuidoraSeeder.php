<?php

namespace Database\Seeders;

use App\Models\Distribuidora;
use Illuminate\Database\Seeder;

class DistribuidoraSeeder extends Seeder
{
    public function run(): void
    {
        // Asegúrate de tener usuarios creados
        $coordinadorId = 2; // Ajusta según tu usuario existente
        $sucursalId = 1; // Ajusta según tu sucursal existente

        Distribuidora::create([
            'numero_distribuidora' => 'DIST-2026-0001',
            'sucursal_id' => $sucursalId,
            'coordinador_id' => $coordinadorId,
            'nombre' => 'Distribuidora Ejemplo SA de CV',
            'rfc' => 'XAXX010101000',
            'limite_credito' => 10000.00,
            'categoria_id' => 1, // BRONCE
            'puntos_acumulados' => 0,
            'estado' => 'ACTIVO',
            'comentarios_verificador' => 'Verificación exitosa',
            'fecha_aprobacion' => now(),
            'aprobado_por' => 1, // Gerente General
        ]);
    }
}