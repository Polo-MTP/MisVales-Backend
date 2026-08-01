<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
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
        $distribuidoraRole = Role::query()->where('name', 'Distribuidora')->first();

        // 1. Administrador (3FA: Contraseña + TOTP + Correo OTP)
        User::query()->updateOrCreate(
            ['email' => 'trejomisaelperez2304@gmail.com'],
            [
                'name' => 'Misael Trejo (Admin 3FA)',
                'password' => Hash::make('8Yro|U_WZi4.39Nny'),
                'role_id' => $adminRole?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'admin@correo.com'],
            [
                'name' => 'Admin Test (3FA)',
                'password' => Hash::make('Password123!'),
                'role_id' => $adminRole?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 2. Gerente General (3FA)
        User::query()->updateOrCreate(
            ['email' => 'gerente.general@correo.com'],
            [
                'name' => 'Gerente General (3FA)',
                'password' => Hash::make('Password123!'),
                'role_id' => $gerenteGeneralRole?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 3. Gerente de Sucursal (3FA)
        User::query()->updateOrCreate(
            ['email' => 'gerente.sucursal@correo.com'],
            [
                'name' => 'Gerente de Sucursal (3FA)',
                'password' => Hash::make('Password123!'),
                'role_id' => $gerenteSucursalRole?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 4. Coordinador (2FA)
        User::query()->updateOrCreate(
            ['email' => 'coordinador@correo.com'],
            [
                'name' => 'Coordinador Test (2FA)',
                'password' => Hash::make('Password123!'),
                'role_id' => $coordinadorRole?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 5. Verificador (2FA)
        User::query()->updateOrCreate(
            ['email' => 'verificador@correo.com'],
            [
                'name' => 'Verificador Test (2FA)',
                'password' => Hash::make('Password123!'),
                'role_id' => $verificadorRole?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 6. Distribuidora (2FA)
        User::query()->updateOrCreate(
            ['email' => 'distribuidora@correo.com'],
            [
                'name' => 'Distribuidora Test (2FA)',
                'password' => Hash::make('Password123!'),
                'role_id' => $distribuidoraRole?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
