<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historial_estado_distribuidora', function (Blueprint $table) {
            // Cambiar de boolean a string
            $table->string('estado_anterior', 50)->nullable()->change();
            $table->string('estado_nuevo', 50)->change();
            // Agregar índice si no existe
            $table->index(['distribuidora_id', 'estado_nuevo'], 'idx_hist_estado_nuevo');
        });
    }

    public function down(): void
    {
        Schema::table('historial_estado_distribuidora', function (Blueprint $table) {
            $table->boolean('estado_anterior')->nullable()->change();
            $table->boolean('estado_nuevo')->change();
            $table->dropIndex('idx_hist_estado_nuevo');
        });
    }
};