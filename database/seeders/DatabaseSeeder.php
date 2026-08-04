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
            UserSeeder::class,                 // Usuarios básicos
            SucursalSeeder::class,             // Sucursales
            CategoriaDistribuidoraSeeder::class, // Categorías (BRONCE, PLATA, ORO)
            ProductoSeeder::class,             // Catálogo de productos
            // DistribuidoraSeeder::class,     // Opcional: para datos de prueba
            ConfiguracionSeeder::class,        // Configuraciones globales
            MfaSeeder::class,                  // MFA (si aplica)
        ]);
    }
}