<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Necesario para el nuevo estado MOROSO automático (RelacionEstadoService): ese cambio
     * de estado lo dispara el sistema (al marcar vencidas o al agotar perdones), no un usuario
     * — mismo criterio que 'generada_por' en Relacion, que ya es nullable para el mismo caso.
     */
    public function up(): void
    {
        Schema::table('historial_estado_distribuidora', function (Blueprint $table): void {
            $table->dropForeign(['cambiado_por']);
        });

        Schema::table('historial_estado_distribuidora', function (Blueprint $table): void {
            $table->foreignId('cambiado_por')->nullable()->change();
            $table->foreign('cambiado_por')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('historial_estado_distribuidora', function (Blueprint $table): void {
            $table->dropForeign(['cambiado_por']);
        });

        Schema::table('historial_estado_distribuidora', function (Blueprint $table): void {
            $table->foreignId('cambiado_por')->nullable(false)->change();
            $table->foreign('cambiado_por')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
