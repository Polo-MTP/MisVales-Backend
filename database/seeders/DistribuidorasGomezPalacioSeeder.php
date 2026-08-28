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
use RuntimeException;

/**
 * 4 Distribuidoras adicionales (con 4 Clientes cada una, 16 en total) para la sucursal Gómez
 * Palacio -- requiere haber corrido SucursalGomezPalacioSeeder antes (de ahí toma la sucursal,
 * el coordinador, el verificador y el gerente que aprueba).
 *
 * No está enganchado en DatabaseSeeder, a propósito -- correr aparte con:
 *   php artisan db:seed --class=DistribuidorasGomezPalacioSeeder
 *
 * Las 4 cuentas usan alias '+' sobre el correo real de Kye (kyescasfelix15@gmail.com) -- todas
 * le llegan a la misma bandeja, pero son 4 cuentas distintas para el sistema (mismo truco que
 * ya usa jonathan.hister.6776+co1@gmail.com en EquipoSeeder).
 */
final class DistribuidorasGomezPalacioSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            /** @var Sucursal|null $sucursal */
            $sucursal = Sucursal::query()->where('nombre', 'Sucursal Gómez Palacio')->first();
            $coordinador = User::query()->where('email', 'jonathan.hister.6776+gp@gmail.com')->first();
            $verificador = User::query()->where('email', 'kyescasfelix15+gp@gmail.com')->first();
            $gerenteSucursal = User::query()->where('email', 'gael140404+gp@gmail.com')->first();

            if (! $sucursal || ! $coordinador || ! $verificador || ! $gerenteSucursal) {
                throw new RuntimeException('Corre primero SucursalGomezPalacioSeeder -- este seeder depende de esa sucursal, coordinador, verificador y gerente ya creados.');
            }

            $roleDistribuidora = Role::query()->where('name', 'Distribuidora')->first()?->id;
            $categoriaBronce = CategoriaDistribuidora::query()->where('nombre', 'BRONCE')->first();

            $distribuidoras = [
                [
                    'alias' => 'dist1',
                    'nombre' => 'Alejandro', 'apellido_paterno' => 'Mendoza', 'apellido_materno' => 'Silva',
                    'curp' => 'MESA800104HDGNLL02', 'rfc' => 'MESA800104AB1',
                    'calle' => 'Boulevard Miguel Alemán', 'numero_ext' => '512', 'colonia' => 'Los Ángeles',
                    'clientes' => [
                        ['nombre' => 'Rosa', 'apellido_paterno' => 'Vega', 'apellido_materno' => 'Chávez', 'curp' => 'VECR910203MDGGHS01', 'calle' => 'Calle Allende', 'numero_ext' => '14', 'colonia' => 'Torreón Jardín'],
                        ['nombre' => 'Miguel', 'apellido_paterno' => 'Ortiz', 'apellido_materno' => 'Palacios', 'curp' => 'OIPM870619HDGRLG04', 'calle' => 'Calle Degollado', 'numero_ext' => '77', 'colonia' => 'Ampliación Torreón'],
                        ['nombre' => 'Sandra', 'apellido_paterno' => 'Cárdenas', 'apellido_materno' => 'Ruiz', 'curp' => 'CARS930811MDGRDN06', 'calle' => 'Calle Ocampo', 'numero_ext' => '203', 'colonia' => 'Nuevo Aeropuerto'],
                        ['nombre' => 'Héctor', 'apellido_paterno' => 'Miranda', 'apellido_materno' => 'Solís', 'curp' => 'MISH841022HDGRLC09', 'calle' => 'Calle Aldama', 'numero_ext' => '61', 'colonia' => 'La Joya'],
                    ],
                ],
                [
                    'alias' => 'dist2',
                    'nombre' => 'Patricia', 'apellido_paterno' => 'Núñez', 'apellido_materno' => 'Cordero',
                    'curp' => 'NUCP820715MDGXRT08', 'rfc' => 'NUCP820715CD2',
                    'calle' => 'Avenida Niños Héroes', 'numero_ext' => '340', 'colonia' => 'Del Bosque',
                    'clientes' => [
                        ['nombre' => 'Javier', 'apellido_paterno' => 'Bautista', 'apellido_materno' => 'León', 'curp' => 'BALJ890430HDGTNV03', 'calle' => 'Calle Independencia', 'numero_ext' => '99', 'colonia' => 'Centro Gómez Palacio'],
                        ['nombre' => 'Karla', 'apellido_paterno' => 'Rentería', 'apellido_materno' => 'Domínguez', 'curp' => 'REDK950912MDGNMR05', 'calle' => 'Calle 5 de Febrero', 'numero_ext' => '18', 'colonia' => 'Villa Florida'],
                        ['nombre' => 'Óscar', 'apellido_paterno' => 'Salazar', 'apellido_materno' => 'Ponce', 'curp' => 'SAPO861117HDGLNS07', 'calle' => 'Calle Reforma', 'numero_ext' => '145', 'colonia' => 'San Isidro'],
                        ['nombre' => 'Diana', 'apellido_paterno' => 'Escobedo', 'apellido_materno' => 'Marín', 'curp' => 'ESMD920228MDGSRN00', 'calle' => 'Calle Guerrero', 'numero_ext' => '56', 'colonia' => 'Los Fresnos'],
                    ],
                ],
                [
                    'alias' => 'dist3',
                    'nombre' => 'Ricardo', 'apellido_paterno' => 'Fuentes', 'apellido_materno' => 'Salas',
                    'curp' => 'FUSR790526HDGNLC01', 'rfc' => 'FUSR790526EF3',
                    'calle' => 'Calle Presidente Carranza', 'numero_ext' => '28', 'colonia' => 'La Concha',
                    'clientes' => [
                        ['nombre' => 'Verónica', 'apellido_paterno' => 'Guzmán', 'apellido_materno' => 'Ibarra', 'curp' => 'GUIV940115MDGZBR02', 'calle' => 'Calle Matamoros', 'numero_ext' => '73', 'colonia' => 'El Vergel'],
                        ['nombre' => 'Fernando', 'apellido_paterno' => 'Loera', 'apellido_materno' => 'Contreras', 'curp' => 'LOCF830923HDGRNR06', 'calle' => 'Calle Colón', 'numero_ext' => '190', 'colonia' => 'Las Rosas'],
                        ['nombre' => 'Alejandra', 'apellido_paterno' => 'Pineda', 'apellido_materno' => 'Vargas', 'curp' => 'PIVA880604MDGNRL09', 'calle' => 'Calle Bravo', 'numero_ext' => '41', 'colonia' => 'Praderas del Sol'],
                        ['nombre' => 'Iván', 'apellido_paterno' => 'Camacho', 'apellido_materno' => 'Ledezma', 'curp' => 'CALI910709HDGMDV04', 'calle' => 'Calle Victoria', 'numero_ext' => '108', 'colonia' => 'Real del Sol'],
                    ],
                ],
                [
                    'alias' => 'dist4',
                    'nombre' => 'Leticia', 'apellido_paterno' => 'Cano', 'apellido_materno' => 'Ibarra',
                    'curp' => 'CAIL840311MDGNBT05', 'rfc' => 'CAIL840311GH4',
                    'calle' => 'Calle Constitución', 'numero_ext' => '215', 'colonia' => 'La Merced',
                    'clientes' => [
                        ['nombre' => 'Adrián', 'apellido_paterno' => 'Villalobos', 'apellido_materno' => 'Reyna', 'curp' => 'VIRA950502HDGLYD07', 'calle' => 'Calle Cuauhtémoc', 'numero_ext' => '32', 'colonia' => 'Solidaridad'],
                        ['nombre' => 'Mónica', 'apellido_paterno' => 'Espino', 'apellido_materno' => 'Chaidez', 'curp' => 'ESCM890817MDGSHN03', 'calle' => 'Calle Morelos Sur', 'numero_ext' => '87', 'colonia' => 'Las Américas'],
                        ['nombre' => 'Gerardo', 'apellido_paterno' => 'Portillo', 'apellido_materno' => 'Sáenz', 'curp' => 'POSG820129HDGRNR08', 'calle' => 'Calle Zapata', 'numero_ext' => '164', 'colonia' => 'El Palmar'],
                        ['nombre' => 'Cynthia', 'apellido_paterno' => 'Beltrán', 'apellido_materno' => 'Ávila', 'curp' => 'BEAC930725MDGLVN01', 'calle' => 'Calle Hidalgo Norte', 'numero_ext' => '50', 'colonia' => 'Nueva Rosita'],
                    ],
                ],
            ];

            foreach ($distribuidoras as $d) {
                $direccionDistribuidora = Direccion::query()->firstOrCreate(
                    ['calle' => $d['calle'], 'numero_ext' => $d['numero_ext'], 'colonia' => $d['colonia']],
                    [
                        'numero_int' => null,
                        'codigo_postal' => '35079',
                        'estado' => 'Durango',
                        'ciudad' => 'Gómez Palacio',
                    ]
                );

                $datosDistribuidora = DatosPersonales::query()->firstOrCreate(
                    ['curp' => $d['curp']],
                    [
                        'nombre' => $d['nombre'],
                        'apellido_paterno' => $d['apellido_paterno'],
                        'apellido_materno' => $d['apellido_materno'],
                        'direccion_id' => $direccionDistribuidora->id,
                        'fecha_nacimiento' => null,
                        'lugar_nacimiento' => 'Gómez Palacio, Durango',
                    ]
                );

                $distribuidoraUser = User::query()->updateOrCreate(
                    ['email' => "kyescasfelix15+{$d['alias']}@gmail.com"],
                    [
                        'name' => $d['nombre'].' '.$d['apellido_paterno'],
                        'password' => Hash::make('Kye2026Gp*'.$d['alias']),
                        'role_id' => $roleDistribuidora,
                        'datos_id' => $datosDistribuidora->id,
                        'sucursal_id' => $sucursal->id,
                        'is_active' => true,
                        'email_verified_at' => now(),
                    ]
                );

                /** @var Distribuidora $distribuidora */
                $distribuidora = Distribuidora::query()->firstOrCreate(
                    ['usuario_id' => $distribuidoraUser->id],
                    [
                        'numero_distribuidora' => 'DIST-'.mb_str_pad((string) $distribuidoraUser->id, 5, '0', STR_PAD_LEFT),
                        'rfc' => $d['rfc'],
                        'sucursal_id' => $sucursal->id,
                        'coordinador_id' => $coordinador->id,
                        'verificador_id' => $verificador->id,
                        'categoria_id' => $categoriaBronce?->id,
                        'aprobado_por' => $gerenteSucursal->id,
                        'fecha_aprobacion' => now(),
                        'comentarios_verificador' => 'Alta de prueba vía DistribuidorasGomezPalacioSeeder.',
                        'limite_credito' => 15000.00,
                        'puntos_acumulados' => 0,
                        'estado' => 'ACTIVO',
                    ]
                );

                foreach ($d['clientes'] as $datosCliente) {
                    $direccionCliente = Direccion::query()->firstOrCreate(
                        ['calle' => $datosCliente['calle'], 'numero_ext' => $datosCliente['numero_ext'], 'colonia' => $datosCliente['colonia']],
                        [
                            'numero_int' => null,
                            'codigo_postal' => '35079',
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
            }
        });
    }
}
