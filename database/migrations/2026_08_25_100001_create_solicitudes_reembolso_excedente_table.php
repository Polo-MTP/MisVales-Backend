<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_reembolso_excedente', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vale_id')->constrained('vales')->cascadeOnDelete();
            // Denormalizado a propósito (igual que en excedente_movimientos): filtrar/listar por
            // distribuidora o por sucursal sin tener que pasar por un join a vales cada vez.
            $table->foreignId('distribuidora_id')->constrained('distribuidoras')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();

            // Snapshot de vale.saldo_excedente al momento de solicitar -- decidir() reembolsa lo
            // que REALMENTE haya en ese momento, no este valor (pudo cambiar entre solicitud y
            // decisión), pero se guarda para que quien autoriza sepa cuánto esperar ver.
            $table->decimal('monto', 12, 2);

            $table->foreignId('solicitado_por')->constrained('users');
            $table->string('motivo', 500)->nullable();
            $table->string('estado', 20)->default('pendiente'); // pendiente, aprobada, rechazada
            $table->foreignId('autorizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comentario_autorizacion', 500)->nullable();
            $table->timestamp('fecha_decision')->nullable();

            $table->timestamps();

            $table->index(['vale_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_reembolso_excedente');
    }
};
