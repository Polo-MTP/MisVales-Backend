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
            // Antes del corte de morosidad: crea al único Gerente General/Administrador/
            // Gerente de Sucursal del sistema, que MorosidadDemoSeeder busca por rol para
            // registrar quién "generó" cada Relacion de prueba.
            EquipoDemoSeeder::class,           // Cuentas reales del equipo, roles intercalados
            MorosidadDemoSeeder::class,        // Distribuidoras/vales/relaciones vencidas para probar reportes/morosos
            GerenteGeneralUttSeeder::class,      // Gerente General adicional (correo UTT)
            MisaelGerentesSeeder::class,         // Gerente General y Gerente Sucursal (correo Misael con alias)
            KyeUsersSeeder::class,               // 5 usuarios de prueba por rol (correo Kye)
            JonathanUsersSeeder::class,          // 3 usuarios de prueba por rol (correo Jonathan)
        ]);
    }
}