<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuando un corte junta varios vales y la distribuidora los paga por separado (cada
 * transferencia con el mismo referencia_pago del corte, pero un "Concepto" distinto por vale),
 * necesitamos saber a cuál RelacionDetalle (cuota) le tocó cada abono -- antes de esto un
 * abono solo se sabía "de qué corte", nunca "de qué vale dentro del corte".
 *
 * Nullable a propósito: si el corte solo tiene un vale, o la distribuidora paga todo junto
 * sin especificar concepto, el abono se sigue aplicando al corte completo como antes
 * (ver ConciliacionBancariaService::aplicarAbono).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abonos_conciliacion', function (Blueprint $table): void {
            $table->foreignId('relacion_detalle_id')->nullable()->after('relacion_id')
                ->constrained('relacion_detalles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('abonos_conciliacion', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('relacion_detalle_id');
        });
    }
};
