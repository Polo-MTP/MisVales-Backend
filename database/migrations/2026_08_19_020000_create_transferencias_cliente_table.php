<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_transferencia_cliente', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('distribuidora_origen_id')->constrained('distribuidoras')->cascadeOnDelete();
            $table->foreignId('distribuidora_destino_id')->constrained('distribuidoras')->cascadeOnDelete();
            $table->foreignId('solicitado_por')->constrained('users')->cascadeOnDelete();
            $table->string('motivo', 500)->nullable();
            // pendiente_autorizacion -> autorizada -> aceptada (o rechazada en cualquiera de los 2 primeros pasos)
            $table->string('estado', 30)->default('pendiente_autorizacion');
            $table->foreignId('autorizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comentario_autorizacion', 500)->nullable();
            $table->timestamp('fecha_autorizacion')->nullable();
            $table->timestamp('fecha_aceptacion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_transferencia_cliente');
    }
};
