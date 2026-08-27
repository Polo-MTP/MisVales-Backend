<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class MisaelGerentesSeeder extends Seeder
{
    public function run(): void
    {
        $gerenteGeneralRole = Role::query()->where('name', 'Gerente General')->first();
        $gerenteSucursalRole = Role::query()->where('name', 'Gerente de Sucursal')->first();

        $matriz = Sucursal::query()->where('es_matriz', true)->first();
        $sucursalGomez = Sucursal::query()->where('nombre', 'Sucursal Gómez Palacio')->first();

        // 1. Gerente General (Sucursal Matriz - Acceso Global)
        User::query()->updateOrCreate(
            ['email' => 'trejomisaelperez2304+gg@gmail.com'],
            [
                'name' => 'Misael Trejo (Gerente General)',
                'password' => Hash::make('8Yro|U_WZi4.39Nny'),
                'role_id' => $gerenteGeneralRole?->id,
                'sucursal_id' => $matriz?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 2. Gerente de Sucursal (Sucursal Gómez Palacio)
        User::query()->updateOrCreate(
            ['email' => 'trejomisaelperez2304+gs@gmail.com'],
            [
                'name' => 'Misael Trejo (Gerente Sucursal Gómez Palacio)',
                'password' => Hash::make('8Yro|U_WZi4.39Nny'),
                'role_id' => $gerenteSucursalRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
