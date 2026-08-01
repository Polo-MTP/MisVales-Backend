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
        $userRole = Role::query()->where('name', 'Usuario')->first();
        $invitadoRole = Role::query()->where('name', 'Invitado')->first();

        // 1. Administrador (3FA con contraseña original de proyecto Laravel)
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

        // 2. Administrador Alterno (3FA con contraseña simple Password123!)
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

        // 3. Usuario Normal (2FA con contraseña original)
        User::query()->updateOrCreate(
            ['email' => 'usuario@correo.com'],
            [
                'name' => 'Usuario Normal (2FA)',
                'password' => Hash::make('501d[qP*r#e2T[bU'),
                'role_id' => $userRole?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 4. Usuario Normal Alterno (2FA con contraseña simple Password123!)
        User::query()->updateOrCreate(
            ['email' => 'user@correo.com'],
            [
                'name' => 'Usuario Test (2FA)',
                'password' => Hash::make('Password123!'),
                'role_id' => $userRole?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 5. Usuario Invitado (1FA: Contraseña simple)
        User::query()->updateOrCreate(
            ['email' => 'invitado@correo.com'],
            [
                'name' => 'Usuario Invitado (1FA)',
                'password' => Hash::make('Password123!'),
                'role_id' => $invitadoRole?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
