<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DatosPersonales;
use App\Models\Direccion;
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
        $coordinadorRole = Role::query()->where('name', 'Coordinador')->first();
        $verificadorRole = Role::query()->where('name', 'Verificador')->first();
        $cajeraRole = Role::query()->where('name', 'Cajera')->first();
        $distribuidoraRole = Role::query()->where('name', 'Distribuidora')->first();

        /** @var Sucursal|null $matriz */
        $matriz = Sucursal::query()->where('es_matriz', true)->first();
        /** @var Sucursal|null $sucursalGomez */
        $sucursalGomez = Sucursal::query()->where('nombre', 'Sucursal Gómez Palacio')->first();

        // Buscar un coordinador existente para asignarlo a la distribuidora
        $coordinador = User::query()
            ->where('role_id', $coordinadorRole?->id)
            ->where('sucursal_id', $sucursalGomez?->id)
            ->first();

        // 1. Administrador (Sucursal Matriz) -- el único que puede haber en todo el sistema, es
        // la cuenta que se usa para operar (dar de alta al Gerente General, etc.), así que se
        // mantiene aquí en vez de en EquipoDemoSeeder. Gerente General y Gerente de Sucursal
        // (uno por sucursal) sí los provee EquipoDemoSeeder -- no se duplican aquí.
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

        // Es persona física: su "nombre" ya no se guarda aparte (ver
        // Distribuidora::getNombreAttribute()), se calcula de sus propios datos personales --
        // sin esto el usuario queda sin datos_id y la distribuidora aparece sin nombre.
        if (! $distribuidoraUser->datos_id) {
            $direccionDist = Direccion::query()->create([
                'calle' => 'Sin especificar', 'colonia' => 'Sin especificar', 'numero_ext' => 'S/N',
                'codigo_postal' => '35070', 'estado' => 'Durango', 'ciudad' => 'Gómez Palacio',
            ]);
            $datosDist = DatosPersonales::query()->create([
                'nombre' => 'Distribuidora', 'apellido_paterno' => 'Gómez Palacio',
                'curp' => 'DIST000101HDGZLA01', 'direccion_id' => $direccionDist->id,
            ]);
            $distribuidoraUser->datos_id = $datosDist->id;
            $distribuidoraUser->save();
        }

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
                'rfc' => 'DGP123456789',
                'categoria_id' => 1, // Si tienes categorías, descomenta y asigna un ID válido
                'usuario_acceso' => 'distribuidora01',
                'password_hash' => Hash::make('Password123!'),
                'comentarios_verificador' => 'Verificado correctamente',
                'fecha_aprobacion' => now(),
                // 'aprobado_por' es FK a users.id -- iba el id del ROL Gerente General por
                // error (colaba de milagro en SQLite sin FKs forzadas; truena en MySQL o con
                // 'foreign_keys' activado, ver DatabaseSeederSingletonTest).
                'aprobado_por' => $coordinador?->id,
            ]
        );
    }
}