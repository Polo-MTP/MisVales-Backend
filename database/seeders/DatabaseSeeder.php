<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Orden recomendado según dependencias. Solo deben existir el Administrador (ver
        // UserSeeder) y una cuenta por rol del equipo (ver EquipoSeeder), todos en la única
        // sucursal (ver SucursalSeeder) -- nada de lotes de cuentas de prueba.
        $this->call([
            RoleSeeder::class,                 // Roles (Spatie)
            SucursalSeeder::class,             // Sucursal única (matriz)
            UserSeeder::class,                 // Administrador (depende de la sucursal)
            CategoriaDistribuidoraSeeder::class, // Categorías (BRONCE, PLATA, ORO)
            ProductoSeeder::class,             // Catálogo de productos
            ConfiguracionSeeder::class,        // Configuraciones globales + tabla de seguros
            MfaSeeder::class,                  // Tipos de MFA (email, TOTP)
            EquipoSeeder::class,               // Las 5 cuentas del equipo (una por rol)
        ]);
    }
}