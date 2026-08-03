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
        Schema::create('distribuidoras', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->unique()->constrained('users')->onDelete('cascade');
            $table->string('numero_distribuidora')->unique();
            $table->decimal('limite_credito', 12, 2)->default(0.00);
            $table->decimal('credito_disponible', 12, 2)->default(0.00);
            $table->unsignedBigInteger('categoria_id')->nullable();
            $table->integer('puntos_acumulados')->default(0);
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distribuidoras');
    }
};
