<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::query()->where('name', 'Administrador')->first();
        $gerenteGeneralRole = Role::query()->where('name', 'Gerente General')->first();
        $gerenteSucursalRole = Role::query()->where('name', 'Gerente de Sucursal')->first();
        $coordinadorRole = Role::query()->where('name', 'Coordinador')->first();
        $verificadorRole = Role::query()->where('name', 'Verificador')->first();
        $cajeraRole = Role::query()->where('name', 'Cajera')->first();
        $distribuidoraRole = Role::query()->where('name', 'Distribuidora')->first();

        /** @var Sucursal|null $matriz */
        $matriz = Sucursal::query()->where('es_matriz', true)->first();
        /** @var Sucursal|null $sucursalGomez */
        $sucursalGomez = Sucursal::query()->where('nombre', 'Sucursal Gómez Palacio')->first();
        /** @var Sucursal|null $sucursalDurango */
        $sucursalDurango = Sucursal::query()->where('nombre', 'Sucursal Durango')->first();

        // Buscar un coordinador existente para asignarlo a la distribuidora
        $coordinador = User::query()
            ->where('role_id', $coordinadorRole?->id)
            ->where('sucursal_id', $sucursalGomez?->id)
            ->first();

        // 1. Administradores (Sucursal Matriz)
        User::query()->updateOrCreate(
            ['email' => 'trejomisaelperez2304@gmail.com'],
            [
                'name' => 'Misael Trejo (Admin Matriz)',
                'password' => Hash::make('8Yro|U_WZi4.39Nny'),
                'role_id' => $adminRole?->id,
                'sucursal_id' => $matriz?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'admin@correo.com'],
            [
                'name' => 'Admin Test (Matriz)',
                'password' => Hash::make('Password123!'),
                'role_id' => $adminRole?->id,
                'sucursal_id' => $matriz?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 2. Gerente General (Sucursal Matriz - Acceso Global a todas las sucursales)
        User::query()->updateOrCreate(
            ['email' => 'gerente.general@correo.com'],
            [
                'name' => 'Gerente General (Matriz Global)',
                'password' => Hash::make('Password123!'),
                'role_id' => $gerenteGeneralRole?->id,
                'sucursal_id' => $matriz?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 3. Gerente de Sucursal Gómez Palacio
        User::query()->updateOrCreate(
            ['email' => 'gerente.sucursal@correo.com'],
            [
                'name' => 'Gerente Sucursal Gómez Palacio',
                'password' => Hash::make('Password123!'),
                'role_id' => $gerenteSucursalRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 4. Gerente de Sucursal Durango (Para probar restricción entre sucursales)
        User::query()->updateOrCreate(
            ['email' => 'gerente.durango@correo.com'],
            [
                'name' => 'Gerente Sucursal Durango',
                'password' => Hash::make('Password123!'),
                'role_id' => $gerenteSucursalRole?->id,
                'sucursal_id' => $sucursalDurango?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 5. Coordinador (Sucursal Gómez Palacio)
        User::query()->updateOrCreate(
            ['email' => 'coordinador@correo.com'],
            [
                'name' => 'Coordinador Gómez Palacio',
                'password' => Hash::make('Password123!'),
                'role_id' => $coordinadorRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 6. Verificador (Sucursal Gómez Palacio)
        User::query()->updateOrCreate(
            ['email' => 'verificador@correo.com'],
            [
                'name' => 'Verificador Gómez Palacio',
                'password' => Hash::make('Password123!'),
                'role_id' => $verificadorRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 6b. Cajera (Sucursal Gómez Palacio)
        User::query()->updateOrCreate(
            ['email' => 'cajera@correo.com'],
            [
                'name' => 'Cajera Gómez Palacio',
                'password' => Hash::make('Password123!'),
                'role_id' => $cajeraRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 7. Distribuidora (Sucursal Gómez Palacio)
        /** @var User $distribuidoraUser */
        $distribuidoraUser = User::query()->updateOrCreate(
            ['email' => 'distribuidora@correo.com'],
            [
                'name' => 'Distribuidora Gómez Palacio',
                'password' => Hash::make('Password123!'),
                'role_id' => $distribuidoraRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // Crear/actualizar la distribuidora con los nuevos campos
        Distribuidora::query()->updateOrCreate(
            ['usuario_id' => $distribuidoraUser->id],
            [
                'numero_distribuidora' => 'DIST-00001',
                'limite_credito' => 50000.00,
                // ELIMINADO: 'credito_disponible' (se calcula automáticamente)
                'puntos_acumulados' => 120,
                'estado' => 'ACTIVO',  

                'sucursal_id' => $sucursalGomez?->id,
                'coordinador_id' => $coordinador?->id, // Asignar un coordinador existente
                'razon_social' => 'Distribuidora Gómez Palacio S.A.',
                'rfc' => 'DGP123456789',
                'categoria_id' => 1, // Si tienes categorías, descomenta y asigna un ID válido
                'usuario_acceso' => 'distribuidora01',
                'password_hash' => Hash::make('Password123!'),
                'comentarios_verificador' => 'Verificado correctamente',
                'fecha_aprobacion' => now(),
                'aprobado_por' => $gerenteGeneralRole?->id, // Asignar aprobador
            ]
        );
    }
}