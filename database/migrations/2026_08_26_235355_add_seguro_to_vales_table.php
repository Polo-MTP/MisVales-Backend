<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasta ahora el seguro del vale se calculaba en vivo en CADA corte (RelacionCalculoService),
 * así que un cambio en seguros_tabla afectaba retroactivamente hasta las cuotas de vales ya
 * autorizados. seguro_tabla_id/seguro_monto snapshotean, al solicitar el vale, qué rango de
 * seguro aplica y cuánto cuesta -- ese monto es el que se usa en TODOS los cortes futuros de
 * ese vale, sin importar qué cambie después en la configuración.
 *
 * Ambas columnas quedan nullable a propósito: los vales que ya existían antes de este cambio
 * nunca tuvieron un seguro asignado, y RelacionCalculoService cae de vuelta al cálculo en vivo
 * cuando seguro_monto es null -- mismo comportamiento que ya tenían, sin necesidad de inventar
 * un valor retroactivo para datos históricos. Los vales NUEVOS sí quedan obligados a traer uno
 * (ver ValeService::solicitar()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vales', function (Blueprint $table): void {
            $table->foreignId('seguro_tabla_id')->nullable()->after('producto_id')
                ->constrained('seguros_tabla')->nullOnDelete();
            $table->decimal('seguro_monto', 12, 2)->nullable()->after('seguro_tabla_id');
        });
    }

    public function down(): void
    {
        Schema::table('vales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('seguro_tabla_id');
            $table->dropColumn('seguro_monto');
        });
    }
};
