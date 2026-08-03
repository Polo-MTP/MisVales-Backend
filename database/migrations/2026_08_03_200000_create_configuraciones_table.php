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
        Schema::create('configuraciones', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 100);
            $table->string('valor', 255);
            $table->enum('tipo_dato', ['decimal', 'int', 'boolean', 'string'])->default('string');
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->foreignId('modificado_por')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->index(['clave', 'vigente_hasta'], 'idx_cfg_vigente');
            $table->index(['clave', 'vigente_desde', 'vigente_hasta'], 'idx_cfg_rango');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
