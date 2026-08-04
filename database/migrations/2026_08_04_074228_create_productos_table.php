<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->decimal('monto', 10, 2);
            $table->string('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique('monto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};