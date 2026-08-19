<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega el paso 'validado' entre 'solicitado' y 'autorizado': la cajera primero valida
     * los datos del cliente en persona antes de poder autorizar/pagar el vale — no se puede
     * autorizar sin validar antes. Se cambia estado de enum a string (mismo criterio ya usado
     * en historial_estado_distribuidora) para no depender de ALTER TYPE de enum por motor de BD;
     * la validación real de transiciones vive en ValeService, no en una constraint de la BD.
     */
    public function up(): void
    {
        Schema::table('vales', function (Blueprint $table) {
            $table->string('estado', 20)->default('solicitado')->change();
            $table->foreignId('validado_por')->nullable()->after('fecha_solicitud')->constrained('users')->nullOnDelete();
            $table->timestamp('fecha_validacion')->nullable()->after('validado_por');
        });
    }

    public function down(): void
    {
        Schema::table('vales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validado_por');
            $table->dropColumn('fecha_validacion');
            $table->enum('estado', ['solicitado', 'autorizado', 'pagado', 'vencido', 'incidencia', 'parcial'])
                ->default('solicitado')
                ->change();
        });
    }
};
