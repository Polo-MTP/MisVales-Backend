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
 * Sucursal de prueba adicional (Gómez Palacio) con su propio Gerente de Sucursal, Coordinador,
 * Verificador, Cajera, una Distribuidora y 4 Clientes -- para probar flujos que involucran más
 * de una sucursal (transferencias, reasignaciones, cortes por sucursal, etc.) sin tocar la
 * única sucursal/cuenta por rol que DatabaseSeeder deja lista por defecto (ver EquipoSeeder).
 *
 * No está enganchado en DatabaseSeeder a propósito -- correr aparte con:
 *   php artisan db:seed --class=SucursalGomezPalacioSeeder
 *
 * Las cuentas de staff reutilizan los correos reales del equipo (ya usados en la matriz, ver
 * EquipoSeeder) con un alias '+gp' para que el email siga siendo único por cuenta pero cada
 * quien reconozca cuál es la suya.
 */
final class SucursalGomezPalacioSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $roles = Role::query()->pluck('id', 'name');

            /** @var Sucursal $sucursal */
            $sucursal = Sucursal::query()->firstOrCreate(
                ['nombre' => 'Sucursal Gómez Palacio'],
                [
                    'codigo' => 'SUC-002',
                    'es_matriz' => false,
                    'is_active' => true,
                ]
            );

            $gerenteSucursal = User::query()->updateOrCreate(
                ['email' => 'gael140404+gp@gmail.com'],
                [
                    'name' => 'Gael (Gerente de Sucursal Gómez Palacio)',
                    'password' => Hash::make('Twrbobyr24*gp'),
                    'role_id' => $roles['Gerente de Sucursal'] ?? null,
                    'sucursal_id' => $sucursal->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $coordinador = User::query()->updateOrCreate(
                ['email' => 'jonathan.hister.6776+gp@gmail.com'],
                [
                    'name' => 'Jonathan (Coordinador Gómez Palacio)',
                    'password' => Hash::make('QaTest123!gp'),
                    'role_id' => $roles['Coordinador'] ?? null,
                    'sucursal_id' => $sucursal->id,
                    'gerente_id' => $gerenteSucursal->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $verificador = User::query()->updateOrCreate(
                ['email' => 'kyescasfelix15+gp@gmail.com'],
                [
                    'name' => 'Kye (Verificador Gómez Palacio)',
                    'password' => Hash::make('Hkhkupwz39*gp'),
                    'role_id' => $roles['Verificador'] ?? null,
                    'sucursal_id' => $sucursal->id,
                    'gerente_id' => $gerenteSucursal->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            User::query()->updateOrCreate(
                ['email' => 'wilbert.acosta+gp@outlook.com'],
                [
                    'name' => 'Wilbert (Cajera Gómez Palacio)',
                    'password' => Hash::make('Sygtwqbz55@gp'),
                    'role_id' => $roles['Cajera'] ?? null,
                    'sucursal_id' => $sucursal->id,
                    'gerente_id' => $gerenteSucursal->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            // ─── Distribuidora ──────────────────────────────────────────────
            // Mismo patrón que SolicitudProveedorService::aprobarORechazar(): Direccion ->
            // DatosPersonales -> User (role Distribuidora) -> Distribuidora.
            $direccionDistribuidora = Direccion::query()->firstOrCreate(
                ['calle' => 'Avenida Francisco I. Madero', 'numero_ext' => '450', 'colonia' => 'Centro'],
                [
                    'numero_int' => null,
                    'codigo_postal' => '35000',
                    'estado' => 'Durango',
                    'ciudad' => 'Gómez Palacio',
                ]
            );

            $datosDistribuidora = DatosPersonales::query()->firstOrCreate(
                ['curp' => 'ROGP850101HDGDRR05'],
                [
                    'nombre' => 'Roberto',
                    'apellido_paterno' => 'García',
                    'apellido_materno' => 'Prieto',
                    'direccion_id' => $direccionDistribuidora->id,
                    'fecha_nacimiento' => '1985-01-01',
                    'lugar_nacimiento' => 'Gómez Palacio, Durango',
                ]
            );

            $distribuidoraUser = User::query()->updateOrCreate(
                ['email' => 'distribuidora.gomezpalacio@example.com'],
                [
                    'name' => $datosDistribuidora->nombre.' '.$datosDistribuidora->apellido_paterno,
                    'password' => Hash::make('Rgp2026Distr*'),
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
                    'rfc' => 'GAPR850101AB1',
                    'sucursal_id' => $sucursal->id,
                    'coordinador_id' => $coordinador->id,
                    'verificador_id' => $verificador->id,
                    'categoria_id' => $categoriaBronce?->id,
                    'aprobado_por' => $gerenteSucursal->id,
                    'fecha_aprobacion' => now(),
                    'comentarios_verificador' => 'Alta de prueba vía SucursalGomezPalacioSeeder.',
                    'limite_credito' => 15000.00,
                    'puntos_acumulados' => 0,
                    'estado' => 'ACTIVO',
                ]
            );

            // ─── 4 Clientes ─────────────────────────────────────────────────
            // Mismo patrón que ClienteService::crearCliente(): Direccion -> DatosPersonales ->
            // Cliente -> HistorialClienteDistr (vínculo vigente con la distribuidora de arriba).
            $clientes = [
                [
                    'nombre' => 'María', 'apellido_paterno' => 'López', 'apellido_materno' => 'Hernández',
                    'curp' => 'LOHM900215MDGPRR08',
                    'calle' => 'Calle Hidalgo', 'numero_ext' => '120', 'colonia' => 'Valle del Guadiana',
                ],
                [
                    'nombre' => 'José', 'apellido_paterno' => 'Ramírez', 'apellido_materno' => 'Soto',
                    'curp' => 'RASJ880730HDGMTS03',
                    'calle' => 'Calle Morelos', 'numero_ext' => '88', 'colonia' => 'Las Fuentes',
                ],
                [
                    'nombre' => 'Guadalupe', 'apellido_paterno' => 'Torres', 'apellido_materno' => 'Nava',
                    'curp' => 'TONG950512MDGRVD01',
                    'calle' => 'Calle Zaragoza', 'numero_ext' => '35', 'colonia' => 'Fovissste',
                ],
                [
                    'nombre' => 'Francisco', 'apellido_paterno' => 'Delgado', 'apellido_materno' => 'Reyes',
                    'curp' => 'DERF821103HDGLYR06',
                    'calle' => 'Calle Juárez', 'numero_ext' => '210', 'colonia' => 'La Perla',
                ],
            ];

            foreach ($clientes as $datosCliente) {
                $direccionCliente = Direccion::query()->firstOrCreate(
                    ['calle' => $datosCliente['calle'], 'numero_ext' => $datosCliente['numero_ext'], 'colonia' => $datosCliente['colonia']],
                    [
                        'numero_int' => null,
                        'codigo_postal' => '35070',
                        'estado' => 'Durango',
                        'ciudad' => 'Gómez Palacio',
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
                        'lugar_nacimiento' => 'Gómez Palacio, Durango',
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
