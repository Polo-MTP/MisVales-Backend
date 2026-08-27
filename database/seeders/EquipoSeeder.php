<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Las únicas cuentas del equipo que deben existir en el sistema, además del Administrador
 * (ver UserSeeder). Una por rol, todas en la única sucursal (la matriz, ver SucursalSeeder).
 * Reemplaza a EquipoDemoSeeder/KyeUsersSeeder/JonathanUsersSeeder/MisaelGerentesSeeder/
 * GerenteGeneralUttSeeder/MorosidadDemoSeeder/QaUsersSeeder (eliminados) -- ese lote generaba
 * decenas de cuentas de prueba en varias sucursales, contradiciendo el requisito de que solo
 * exista un usuario por rol en una sola sucursal.
 *
 * Las contraseñas se conservan tal cual las tenía cada persona en los seeders anteriores (de
 * donde viene cada cuenta), para no invalidar credenciales que el equipo ya conoce.
 */
final class EquipoSeeder extends Seeder
{
    public function run(): void
    {
        /** @var Sucursal|null $matriz */
        $matriz = Sucursal::query()->where('es_matriz', true)->first();

        $roles = Role::query()->pluck('id', 'name');

        $cuentas = [
            // email => [nombre, rol, password -- viene de EquipoDemoSeeder (Gerente de Sucursal Gómez Palacio)]
            'kyescasfelix15@gmail.com' => ['Kye (Verificador)', 'Verificador', 'Hkhkupwz39*'],
            // viene de EquipoDemoSeeder (Distribuidora Durango)
            'saldivar140404@gmail.com' => ['Saldivar (Gerente General)', 'Gerente General', 'Xtovhcng25*'],
            // viene de EquipoDemoSeeder (Distribuidora Gómez Palacio)
            'wilbert.acosta@outlook.com' => ['Wilbert (Cajera)', 'Cajera', 'Sygtwqbz55@'],
            // viene de JonathanUsersSeeder (Coordinador #1 del lote)
            'jonathan.hister.6776+co1@gmail.com' => ['Jonathan (Coordinador)', 'Coordinador', 'QaTest123!'],
            // viene de EquipoDemoSeeder (Gerente de Sucursal Durango) -- mismo rol, ahora en la matriz
            'gael140404@gmail.com' => ['Gael (Gerente de Sucursal)', 'Gerente de Sucursal', 'Twrbobyr24*'],
        ];

        foreach ($cuentas as $email => [$nombre, $rolNombre, $password]) {
            User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $nombre,
                    'password' => Hash::make($password),
                    'role_id' => $roles[$rolNombre] ?? null,
                    'sucursal_id' => $matriz?->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
