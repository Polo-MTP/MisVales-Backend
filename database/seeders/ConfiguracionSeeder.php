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
