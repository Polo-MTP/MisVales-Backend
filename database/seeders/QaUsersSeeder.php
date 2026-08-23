<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CategoriaDistribuidora;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuarios de prueba (uno por rol) para entregar a infraestructura y correr pruebas
 * de QA en un entorno migrado. Es idempotente (updateOrCreate por email) y no toca
 * ni depende de los usuarios creados en UserSeeder/EquipoDemoSeeder: usa correos
 * nuevos (alias + de Gmail, todos caen en la misma bandeja), así que se puede
 * correr junto con esos seeders sin romper nada.
 */
final class QaUsersSeeder extends Seeder
{
    private const string PASSWORD = 'QaTest123!';

    public function run(): void
    {
        $adminRole = Role::query()->where('name', 'Administrador')->first();
        $gerenteGeneralRole = Role::query()->where('name', 'Gerente General')->first();
        $gerenteSucursalRole = Role::query()->where('name', 'Gerente de Sucursal')->first();
        $coordinadorRole = Role::query()->where('name', 'Coordinador')->first();
        $verificadorRole = Role::query()->where('name', 'Verificador')->first();
        $cajeraRole = Role::query()->where('name', 'Cajera')->first();
        $distribuidoraRole = Role::query()->where('name', 'Distribuidora')->first();

        $matriz = Sucursal::query()->where('es_matriz', true)->first();
        $sucursalGomez = Sucursal::query()->where('nombre', 'Sucursal Gómez Palacio')->first();

        $categoriaBronce = CategoriaDistribuidora::query()->where('nombre', 'BRONCE')->first();

        $coordinadorQa = User::query()->updateOrCreate(
            ['email' => 'ygz765434+co@gmail.com'],
            [
                'name' => 'QA Coordinador',
                'password' => Hash::make(self::PASSWORD),
                'role_id' => $coordinadorRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'ygz765434+ad@gmail.com'],
            [
                'name' => 'QA Administrador',
                'password' => Hash::make(self::PASSWORD),
                'role_id' => $adminRole?->id,
                'sucursal_id' => $matriz?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'ygz765434+gg@gmail.com'],
            [
                'name' => 'QA Gerente General',
                'password' => Hash::make(self::PASSWORD),
                'role_id' => $gerenteGeneralRole?->id,
                'sucursal_id' => $matriz?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'ygz765434+gs@gmail.com'],
            [
                'name' => 'QA Gerente de Sucursal',
                'password' => Hash::make(self::PASSWORD),
                'role_id' => $gerenteSucursalRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'ygz765434+ve@gmail.com'],
            [
                'name' => 'QA Verificador',
                'password' => Hash::make(self::PASSWORD),
                'role_id' => $verificadorRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'ygz765434+ca@gmail.com'],
            [
                'name' => 'QA Cajera',
                'password' => Hash::make(self::PASSWORD),
                'role_id' => $cajeraRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        /** @var User $distribuidoraUser */
        $distribuidoraUser = User::query()->updateOrCreate(
            ['email' => 'ygz765434+di@gmail.com'],
            [
                'name' => 'QA Distribuidora',
                'password' => Hash::make(self::PASSWORD),
                'role_id' => $distribuidoraRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        Distribuidora::query()->updateOrCreate(
            ['usuario_id' => $distribuidoraUser->id],
            [
                'numero_distribuidora' => 'DIST-QA-001',
                'limite_credito' => 25000.00,
                'puntos_acumulados' => 0,
                'estado' => 'ACTIVO',
                'sucursal_id' => $sucursalGomez?->id,
                'coordinador_id' => $coordinadorQa->id,
                'razon_social' => 'QA Distribuidora de Prueba',
                'rfc' => 'QATE250101QA1',
                'categoria_id' => $categoriaBronce?->id,
                'comentarios_verificador' => 'Verificado correctamente',
                'fecha_aprobacion' => now(),
                'aprobado_por' => $coordinadorQa->id,
            ]
        );
    }
}
