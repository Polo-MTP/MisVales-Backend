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
 * Cuentas reales de prueba para el equipo, con roles intercalados para probar cómo se
 * comporta la app (y el escaneo por sucursal) desde distintos correos personales.
 * Idempotente (updateOrCreate por email), igual que UserSeeder.
 */
final class EquipoDemoSeeder extends Seeder
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

        $matriz = Sucursal::query()->where('es_matriz', true)->first();
        $sucursalGomez = Sucursal::query()->where('nombre', 'Sucursal Gómez Palacio')->first();
        $sucursalDurango = Sucursal::query()->where('nombre', 'Sucursal Durango')->first();
        $categoriaPlata = CategoriaDistribuidora::query()->where('nombre', 'PLATA')->first();

        $coordinadorExistente = User::query()
            ->where('role_id', $coordinadorRole?->id)
            ->where('sucursal_id', $sucursalGomez?->id)
            ->first();

        // --- Grupo 1 ---
        User::query()->updateOrCreate(
            ['email' => 'aguiladorada1998@hotmail.com'],
            [
                'name' => 'Wilbert (Administrador)',
                'password' => Hash::make('Whnyikxm64#'),
                'role_id' => $adminRole?->id,
                'sucursal_id' => $matriz?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => '6aguila.dorada.1998@gmail.com'],
            [
                'name' => 'Wilbert (Gerente General)',
                'password' => Hash::make('Fvgzbegt33@'),
                'role_id' => $gerenteGeneralRole?->id,
                'sucursal_id' => $matriz?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => '18aguila.dorada.1998@gmail.com'],
            [
                'name' => 'Wilbert (Coordinador)',
                'password' => Hash::make('Pbpsuxta49!'),
                'role_id' => $coordinadorRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        /** @var User $distribuidoraUser */
        $distribuidoraUser = User::query()->updateOrCreate(
            ['email' => 'wilbert.acosta@outlook.com'],
            [
                'name' => 'Wilbert (Distribuidora)',
                'password' => Hash::make('Sygtwqbz55@'),
                'role_id' => $distribuidoraRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        Distribuidora::query()->updateOrCreate(
            ['usuario_id' => $distribuidoraUser->id],
            [
                'numero_distribuidora' => 'DIST-EQUIPO-01',
                'limite_credito' => 25000.00,
                'puntos_acumulados' => 0,
                'estado' => 'ACTIVO',
                'sucursal_id' => $sucursalGomez?->id,
                'coordinador_id' => $coordinadorExistente?->id,
                'nombre' => 'Wilbert Acosta (Distribuidora de Prueba)',
                'rfc' => 'AOWX980101EQ1',
                'categoria_id' => $categoriaPlata?->id,
                'comentarios_verificador' => 'Verificado correctamente',
                'fecha_aprobacion' => now(),
                'aprobado_por' => $coordinadorExistente?->id,
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'wilbertacosta@develop.mx'],
            [
                'name' => 'Wilbert (Cajera)',
                'password' => Hash::make('Sgzcbqox98%'),
                'role_id' => $cajeraRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // --- Grupo 2 (roles distintos a los del grupo 1) ---
        User::query()->updateOrCreate(
            ['email' => 'gael140404@gmail.com'],
            [
                'name' => 'Gael (Gerente de Sucursal Durango)',
                'password' => Hash::make('Twrbobyr24*'),
                'role_id' => $gerenteSucursalRole?->id,
                'sucursal_id' => $sucursalDurango?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'eliasgalarzapedroza@gmail.com'],
            [
                'name' => 'Elías (Verificador Durango)',
                'password' => Hash::make('Mzpdbdjz69@'),
                'role_id' => $verificadorRole?->id,
                'sucursal_id' => $sucursalDurango?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'kyescasfelix15@gmail.com'],
            [
                'name' => 'Kye (Gerente de Sucursal Gómez Palacio)',
                'password' => Hash::make('Hkhkupwz39*'),
                'role_id' => $gerenteSucursalRole?->id,
                'sucursal_id' => $sucursalGomez?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // --- Grupo 3 (de felixsaldivar, roles variados, completan el plantel de Durango) ---
        /** @var User $coordinadorDurango */
        $coordinadorDurango = User::query()->updateOrCreate(
            ['email' => 'tonogael813@gmail.com'],
            [
                'name' => 'Tono Gael (Coordinador Durango)',
                'password' => Hash::make('Ufyggnfk53*'),
                'role_id' => $coordinadorRole?->id,
                'sucursal_id' => $sucursalDurango?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'tonoxd1414@gmail.com'],
            [
                'name' => 'Tono (Cajera Durango)',
                'password' => Hash::make('Sngtbehc53@'),
                'role_id' => $cajeraRole?->id,
                'sucursal_id' => $sucursalDurango?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        /** @var User $distribuidoraDurangoUser */
        $distribuidoraDurangoUser = User::query()->updateOrCreate(
            ['email' => 'saldivar140404@gmail.com'],
            [
                'name' => 'Saldivar (Distribuidora Durango)',
                'password' => Hash::make('Xtovhcng25*'),
                'role_id' => $distribuidoraRole?->id,
                'sucursal_id' => $sucursalDurango?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        Distribuidora::query()->updateOrCreate(
            ['usuario_id' => $distribuidoraDurangoUser->id],
            [
                'numero_distribuidora' => 'DIST-EQUIPO-02',
                'limite_credito' => 25000.00,
                'puntos_acumulados' => 0,
                'estado' => 'ACTIVO',
                'sucursal_id' => $sucursalDurango?->id,
                'coordinador_id' => $coordinadorDurango->id,
                'nombre' => 'Saldivar (Distribuidora de Prueba)',
                'rfc' => 'SALD040101EQ2',
                'categoria_id' => $categoriaPlata?->id,
                'comentarios_verificador' => 'Verificado correctamente',
                'fecha_aprobacion' => now(),
                'aprobado_por' => $coordinadorDurango->id,
            ]
        );
    }
}
