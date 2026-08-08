<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convenios_bancarios', function (Blueprint $table): void {
            $table->id();
            $table->string('banco', 100);
            $table->string('convenio', 50);
            $table->string('clabe', 18);
            // null = convenio aplica a todas las sucursales
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['sucursal_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convenios_bancarios');
    }
};
