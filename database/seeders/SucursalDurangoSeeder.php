<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\HistorialClienteDistr;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Sucursal de prueba adicional (Durango) con su propio Gerente de Sucursal, Coordinador,
 * Verificador, Cajera, una Distribuidora y 4 Clientes -- mismo propósito y mismo patrón que
 * SucursalGomezPalacioSeeder (ver ahí), solo que para otra sucursal distinta.
 *
 * No está enganchado en DatabaseSeeder a propósito -- correr aparte con:
 *   php artisan db:seed --class=SucursalDurangoSeeder
 *
 * Las cuentas de staff reutilizan los correos reales del equipo (ver EquipoSeeder) con un
 * alias '+dgo' para que el email siga siendo único por cuenta pero cada quien reconozca cuál
 * es la suya.
 */
final class SucursalDurangoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $roles = Role::query()->pluck('id', 'name');

            /** @var Sucursal $sucursal */
            $sucursal = Sucursal::query()->firstOrCreate(
                ['nombre' => 'Sucursal Durango'],
                [
                    'codigo' => 'SUC-003',
                    'es_matriz' => false,
                    'is_active' => true,
                ]
            );

            $gerenteSucursal = User::query()->updateOrCreate(
                ['email' => 'gael140404+dgo@gmail.com'],
                [
                    'name' => 'Gael (Gerente de Sucursal Durango)',
                    'password' => Hash::make('Twrbobyr24*dgo'),
                    'role_id' => $roles['Gerente de Sucursal'] ?? null,
                    'sucursal_id' => $sucursal->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $coordinador = User::query()->updateOrCreate(
                ['email' => 'jonathan.hister.6776+dgo@gmail.com'],
                [
                    'name' => 'Jonathan (Coordinador Durango)',
                    'password' => Hash::make('QaTest123!dgo'),
                    'role_id' => $roles['Coordinador'] ?? null,
                    'sucursal_id' => $sucursal->id,
                    'gerente_id' => $gerenteSucursal->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $verificador = User::query()->updateOrCreate(
                ['email' => 'kyescasfelix15+dgo@gmail.com'],
                [
                    'name' => 'Kye (Verificador Durango)',
                    'password' => Hash::make('Hkhkupwz39*dgo'),
                    'role_id' => $roles['Verificador'] ?? null,
                    'sucursal_id' => $sucursal->id,
                    'gerente_id' => $gerenteSucursal->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            User::query()->updateOrCreate(
                ['email' => 'wilbert.acosta+dgo@outlook.com'],
                [
                    'name' => 'Wilbert (Cajera Durango)',
                    'password' => Hash::make('Sygtwqbz55@dgo'),
                    'role_id' => $roles['Cajera'] ?? null,
                    'sucursal_id' => $sucursal->id,
                    'gerente_id' => $gerenteSucursal->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            // ─── Distribuidora ──────────────────────────────────────────────
            $direccionDistribuidora = Direccion::query()->firstOrCreate(
                ['calle' => 'Avenida 20 de Noviembre', 'numero_ext' => '610', 'colonia' => 'Centro'],
                [
                    'numero_int' => null,
                    'codigo_postal' => '34000',
                    'estado' => 'Durango',
                    'ciudad' => 'Durango',
                ]
            );

            $datosDistribuidora = DatosPersonales::query()->firstOrCreate(
                ['curp' => 'REMF830312HDGYNR07'],
                [
                    'nombre' => 'Fernando',
                    'apellido_paterno' => 'Reyes',
                    'apellido_materno' => 'Muñoz',
                    'direccion_id' => $direccionDistribuidora->id,
                    'fecha_nacimiento' => '1983-03-12',
                    'lugar_nacimiento' => 'Durango, Durango',
                ]
            );

            $distribuidoraUser = User::query()->updateOrCreate(
                ['email' => 'distribuidora.durango@example.com'],
                [
                    'name' => $datosDistribuidora->nombre.' '.$datosDistribuidora->apellido_paterno,
                    'password' => Hash::make('Rdgo2026Distr*'),
                    'role_id' => $roles['Distribuidora'] ?? null,
                    'datos_id' => $datosDistribuidora->id,
                    'sucursal_id' => $sucursal->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $categoriaBronce = CategoriaDistribuidora::query()->where('nombre', 'BRONCE')->first();

            /** @var Distribuidora $distribuidora */
            $distribuidora = Distribuidora::query()->firstOrCreate(
                ['usuario_id' => $distribuidoraUser->id],
                [
                    'numero_distribuidora' => 'DIST-'.mb_str_pad((string) $distribuidoraUser->id, 5, '0', STR_PAD_LEFT),
                    'rfc' => 'REMF830312AB2',
                    'sucursal_id' => $sucursal->id,
                    'coordinador_id' => $coordinador->id,
                    'verificador_id' => $verificador->id,
                    'categoria_id' => $categoriaBronce?->id,
                    'aprobado_por' => $gerenteSucursal->id,
                    'fecha_aprobacion' => now(),
                    'comentarios_verificador' => 'Alta de prueba vía SucursalDurangoSeeder.',
                    'limite_credito' => 70000.00,
                    'puntos_acumulados' => 0,
                    'estado' => 'ACTIVO',
                ]
            );

            // ─── 4 Clientes ─────────────────────────────────────────────────
            $clientes = [
                [
                    'nombre' => 'Alicia', 'apellido_paterno' => 'Contreras', 'apellido_materno' => 'Rivas',
                    'curp' => 'CORA911204MDGNVL02',
                    'calle' => 'Calle Negrete', 'numero_ext' => '145', 'colonia' => 'Barrio del Calvario',
                ],
                [
                    'nombre' => 'Emilio', 'apellido_paterno' => 'Salcido', 'apellido_materno' => 'Reyna',
                    'curp' => 'SARE870825HDGLYM06',
                    'calle' => 'Calle Bruno Martínez', 'numero_ext' => '76', 'colonia' => 'Silvestre Dorador',
                ],
                [
                    'nombre' => 'Norma', 'apellido_paterno' => 'Aguirre', 'apellido_materno' => 'Bustos',
                    'curp' => 'AUBN940630MDGGSR09',
                    'calle' => 'Calle Constitución', 'numero_ext' => '212', 'colonia' => 'Guadalupe',
                ],
                [
                    'nombre' => 'Rodolfo', 'apellido_paterno' => 'Palomino', 'apellido_materno' => 'Estrada',
                    'curp' => 'PAER800917HDGLSD03',
                    'calle' => 'Calle Pino Suárez', 'numero_ext' => '58', 'colonia' => 'Villa Jardín',
                ],
            ];

            foreach ($clientes as $datosCliente) {
                $direccionCliente = Direccion::query()->firstOrCreate(
                    ['calle' => $datosCliente['calle'], 'numero_ext' => $datosCliente['numero_ext'], 'colonia' => $datosCliente['colonia']],
                    [
                        'numero_int' => null,
                        'codigo_postal' => '34070',
                        'estado' => 'Durango',
                        'ciudad' => 'Durango',
                    ]
                );

                $datosPersonalesCliente = DatosPersonales::query()->firstOrCreate(
                    ['curp' => $datosCliente['curp']],
                    [
                        'nombre' => $datosCliente['nombre'],
                        'apellido_paterno' => $datosCliente['apellido_paterno'],
                        'apellido_materno' => $datosCliente['apellido_materno'],
                        'direccion_id' => $direccionCliente->id,
                        'fecha_nacimiento' => null,
                        'lugar_nacimiento' => 'Durango, Durango',
                    ]
                );

                /** @var Cliente $cliente */
                $cliente = Cliente::query()->firstOrCreate(
                    ['datos_id' => $datosPersonalesCliente->id],
                    ['estado' => true]
                );

                HistorialClienteDistr::query()->firstOrCreate(
                    ['distribuidor_id' => $distribuidora->id, 'cliente_id' => $cliente->id, 'fecha_fin' => null],
                    ['fecha_inicio' => now()]
                );
            }
        });
    }
}
