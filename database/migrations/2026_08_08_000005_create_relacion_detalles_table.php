<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relacion_detalles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('relacion_id')->constrained('relaciones')->cascadeOnDelete();
            $table->foreignId('vale_id')->constrained('vales')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();

            // "Pagos Realizados" tal como aparece en la relación de ejemplo: cuota_numero / cuotas_totales
            $table->unsignedSmallInteger('cuota_numero');
            $table->unsignedSmallInteger('cuotas_totales');

            $table->decimal('capital', 12, 2)->default(0);
            $table->decimal('comision', 12, 2)->default(0);
            $table->decimal('interes', 12, 2)->default(0);
            $table->decimal('seguro', 12, 2)->default(0);
            $table->decimal('recargo', 12, 2)->default(0);
            $table->decimal('pago', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->enum('estado', ['pendiente', 'pagado', 'parcial', 'vencido'])->default('pendiente');

            $table->timestamps();

            $table->index('relacion_id');
            $table->index('vale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relacion_detalles');
    }
};
