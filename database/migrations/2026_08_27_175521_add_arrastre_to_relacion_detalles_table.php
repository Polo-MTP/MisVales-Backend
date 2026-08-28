<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Si una cuota (quincena) de un vale se genera y la cuota ANTERIOR de ese mismo vale sigue sin
 * liquidarse, su saldo se absorbe dentro de la cuota nueva (arrastre) en vez de quedar como dos
 * deudas independientes -- la cuota vieja deja de poder pagarse por separado (ver
 * RelacionCalculoService::calcularDetalleVale()). 'arrastre' guarda cuánto se sumó por esto;
 * 'absorbida_en_detalle_id' marca, en la cuota vieja, en cuál cuota nueva quedó su saldo.
 *
 * estado pasa de enum a string (mismo criterio ya usado en otras tablas de este proyecto, ej.
 * historial_estado_distribuidora) para poder agregar el nuevo valor 'arrastrada' sin depender
 * de sintaxis específica de MySQL para alterar un ENUM -- portátil entre MySQL y SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relacion_detalles', function (Blueprint $table): void {
            $table->decimal('arrastre', 12, 2)->default(0)->after('recargo');
            $table->foreignId('absorbida_en_detalle_id')->nullable()->after('estado')
                ->constrained('relacion_detalles')->nullOnDelete();
            $table->string('estado', 20)->default('pendiente')->change();
        });
    }

    public function down(): void
    {
        Schema::table('relacion_detalles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('absorbida_en_detalle_id');
            $table->dropColumn('arrastre');
            $table->enum('estado', ['pendiente', 'pagado', 'parcial', 'vencido'])->default('pendiente')->change();
        });
    }
};
