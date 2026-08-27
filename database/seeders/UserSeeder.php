<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class UserSeeder extends Seeder
{
    /**
     * El único Administrador del sistema (candado de unicidad a nivel de columna, ver
     * User::saving() y af67e1a). El resto del equipo (Gerente General, Gerente de Sucursal,
     * Coordinador, Verificador, Cajera) lo crea EquipoSeeder.
     */
    public function run(): void
    {
        $adminRole = Role::query()->where('name', 'Administrador')->first();

        /** @var Sucursal|null $matriz */
        $matriz = Sucursal::query()->where('es_matriz', true)->first();

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
    }
}