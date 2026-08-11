<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_edicion_cliente', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('solicitado_por')->constrained('users');
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->json('campos_propuestos');
            $table->string('motivo', 500);
            $table->enum('estado', ['pendiente', 'aprobada', 'rechazada', 'aplicada'])->default('pendiente');
            $table->foreignId('autorizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comentario_autorizacion', 500)->nullable();
            $table->timestamp('fecha_decision')->nullable();
            $table->timestamps();

            $table->index(['cliente_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_edicion_cliente');
    }
};
