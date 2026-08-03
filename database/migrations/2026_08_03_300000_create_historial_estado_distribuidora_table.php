<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('historial_estado_distribuidora', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distribuidora_id')->constrained('distribuidoras')->onDelete('cascade');
            $table->boolean('estado_anterior');
            $table->boolean('estado_nuevo');
            $table->text('motivo');
            $table->foreignId('cambiado_por')->constrained('users')->onDelete('cascade');
            $table->dateTime('fecha');
            $table->timestamps();

            $table->index(['distribuidora_id', 'fecha'], 'idx_hist_est_dist_fecha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('historial_estado_distribuidora');
    }
};
