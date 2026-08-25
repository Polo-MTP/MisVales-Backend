<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excedente_movimientos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distribuidora_id')->constrained('distribuidoras')->cascadeOnDelete();
            // 'generado': la relación que originó el excedente (pagaron de más). 'aplicado': la
            // relación (corte) a la que se le restó de ese saldo a favor. Puede ser una relación
            // distinta en cada caso -- no son la misma fila.
            $table->foreignId('relacion_id')->nullable()->constrained('relaciones')->nullOnDelete();

            $table->enum('tipo', ['generado', 'aplicado']);
            // Positivo para generado, negativo para aplicado -- mismo criterio de signo que
            // puntos_movimientos.cantidad.
            $table->decimal('monto', 12, 2);
            $table->string('motivo', 255)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('distribuidora_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excedente_movimientos');
    }
};
