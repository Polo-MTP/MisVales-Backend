<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class GerenteGeneralUttSeeder extends Seeder
{
    public function run(): void
    {
        $gerenteGeneralRole = Role::query()->where('name', 'Gerente General')->first();
        $matriz = Sucursal::query()->where('es_matriz', true)->first();

        User::query()->updateOrCreate(
            ['email' => '22170067@uttcampus.edu.mx'],
            [
                'name' => 'Gerente General (UTT)',
                'password' => Hash::make('QaTest123!'),
                'role_id' => $gerenteGeneralRole?->id,
                'sucursal_id' => $matriz?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
