<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El monto del seguro "varia segun la cantidad" (documento de análisis) -> tabla de rangos configurable,
        // en vez de un monto fijo hardcodeado.
        Schema::create('seguros_tabla', function (Blueprint $table): void {
            $table->id();
            $table->decimal('monto_desde', 12, 2);
            $table->decimal('monto_hasta', 12, 2)->nullable();
            $table->decimal('seguro_monto', 12, 2);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['activo', 'monto_desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seguros_tabla');
    }
};
