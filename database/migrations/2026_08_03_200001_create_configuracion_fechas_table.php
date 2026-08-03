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
        Schema::create('configuracion_fechas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->onDelete('cascade');
            $table->integer('dia_corte');
            $table->integer('dia_limite_pago');
            $table->integer('dias_pago_anticipado');
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->foreignId('modificado_por')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->index(['sucursal_id', 'vigente_hasta'], 'idx_cfg_fechas_vigente');
            $table->index(['sucursal_id', 'vigente_desde', 'vigente_hasta'], 'idx_cfg_fechas_rango');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuracion_fechas');
    }
};
