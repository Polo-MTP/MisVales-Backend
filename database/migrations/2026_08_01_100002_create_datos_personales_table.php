<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datos_personales', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('apellido_paterno');
            $table->string('apellido_materno')->nullable();
            $table->string('curp', 18)->unique();
            $table->foreignId('direccion_id')->constrained('direcciones')->cascadeOnDelete();
            $table->date('fecha_nacimiento')->nullable();
            $table->string('lugar_nacimiento')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datos_personales');
    }
};
