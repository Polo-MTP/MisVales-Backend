<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribuidora_id')->constrained()->onDelete('cascade');
            $table->foreignId('cliente_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('monto', 12, 2);
            $table->enum('tipo', ['pre-vale', 'vale-digital'])->default('pre-vale');
            $table->enum('estado', ['solicitado', 'autorizado', 'pagado', 'vencido', 'incidencia', 'parcial'])->default('solicitado');
            $table->timestamp('fecha_solicitud')->useCurrent();
            $table->timestamp('fecha_autorizacion')->nullable();
            $table->string('numero_transferencia', 50)->nullable();
            $table->timestamps();

            $table->index(['distribuidora_id', 'estado']);
            $table->index('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vales');
    }
};