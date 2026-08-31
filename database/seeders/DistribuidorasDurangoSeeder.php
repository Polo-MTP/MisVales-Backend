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
 * 4 Distribuidoras adicionales (con 4 Clientes cada una, 16 en total) para la sucursal Durango
 * -- requiere haber corrido SucursalDurangoSeeder antes (de ahí toma la sucursal, el
 * coordinador, el verificador y el gerente que aprueba). Mismo patrón que
 * DistribuidorasGomezPalacioSeeder (ver ahí).
 *
 * No está enganchado en DatabaseSeeder, a propósito -- correr aparte con:
 *   php artisan db:seed --class=DistribuidorasDurangoSeeder
 *
 * Las 4 cuentas usan alias '+' sobre el correo real de Kye (kyescasfelix15@gmail.com) -- todas
 * le llegan a la misma bandeja, pero son 4 cuentas distintas para el sistema.
 */
final class DistribuidorasDurangoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            /** @var Sucursal|null $sucursal */
            $sucursal = Sucursal::query()->where('nombre', 'Sucursal Durango')->first();
            $coordinador = User::query()->where('email', 'jonathan.hister.6776+dgo@gmail.com')->first();
            $verificador = User::query()->where('email', 'kyescasfelix15+dgo@gmail.com')->first();
            $gerenteSucursal = User::query()->where('email', 'gael140404+dgo@gmail.com')->first();

            if (! $sucursal || ! $coordinador || ! $verificador || ! $gerenteSucursal) {
                throw new RuntimeException('Corre primero SucursalDurangoSeeder -- este seeder depende de esa sucursal, coordinador, verificador y gerente ya creados.');
            }

            $roleDistribuidora = Role::query()->where('name', 'Distribuidora')->first()?->id;
            $categoriaBronce = CategoriaDistribuidora::query()->where('nombre', 'BRONCE')->first();

            $distribuidoras = [
                [
                    'alias' => 'dgo1',
                    'nombre' => 'Gustavo', 'apellido_paterno' => 'Herrera', 'apellido_materno' => 'Nájera',
                    'curp' => 'HENG810512HDGRJS04', 'rfc' => 'HENG810512AB3',
                    'calle' => 'Boulevard Domingo Arrieta', 'numero_ext' => '330', 'colonia' => 'Barrio de Analco',
                    'clientes' => [
                        ['nombre' => 'Beatriz', 'apellido_paterno' => 'Frayre', 'apellido_materno' => 'Ontiveros', 'curp' => 'FAOB930118MDGRNT05', 'calle' => 'Calle Patoni', 'numero_ext' => '22', 'colonia' => 'Fátima'],
                        ['nombre' => 'Ramón', 'apellido_paterno' => 'Solís', 'apellido_materno' => 'Carrete', 'curp' => 'SOCR870709HDGLRM01', 'calle' => 'Calle Coronado', 'numero_ext' => '89', 'colonia' => 'Nueva Vizcaya'],
                        ['nombre' => 'Yolanda', 'apellido_paterno' => 'Nevárez', 'apellido_materno' => 'Ibarra', 'curp' => 'NEIY920225MDGVBL08', 'calle' => 'Calle Norman', 'numero_ext' => '164', 'colonia' => 'Industrial'],
                        ['nombre' => 'Salvador', 'apellido_paterno' => 'Quiñones', 'apellido_materno' => 'Terrazas', 'curp' => 'QUTS840403HDGLRV07', 'calle' => 'Calle Cuauhtémoc', 'numero_ext' => '95', 'colonia' => 'Las Palmas'],
                    ],
                ],
                [
                    'alias' => 'dgo2',
                    'nombre' => 'Claudia', 'apellido_paterno' => 'Márquez', 'apellido_materno' => 'Olivas',
                    'curp' => 'MAOC860924MDGRLL06', 'rfc' => 'MAOC860924CD4',
                    'calle' => 'Avenida Universidad', 'numero_ext' => '412', 'colonia' => 'Roma',
                    'clientes' => [
                        ['nombre' => 'Ezequiel', 'apellido_paterno' => 'Payán', 'apellido_materno' => 'Domínguez', 'curp' => 'PADE890714HDGYZQ00', 'calle' => 'Calle Aquiles Serdán', 'numero_ext' => '37', 'colonia' => 'El Progreso'],
                        ['nombre' => 'Perla', 'apellido_paterno' => 'Chacón', 'apellido_materno' => 'Rosales', 'curp' => 'CARP950301MDGHSR03', 'calle' => 'Calle Insurgentes', 'numero_ext' => '150', 'colonia' => 'Las Rosas'],
                        ['nombre' => 'Arturo', 'apellido_paterno' => 'Bermúdez', 'apellido_materno' => 'Fierro', 'curp' => 'BEFA820608HDGRRR09', 'calle' => 'Calle Río Nazas', 'numero_ext' => '63', 'colonia' => 'Valle Verde'],
                        ['nombre' => 'Isela', 'apellido_paterno' => 'Portillo', 'apellido_materno' => 'Guereca', 'curp' => 'POGI910427MDGRSL02', 'calle' => 'Calle Efrén Rebles', 'numero_ext' => '178', 'colonia' => 'Filadelfia'],
                    ],
                ],
                [
                    'alias' => 'dgo3',
                    'nombre' => 'Marco', 'apellido_paterno' => 'Estrella', 'apellido_materno' => 'Baca',
                    'curp' => 'ESBM790215HDGTCR05', 'rfc' => 'ESBM790215EF6',
                    'calle' => 'Calle Zaragoza', 'numero_ext' => '99', 'colonia' => 'San Luis',
                    'clientes' => [
                        ['nombre' => 'Teresa', 'apellido_paterno' => 'Villegas', 'apellido_materno' => 'Cordero', 'curp' => 'VICT940512MDGLRR01', 'calle' => 'Calle Mina', 'numero_ext' => '204', 'colonia' => 'Los Ángeles'],
                        ['nombre' => 'Damián', 'apellido_paterno' => 'Rosas', 'apellido_materno' => 'Almanza', 'curp' => 'ROAD860819HDGSLM04', 'calle' => 'Calle Victoria', 'numero_ext' => '46', 'colonia' => 'El Mezquital'],
                        ['nombre' => 'Silvia', 'apellido_paterno' => 'Cabral', 'apellido_materno' => 'Muñoz', 'curp' => 'CAMS900930MDGBXL07', 'calle' => 'Calle Bravo', 'numero_ext' => '117', 'colonia' => 'Casa Blanca'],
                        ['nombre' => 'Eduardo', 'apellido_paterno' => 'Terán', 'apellido_materno' => 'Solano', 'curp' => 'TESE830126HDGRLD08', 'calle' => 'Calle Guerrero', 'numero_ext' => '81', 'colonia' => 'Tierra Blanca'],
                    ],
                ],
                [
                    'alias' => 'dgo4',
                    'nombre' => 'Adriana', 'apellido_paterno' => 'Loya', 'apellido_materno' => 'Reséndez',
                    'curp' => 'LORA880203MDGYSD09', 'rfc' => 'LORA880203GH7',
                    'calle' => 'Calle Progreso', 'numero_ext' => '271', 'colonia' => 'Lázaro Cárdenas',
                    'clientes' => [
                        ['nombre' => 'Octavio', 'apellido_paterno' => 'Franco', 'apellido_materno' => 'Beltrán', 'curp' => 'FABO910614HDGRLC00', 'calle' => 'Calle Madero', 'numero_ext' => '29', 'colonia' => 'Villa del Sol'],
                        ['nombre' => 'Graciela', 'apellido_paterno' => 'Peña', 'apellido_materno' => 'Sarabia', 'curp' => 'PESG870308MDGXRR06', 'calle' => 'Calle Colón', 'numero_ext' => '138', 'colonia' => 'Real del Mezquital'],
                        ['nombre' => 'Bernardo', 'apellido_paterno' => 'Anaya', 'apellido_materno' => 'Cordero', 'curp' => 'AACB840719HDGNRR03', 'calle' => 'Calle Ocampo', 'numero_ext' => '54', 'colonia' => 'Nueva España'],
                        ['nombre' => 'Lorena', 'apellido_paterno' => 'Trejo', 'apellido_materno' => 'Vázquez', 'curp' => 'TEVL930522MDGRZR05', 'calle' => 'Calle Juárez', 'numero_ext' => '190', 'colonia' => 'Las Américas'],
                    ],
                ],
            ];

            foreach ($distribuidoras as $d) {
                $direccionDistribuidora = Direccion::query()->firstOrCreate(
                    ['calle' => $d['calle'], 'numero_ext' => $d['numero_ext'], 'colonia' => $d['colonia']],
                    [
                        'numero_int' => null,
                        'codigo_postal' => '34079',
                        'estado' => 'Durango',
                        'ciudad' => 'Durango',
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
                        'lugar_nacimiento' => 'Durango, Durango',
                    ]
                );

                $distribuidoraUser = User::query()->updateOrCreate(
                    ['email' => "kyescasfelix15+{$d['alias']}@gmail.com"],
                    [
                        'name' => $d['nombre'].' '.$d['apellido_paterno'],
                        'password' => Hash::make('Kye2026Dgo*'.$d['alias']),
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
                        'comentarios_verificador' => 'Alta de prueba vía DistribuidorasDurangoSeeder.',
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
                            'codigo_postal' => '34079',
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
            }
        });
    }
}
