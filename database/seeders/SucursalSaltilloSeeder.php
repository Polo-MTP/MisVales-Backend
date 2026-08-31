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
 * Sucursal de prueba adicional (Saltillo) con su propio Gerente de Sucursal, Coordinador,
 * Verificador, Cajera, una Distribuidora y 4 Clientes -- mismo propósito y mismo patrón que
 * SucursalGomezPalacioSeeder (ver ahí), solo que para otra sucursal distinta.
 *
 * No está enganchado en DatabaseSeeder a propósito -- correr aparte con:
 *   php artisan db:seed --class=SucursalSaltilloSeeder
 *
 * Las cuentas de staff reutilizan los correos reales del equipo (ver EquipoSeeder) con un
 * alias '+sal' para que el email siga siendo único por cuenta pero cada quien reconozca cuál
 * es la suya.
 */
final class SucursalSaltilloSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $roles = Role::query()->pluck('id', 'name');

            /** @var Sucursal $sucursal */
            $sucursal = Sucursal::query()->firstOrCreate(
                ['nombre' => 'Sucursal Saltillo'],
                [
                    'codigo' => 'SUC-004',
                    'es_matriz' => false,
                    'is_active' => true,
                ]
            );

            $gerenteSucursal = User::query()->updateOrCreate(
                ['email' => 'gael140404+sal@gmail.com'],
                [
                    'name' => 'Gael (Gerente de Sucursal Saltillo)',
                    'password' => Hash::make('Twrbobyr24*sal'),
                    'role_id' => $roles['Gerente de Sucursal'] ?? null,
                    'sucursal_id' => $sucursal->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $coordinador = User::query()->updateOrCreate(
                ['email' => 'jonathan.hister.6776+sal@gmail.com'],
                [
                    'name' => 'Jonathan (Coordinador Saltillo)',
                    'password' => Hash::make('QaTest123!sal'),
                    'role_id' => $roles['Coordinador'] ?? null,
                    'sucursal_id' => $sucursal->id,
                    'gerente_id' => $gerenteSucursal->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $verificador = User::query()->updateOrCreate(
                ['email' => 'kyescasfelix15+sal@gmail.com'],
                [
                    'name' => 'Kye (Verificador Saltillo)',
                    'password' => Hash::make('Hkhkupwz39*sal'),
                    'role_id' => $roles['Verificador'] ?? null,
                    'sucursal_id' => $sucursal->id,
                    'gerente_id' => $gerenteSucursal->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            User::query()->updateOrCreate(
                ['email' => 'wilbert.acosta+sal@outlook.com'],
                [
                    'name' => 'Wilbert (Cajera Saltillo)',
                    'password' => Hash::make('Sygtwqbz55@sal'),
                    'role_id' => $roles['Cajera'] ?? null,
                    'sucursal_id' => $sucursal->id,
                    'gerente_id' => $gerenteSucursal->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            // ─── Distribuidora ──────────────────────────────────────────────
            $direccionDistribuidora = Direccion::query()->firstOrCreate(
                ['calle' => 'Boulevard Venustiano Carranza', 'numero_ext' => '780', 'colonia' => 'Centro'],
                [
                    'numero_int' => null,
                    'codigo_postal' => '25000',
                    'estado' => 'Coahuila',
                    'ciudad' => 'Saltillo',
                ]
            );

            $datosDistribuidora = DatosPersonales::query()->firstOrCreate(
                ['curp' => 'GOTL840528HCLNRS03'],
                [
                    'nombre' => 'Luis',
                    'apellido_paterno' => 'González',
                    'apellido_materno' => 'Torres',
                    'direccion_id' => $direccionDistribuidora->id,
                    'fecha_nacimiento' => '1984-05-28',
                    'lugar_nacimiento' => 'Saltillo, Coahuila',
                ]
            );

            $distribuidoraUser = User::query()->updateOrCreate(
                ['email' => 'distribuidora.saltillo@example.com'],
                [
                    'name' => $datosDistribuidora->nombre.' '.$datosDistribuidora->apellido_paterno,
                    'password' => Hash::make('Rsal2026Distr*'),
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
                    'rfc' => 'GOTL840528AB4',
                    'sucursal_id' => $sucursal->id,
                    'coordinador_id' => $coordinador->id,
                    'verificador_id' => $verificador->id,
                    'categoria_id' => $categoriaBronce?->id,
                    'aprobado_por' => $gerenteSucursal->id,
                    'fecha_aprobacion' => now(),
                    'comentarios_verificador' => 'Alta de prueba vía SucursalSaltilloSeeder.',
                    'limite_credito' => 70000.00,
                    'puntos_acumulados' => 0,
                    'estado' => 'ACTIVO',
                ]
            );

            // ─── 4 Clientes ─────────────────────────────────────────────────
            $clientes = [
                [
                    'nombre' => 'Marisol', 'apellido_paterno' => 'Ibarra', 'apellido_materno' => 'Cepeda',
                    'curp' => 'IACM920714MCLBPR06',
                    'calle' => 'Calle Allende Sur', 'numero_ext' => '318', 'colonia' => 'República Oriente',
                ],
                [
                    'nombre' => 'Gilberto', 'apellido_paterno' => 'Villarreal', 'apellido_materno' => 'Cantú',
                    'curp' => 'VICG870222HCLLNL08',
                    'calle' => 'Calle Xicoténcatl', 'numero_ext' => '94', 'colonia' => 'San Isidro',
                ],
                [
                    'nombre' => 'Elvira', 'apellido_paterno' => 'Rodríguez', 'apellido_materno' => 'Moreno',
                    'curp' => 'ROME950115MCLDRL04',
                    'calle' => 'Calle Aldama Poniente', 'numero_ext' => '61', 'colonia' => 'Guadalupe',
                ],
                [
                    'nombre' => 'Sergio', 'apellido_paterno' => 'Palacios', 'apellido_materno' => 'Elizondo',
                    'curp' => 'PAES810903HCLLLR09',
                    'calle' => 'Calle Múzquiz', 'numero_ext' => '203', 'colonia' => 'Los Pinos',
                ],
            ];

            foreach ($clientes as $datosCliente) {
                $direccionCliente = Direccion::query()->firstOrCreate(
                    ['calle' => $datosCliente['calle'], 'numero_ext' => $datosCliente['numero_ext'], 'colonia' => $datosCliente['colonia']],
                    [
                        'numero_int' => null,
                        'codigo_postal' => '25070',
                        'estado' => 'Coahuila',
                        'ciudad' => 'Saltillo',
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
                        'lugar_nacimiento' => 'Saltillo, Coahuila',
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
