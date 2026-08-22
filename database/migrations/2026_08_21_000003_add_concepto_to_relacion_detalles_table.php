<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Negocio pidió identificar cada vale/cuota dentro de un corte: hasta ahora, si un corte
 * juntaba varios vales de la misma distribuidora, todos compartían la MISMA referencia_pago
 * (esa sigue siendo por distribuidora+corte, sin cambios) -- no había forma de saber, dentro
 * de ese corte, a cuál vale correspondía cada transferencia si la distribuidora las pagaba
 * por separado.
 *
 * 'concepto' es ese identificador único por cuota: 5 dígitos de vale_id + 4 de cuota_numero.
 * Se lee de la columna "Concepto" del Excel del banco (ConciliacionBancariaService ya la
 * esperaba, solo no la usaba para nada) para saber qué RelacionDetalle paga cada abono dentro
 * de un corte con varios vales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relacion_detalles', function (Blueprint $table): void {
            $table->string('concepto', 20)->nullable()->after('vale_id');
        });

        // Backfill de las cuotas que ya existían antes de este cambio.
        DB::table('relacion_detalles')
            ->select('id', 'vale_id', 'cuota_numero')
            ->orderBy('id')
            ->chunkById(500, function ($filas): void {
                foreach ($filas as $fila) {
                    $concepto = sprintf('%05d%04d', $fila->vale_id, $fila->cuota_numero);
                    DB::table('relacion_detalles')->where('id', $fila->id)->update(['concepto' => $concepto]);
                }
            });

        Schema::table('relacion_detalles', function (Blueprint $table): void {
            $table->string('concepto', 20)->nullable(false)->change();
            $table->unique('concepto');
        });
    }

    public function down(): void
    {
        Schema::table('relacion_detalles', function (Blueprint $table): void {
            $table->dropUnique(['concepto']);
            $table->dropColumn('concepto');
        });
    }
};
