<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relacion_perdones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distribuidora_id')->constrained('distribuidoras')->cascadeOnDelete();
            $table->foreignId('relacion_id')->constrained('relaciones')->cascadeOnDelete();

            // Secuencia por distribuidora: 1er perdón, 2do perdón... (la 3ra relación vencida ya no se perdona)
            $table->unsignedTinyInteger('numero_perdon');
            $table->foreignId('autorizado_por')->constrained('users');
            $table->string('motivo', 500)->nullable();

            $table->timestamps();

            $table->unique('relacion_id');
            $table->index('distribuidora_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relacion_perdones');
    }
};
