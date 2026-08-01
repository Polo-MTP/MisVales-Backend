<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direcciones', function (Blueprint $table): void {
            $table->id();
            $table->string('calle');
            $table->string('colonia');
            $table->string('numero_ext');
            $table->string('numero_int')->nullable();
            $table->string('codigo_postal', 10);
            $table->string('estado');
            $table->string('ciudad');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direcciones');
    }
};
