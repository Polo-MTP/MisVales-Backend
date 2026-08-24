<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El corte pasa de un solo dia_corte a un par: dia_corte (primera quincena) y dia_corte_2
     * (segunda quincena), configurables por sucursal y siempre en pareja -- ver
     * UpdateConfiguracionFechasRequest (exige ambos y que sean distintos) y
     * RelacionCalculoService::esDiaDeCorte(). Un día mayor a los días reales del mes se capa
     * al último día calendario, mismo criterio que ya usa dia_limite_pago (ver
     * RelacionCalculoService::calcularFechas()) -- así 31 sirve para expresar "fin de mes"
     * incluso en meses de 28-30 días.
     */
    public function up(): void
    {
        Schema::table('configuracion_fechas', function (Blueprint $table): void {
            $table->integer('dia_corte_2')->nullable()->after('dia_corte');
        });

        // Backfill de filas existentes: si su dia_corte ya era 15, la segunda quincena queda
        // en fin de mes (31, se capa solo); si no, se agrega el 15 como segundo corte y se
        // deja el dia_corte existente como el otro.
        DB::table('configuracion_fechas')->update([
            'dia_corte_2' => DB::raw('CASE WHEN dia_corte = 15 THEN 31 ELSE 15 END'),
        ]);

        Schema::table('configuracion_fechas', function (Blueprint $table): void {
            $table->integer('dia_corte_2')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_fechas', function (Blueprint $table): void {
            $table->dropColumn('dia_corte_2');
        });
    }
};
