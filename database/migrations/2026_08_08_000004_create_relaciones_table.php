<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distribuidora_id')->constrained('distribuidoras')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();

            // Referencia única de pago para conciliación bancaria (ej. id_distribuidora + id_corte)
            $table->string('referencia_pago', 30)->unique();

            $table->date('fecha_corte');
            $table->date('fecha_limite_pago');
            $table->date('fecha_pago_anticipado_desde')->nullable();
            $table->date('fecha_pago_anticipado_hasta')->nullable();

            // Snapshots: la relación no debe cambiar si luego se edita el crédito o la categoría de la distribuidora
            $table->decimal('limite_credito_snapshot', 12, 2);
            $table->foreignId('categoria_id_snapshot')->nullable()->constrained('categorias_distribuidoras')->nullOnDelete();
            $table->decimal('porcentaje_comision_snapshot', 5, 2)->nullable();

            $table->decimal('total_capital', 12, 2)->default(0);
            $table->decimal('total_comision', 12, 2)->default(0);
            $table->decimal('total_interes', 12, 2)->default(0);
            $table->decimal('total_seguro', 12, 2)->default(0);
            $table->decimal('total_recargos', 12, 2)->default(0);
            $table->decimal('total_a_pagar', 12, 2)->default(0);
            $table->decimal('total_abonado', 12, 2)->default(0);

            $table->integer('puntos_generados')->default(0);

            $table->enum('estado', ['pendiente', 'parcial', 'liquidada', 'vencida', 'perdonada', 'en_perdida'])
                ->default('pendiente');

            $table->foreignId('generada_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['distribuidora_id', 'estado']);
            $table->index('fecha_corte');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relaciones');
    }
};
