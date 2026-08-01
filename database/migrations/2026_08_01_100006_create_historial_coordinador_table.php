<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial_coordinador', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distribuidor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('coordinador_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('fecha_inicio');
            $table->timestamp('fecha_fin')->nullable();
            $table->foreignId('asignado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_coordinador');
    }
};
