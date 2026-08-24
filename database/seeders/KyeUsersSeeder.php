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
 * 5 usuarios de prueba por cada rol (35 en total) para entregar a infraestructura.
 * Usa alias + de Gmail sobre Kyescasfelix22@gmail.com, así que todos caen en la
 * misma bandeja. Idempotente y aditivo: no toca los usuarios de los demás seeders.
 */
final class KyeUsersSeeder extends Seeder
{
    private const string PASSWORD = 'QaTest123!';

    private const int PER_ROLE = 5;

    /** @var array<string, string> */
    private const ROLE_CODES = [
        'Administrador' => 'ad',
        'Gerente General' => 'gg',
        'Gerente de Sucursal' => 'gs',
        'Coordinador' => 'co',
        'Verificador' => 've',
        'Cajera' => 'ca',
        'Distribuidora' => 'di',
    ];

    public function run(): void
    {
        $matriz = Sucursal::query()->where('es_matriz', true)->first();
        $sucursalGomez = Sucursal::query()->where('nombre', 'Sucursal Gómez Palacio')->first();
        $categoriaBronce = CategoriaDistribuidora::query()->where('nombre', 'BRONCE')->first();

        $coordinadorFallback = User::query()
            ->whereHas('role', fn ($q) => $q->where('name', 'Coordinador'))
            ->first();

        foreach (self::ROLE_CODES as $roleName => $code) {
            $role = Role::query()->where('name', $roleName)->first();
            $sucursal = in_array($roleName, ['Administrador', 'Gerente General'], true) ? $matriz : $sucursalGomez;

            for ($i = 1; $i <= self::PER_ROLE; $i++) {
                $email = sprintf('Kyescasfelix22+%s%d@gmail.com', $code, $i);

                /** @var User $user */
                $user = User::query()->updateOrCreate(
                    ['email' => $email],
                    [
                        'name' => sprintf('%s %d (Kye)', $roleName, $i),
                        'password' => Hash::make(self::PASSWORD),
                        'role_id' => $role?->id,
                        'sucursal_id' => $sucursal?->id,
                        'is_active' => true,
                        'email_verified_at' => now(),
                    ]
                );

                if ($roleName === 'Distribuidora') {
                    Distribuidora::query()->updateOrCreate(
                        ['usuario_id' => $user->id],
                        [
                            'numero_distribuidora' => sprintf('DIST-KYE-%03d', $i),
                            'limite_credito' => 25000.00,
                            'puntos_acumulados' => 0,
                            'estado' => 'ACTIVO',
                            'sucursal_id' => $sucursalGomez?->id,
                            'coordinador_id' => $coordinadorFallback?->id,
                            'razon_social' => sprintf('Kye Distribuidora de Prueba %d', $i),
                            'rfc' => sprintf('KYE0%02d250101K%d', $i, $i),
                            'categoria_id' => $categoriaBronce?->id,
                            'comentarios_verificador' => 'Verificado correctamente',
                            'fecha_aprobacion' => now(),
                            'aprobado_por' => $coordinadorFallback?->id,
                        ]
                    );
                }
            }
        }
    }
}
