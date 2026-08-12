<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Orden recomendado según dependencias
        $this->call([
            RoleSeeder::class,                 // Roles (Spatie)
            SucursalSeeder::class,             // Sucursales
            UserSeeder::class,                 // Usuarios básicos (depende de sucursales)
            CategoriaDistribuidoraSeeder::class, // Categorías (BRONCE, PLATA, ORO)
            ProductoSeeder::class,             // Catálogo de productos
            // DistribuidoraSeeder::class,     // Opcional: para datos de prueba
            ConfiguracionSeeder::class,        // Configuraciones globales
            MfaSeeder::class,                  // MFA (si aplica)
            MorosidadDemoSeeder::class,        // Distribuidoras/vales/relaciones vencidas para probar reportes/morosos
        ]);
    }
}