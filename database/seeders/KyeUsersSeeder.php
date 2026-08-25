<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CategoriaDistribuidora;
use App\Models\DatosPersonales;
use App\Models\Direccion;
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

    /**
     * Administrador, Gerente General y Gerente de Sucursal quedan fuera a propósito: solo
     * puede haber uno de cada uno (y uno por sucursal, en el caso de Gerente de Sucursal) en
     * todo el sistema, así que no tiene sentido generarlos en lote -- esas cuentas canónicas
     * las provee EquipoDemoSeeder.
     *
     * @var array<string, string>
     */
    private const ROLE_CODES = [
        'Coordinador' => 'co',
        'Verificador' => 've',
        'Cajera' => 'ca',
        'Distribuidora' => 'di',
    ];

    public function run(): void
    {
        $sucursalGomez = Sucursal::query()->where('nombre', 'Sucursal Gómez Palacio')->first();
        $categoriaBronce = CategoriaDistribuidora::query()->where('nombre', 'BRONCE')->first();

        $coordinadorFallback = User::query()
            ->whereHas('role', fn ($q) => $q->where('name', 'Coordinador'))
            ->first();

        foreach (self::ROLE_CODES as $roleName => $code) {
            $role = Role::query()->where('name', $roleName)->first();
            $sucursal = $sucursalGomez;

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
                    // Persona física: sin datos_id el usuario no tiene de dónde calcular su
                    // nombre (ver Distribuidora::getNombreAttribute()).
                    if (! $user->datos_id) {
                        $direccion = Direccion::query()->create([
                            'calle' => 'Sin especificar', 'colonia' => 'Sin especificar', 'numero_ext' => 'S/N',
                            'codigo_postal' => '00000', 'estado' => 'Durango', 'ciudad' => 'Gómez Palacio',
                        ]);
                        $datos = DatosPersonales::query()->create([
                            'nombre' => 'Kye', 'apellido_paterno' => sprintf('Distribuidora %d', $i),
                            'curp' => sprintf('KYE%d250101HDGZLA0%d', $i, $i), 'direccion_id' => $direccion->id,
                        ]);
                        $user->datos_id = $datos->id;
                        $user->save();
                    }

                    Distribuidora::query()->updateOrCreate(
                        ['usuario_id' => $user->id],
                        [
                            'numero_distribuidora' => sprintf('DIST-KYE-%03d', $i),
                            'limite_credito' => 25000.00,
                            'puntos_acumulados' => 0,
                            'estado' => 'ACTIVO',
                            'sucursal_id' => $sucursalGomez?->id,
                            'coordinador_id' => $coordinadorFallback?->id,
                            'rfc' => sprintf('KYE%02d250101K%d', $i, $i),
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
