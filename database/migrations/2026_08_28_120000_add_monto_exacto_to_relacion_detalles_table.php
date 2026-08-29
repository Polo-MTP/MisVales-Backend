<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'total' siempre se muestra ya con el piso aplicado (lo que de verdad se le cobra a la
     * distribuidora). 'monto_exacto' guarda el mismo cálculo SIN ese piso -- los centavos que
     * el ROUNDDOWN descarta de 'total' no se pierden, viajan aquí para que la siguiente cuota
     * los arrastre completos (ver RelacionCalculoService::calcularDetalleVale() y
     * RelacionEstadoService::aplicarMultaPorVencimiento()).
     */
    public function up(): void
    {
        Schema::table('relacion_detalles', function (Blueprint $table): void {
            $table->decimal('monto_exacto', 12, 2)->default(0)->after('total');
        });

        // Backfill: para las cuotas que ya existen, el monto exacto que nunca se registró es,
        // en el peor caso, igual al total ya redondeado -- no hay forma de reconstruir los
        // centavos que ya se perdieron antes de este cambio, así que se parte de ahí (no deja
        // nada peor de lo que ya estaba, y a partir de aquí ya no se vuelve a perder nada).
        DB::table('relacion_detalles')->update(['monto_exacto' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('relacion_detalles', function (Blueprint $table): void {
            $table->dropColumn('monto_exacto');
        });
    }
};
