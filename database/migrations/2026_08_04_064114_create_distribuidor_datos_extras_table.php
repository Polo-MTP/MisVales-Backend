<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribuidor_datos_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribuidora_id')->unique()->constrained('distribuidoras')->onDelete('cascade');
            $table->json('datos_familiares')->nullable();    // Esposo, hijos, etc.
            $table->json('datos_vehiculos')->nullable();     // Marca, modelo, placas
            $table->json('datos_vivienda')->nullable();      // Propia, rentada, Infonavit
            $table->string('referencia_laboral')->nullable(); // Referencia de donde labora
            $table->timestamps();

            $table->index('distribuidora_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribuidor_datos_extras');
    }
};