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
 * 4 Distribuidoras adicionales (con 4 Clientes cada una, 16 en total) para la sucursal Saltillo
 * -- requiere haber corrido SucursalSaltilloSeeder antes (de ahí toma la sucursal, el
 * coordinador, el verificador y el gerente que aprueba). Mismo patrón que
 * DistribuidorasGomezPalacioSeeder (ver ahí).
 *
 * No está enganchado en DatabaseSeeder, a propósito -- correr aparte con:
 *   php artisan db:seed --class=DistribuidorasSaltilloSeeder
 *
 * Las 4 cuentas usan alias '+' sobre el correo real de Kye (kyescasfelix15@gmail.com) -- todas
 * le llegan a la misma bandeja, pero son 4 cuentas distintas para el sistema.
 */
final class DistribuidorasSaltilloSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            /** @var Sucursal|null $sucursal */
            $sucursal = Sucursal::query()->where('nombre', 'Sucursal Saltillo')->first();
            $coordinador = User::query()->where('email', 'jonathan.hister.6776+sal@gmail.com')->first();
            $verificador = User::query()->where('email', 'kyescasfelix15+sal@gmail.com')->first();
            $gerenteSucursal = User::query()->where('email', 'gael140404+sal@gmail.com')->first();

            if (! $sucursal || ! $coordinador || ! $verificador || ! $gerenteSucursal) {
                throw new RuntimeException('Corre primero SucursalSaltilloSeeder -- este seeder depende de esa sucursal, coordinador, verificador y gerente ya creados.');
            }

            $roleDistribuidora = Role::query()->where('name', 'Distribuidora')->first()?->id;
            $categoriaBronce = CategoriaDistribuidora::query()->where('nombre', 'BRONCE')->first();

            $distribuidoras = [
                [
                    'alias' => 'sal1',
                    'nombre' => 'Humberto', 'apellido_paterno' => 'Cárdenas', 'apellido_materno' => 'Espinoza',
                    'curp' => 'CAEH820109HCLRSM05', 'rfc' => 'CAEH820109AB5',
                    'calle' => 'Boulevard Nazario Ortiz Garza', 'numero_ext' => '540', 'colonia' => 'Nazario Ortiz Garza',
                    'clientes' => [
                        ['nombre' => 'Rocío', 'apellido_paterno' => 'Gaytán', 'apellido_materno' => 'Loredo', 'curp' => 'GALR930410MCLYRC00', 'calle' => 'Calle Victoria', 'numero_ext' => '18', 'colonia' => 'Buenos Aires'],
                        ['nombre' => 'Homero', 'apellido_paterno' => 'Salinas', 'apellido_materno' => 'Del Bosque', 'curp' => 'SABH880212HCLLSM06', 'calle' => 'Calle Bravo Norte', 'numero_ext' => '72', 'colonia' => 'Mirasierra'],
                        ['nombre' => 'Lucía', 'apellido_paterno' => 'Escamilla', 'apellido_materno' => 'Reyes', 'curp' => 'ESRL910826MCLSYL02', 'calle' => 'Calle Múzquiz Norte', 'numero_ext' => '129', 'colonia' => 'Nueva Aurora'],
                        ['nombre' => 'Cuauhtémoc', 'apellido_paterno' => 'Longoria', 'apellido_materno' => 'Sifuentes', 'curp' => 'LOSC850514HCLNFC01', 'calle' => 'Calle Zaragoza Sur', 'numero_ext' => '206', 'colonia' => 'Landín'],
                    ],
                ],
                [
                    'alias' => 'sal2',
                    'nombre' => 'Karina', 'apellido_paterno' => 'Botello', 'apellido_materno' => 'Guajardo',
                    'curp' => 'BOGK890703MCLTJR07', 'rfc' => 'BOGK890703CD6',
                    'calle' => 'Calle Purcell', 'numero_ext' => '287', 'colonia' => 'República',
                    'clientes' => [
                        ['nombre' => 'Alonso', 'apellido_paterno' => 'Segovia', 'apellido_materno' => 'Anzures', 'curp' => 'SEAA920117HCLGNL03', 'calle' => 'Calle Hidalgo Sur', 'numero_ext' => '41', 'colonia' => 'Saltillo 400'],
                        ['nombre' => 'Fabiola', 'apellido_paterno' => 'Rangel', 'apellido_materno' => 'Cavazos', 'curp' => 'RACF870529MCLNVB09', 'calle' => 'Calle Falcón', 'numero_ext' => '158', 'colonia' => 'Valle Alto'],
                        ['nombre' => 'Emiliano', 'apellido_paterno' => 'Dávila', 'apellido_materno' => 'Reséndiz', 'curp' => 'DARE940208HCLVSM04', 'calle' => 'Calle Coahuila', 'numero_ext' => '63', 'colonia' => 'Guayulera'],
                        ['nombre' => 'Nadia', 'apellido_paterno' => 'Ochoa', 'apellido_materno' => 'Villanueva', 'curp' => 'OCVN900822MCLCLD08', 'calle' => 'Calle Ramos Arizpe', 'numero_ext' => '190', 'colonia' => 'Los Rodríguez'],
                    ],
                ],
                [
                    'alias' => 'sal3',
                    'nombre' => 'Rubén', 'apellido_paterno' => 'Zamarripa', 'apellido_materno' => 'Contreras',
                    'curp' => 'ZACR791211HCLMNB06', 'rfc' => 'ZACR791211EF7',
                    'calle' => 'Calle Pérez Treviño', 'numero_ext' => '112', 'colonia' => 'Mirador',
                    'clientes' => [
                        ['nombre' => 'Guadalupe', 'apellido_paterno' => 'Farías', 'apellido_materno' => 'Ibáñez', 'curp' => 'FAIG950604MCLRBD01', 'calle' => 'Calle Obregón', 'numero_ext' => '85', 'colonia' => 'Estación'],
                        ['nombre' => 'Israel', 'apellido_paterno' => 'Cepeda', 'apellido_materno' => 'Márquez', 'curp' => 'CEMI860130HCLPRS05', 'calle' => 'Calle Juárez Norte', 'numero_ext' => '224', 'colonia' => 'Ricardo Flores Magón'],
                        ['nombre' => 'Marlene', 'apellido_paterno' => 'Anaya', 'apellido_materno' => 'Guerrero', 'curp' => 'AAGM910919MCLNRR07', 'calle' => 'Calle Los Ángeles', 'numero_ext' => '39', 'colonia' => 'Cerritos'],
                        ['nombre' => 'Pedro', 'apellido_paterno' => 'Villalón', 'apellido_materno' => 'Gómez', 'curp' => 'VIGP840327HCLLMD02', 'calle' => 'Calle Trece', 'numero_ext' => '167', 'colonia' => 'La Fuente'],
                    ],
                ],
                [
                    'alias' => 'sal4',
                    'nombre' => 'Mireya', 'apellido_paterno' => 'Rocha', 'apellido_materno' => 'Uribe',
                    'curp' => 'ROUM830816MCLCRR03', 'rfc' => 'ROUM830816GH8',
                    'calle' => 'Calle Aristóteles', 'numero_ext' => '58', 'colonia' => 'Chapultepec',
                    'clientes' => [
                        ['nombre' => 'Genaro', 'apellido_paterno' => 'Escobar', 'apellido_materno' => 'Tovar', 'curp' => 'ESTG900511HCLSVN08', 'calle' => 'Calle Colón Sur', 'numero_ext' => '96', 'colonia' => 'Topo Chico'],
                        ['nombre' => 'Wendy', 'apellido_paterno' => 'Cantú', 'apellido_materno' => 'Berlanga', 'curp' => 'CABW940227MCLNRN06', 'calle' => 'Calle Cuauhtémoc Norte', 'numero_ext' => '143', 'colonia' => 'Los Ángeles'],
                        ['nombre' => 'Iván', 'apellido_paterno' => 'Palomo', 'apellido_materno' => 'Estrada', 'curp' => 'PAEI870705HCLLSV00', 'calle' => 'Calle Morelos Poniente', 'numero_ext' => '77', 'colonia' => 'Alamedas'],
                        ['nombre' => 'Dulce', 'apellido_paterno' => 'Riojas', 'apellido_materno' => 'Fuentes', 'curp' => 'RIFD920314MCLJND05', 'calle' => 'Calle Río San Juan', 'numero_ext' => '211', 'colonia' => 'Villa Olímpica'],
                    ],
                ],
            ];

            foreach ($distribuidoras as $d) {
                $direccionDistribuidora = Direccion::query()->firstOrCreate(
                    ['calle' => $d['calle'], 'numero_ext' => $d['numero_ext'], 'colonia' => $d['colonia']],
                    [
                        'numero_int' => null,
                        'codigo_postal' => '25079',
                        'estado' => 'Coahuila',
                        'ciudad' => 'Saltillo',
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
                        'lugar_nacimiento' => 'Saltillo, Coahuila',
                    ]
                );

                $distribuidoraUser = User::query()->updateOrCreate(
                    ['email' => "kyescasfelix15+{$d['alias']}@gmail.com"],
                    [
                        'name' => $d['nombre'].' '.$d['apellido_paterno'],
                        'password' => Hash::make('Kye2026Sal*'.$d['alias']),
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
                        'comentarios_verificador' => 'Alta de prueba vía DistribuidorasSaltilloSeeder.',
                        'limite_credito' => 70000.00,
                        'puntos_acumulados' => 0,
                        'estado' => 'ACTIVO',
                    ]
                );

                foreach ($d['clientes'] as $datosCliente) {
                    $direccionCliente = Direccion::query()->firstOrCreate(
                        ['calle' => $datosCliente['calle'], 'numero_ext' => $datosCliente['numero_ext'], 'colonia' => $datosCliente['colonia']],
                        [
                            'numero_int' => null,
                            'codigo_postal' => '25079',
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
            }
        });
    }
}
