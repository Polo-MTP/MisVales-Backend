<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Configuracion;
use App\Models\ConfiguracionFechas;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Seeder;

final class ConfiguracionSeeder extends Seeder
{
    public function run(): void
    {
        /** @var User|null $adminUser */
        $adminUser = User::query()->where('email', 'admin@correo.com')->first()
            ?? User::query()->first();

        if (! $adminUser) {
            return;
        }

        $fechaInicio = '2025-01-01';

        // 1. Configuraciones Clave-Valor Genéricas
        $configsIniciales = [
            [
                'clave' => 'margen_tolerancia',
                'valor' => '300',
                'tipo_dato' => 'decimal',
            ],
            [
                'clave' => 'margen_tolerancia_conciliacion',
                'valor' => '300',
                'tipo_dato' => 'decimal',
            ],
            [
                'clave' => 'margen_tolerancia_credito',
                'valor' => '300',
                'tipo_dato' => 'decimal',
            ],
            [
                'clave' => 'valor_punto',
                'valor' => '5',
                'tipo_dato' => 'decimal',
            ],
            [
                'clave' => 'regla_50_pct',
                'valor' => '50',
                'tipo_dato' => 'decimal',
            ],
            // Reglas del motor de cálculo de relaciones (ver "Analisis de calculo de relacion")
            [
                'clave' => 'comision_base_pct',
                'valor' => '10',
                'tipo_dato' => 'decimal',
            ],
            [
                'clave' => 'interes_pct_quincena',
                'valor' => '5',
                'tipo_dato' => 'decimal',
            ],
            [
                'clave' => 'multa_no_pago',
                'valor' => '300',
                'tipo_dato' => 'decimal',
            ],
            [
                'clave' => 'puntos_divisor',
                'valor' => '1200',
                'tipo_dato' => 'decimal',
            ],
            [
                'clave' => 'puntos_multiplicador',
                'valor' => '3',
                'tipo_dato' => 'decimal',
            ],
            [
                'clave' => 'puntos_penalizacion_pct',
                'valor' => '20',
                'tipo_dato' => 'decimal',
            ],
            [
                'clave' => 'limite_perdones_relacion',
                'valor' => '2',
                'tipo_dato' => 'decimal',
            ],
            [
                'clave' => 'relaciones_impagas_para_morosidad',
                'valor' => '3',
                'tipo_dato' => 'decimal',
            ],
            [
                'clave' => 'margen_aumento_credito',
                'valor' => '500',
                'tipo_dato' => 'decimal',
            ],
        ];

        foreach ($configsIniciales as $cfg) {
            Configuracion::query()->updateOrCreate(
                [
                    'clave' => $cfg['clave'],
                    'vigente_hasta' => null,
                ],
                [
                    'valor' => $cfg['valor'],
                    'tipo_dato' => $cfg['tipo_dato'],
                    'vigente_desde' => $fechaInicio,
                    'modificado_por' => $adminUser->id,
                ]
            );
        }

        // 2. Configuración de Fechas por Defecto Global (sucursal_id = null)
        ConfiguracionFechas::query()->updateOrCreate(
            [
                'sucursal_id' => null,
                'vigente_hasta' => null,
            ],
            [
                'dia_corte' => 15,
                'dia_limite_pago' => 16,
                'dias_pago_anticipado' => 3,
                'vigente_desde' => $fechaInicio,
                'modificado_por' => $adminUser->id,
            ]
        );

        // 3. Configuración de Fechas específica para Sucursal Gómez Palacio
        $sucursalGomez = Sucursal::query()->where('codigo', 'SUC-002')->first();
        if ($sucursalGomez) {
            ConfiguracionFechas::query()->updateOrCreate(
                [
                    'sucursal_id' => $sucursalGomez->id,
                    'vigente_hasta' => null,
                ],
                [
                    'dia_corte' => 20,
                    'dia_limite_pago' => 22,
                    'dias_pago_anticipado' => 2,
                    'vigente_desde' => $fechaInicio,
                    'modificado_por' => $adminUser->id,
                ]
            );
        }
    }
}
