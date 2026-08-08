<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puntos_movimientos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distribuidora_id')->constrained('distribuidoras')->cascadeOnDelete();
            $table->foreignId('relacion_id')->nullable()->constrained('relaciones')->nullOnDelete();

            $table->enum('tipo', ['generado', 'penalizado', 'redimido', 'ajuste_manual']);
            // Negativo para penalizado/redimido, positivo para generado
            $table->integer('cantidad');
            // Snapshot del valor configurado de 1 punto, solo aplica cuando tipo = redimido
            $table->decimal('valor_punto_snapshot', 6, 2)->nullable();
            $table->string('motivo', 255)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('distribuidora_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puntos_movimientos');
    }
};
