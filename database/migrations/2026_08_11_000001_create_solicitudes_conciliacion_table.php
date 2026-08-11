<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_conciliacion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('abono_conciliacion_id')->constrained('abonos_conciliacion')->cascadeOnDelete();
            $table->foreignId('relacion_id')->constrained('relaciones');
            $table->foreignId('solicitado_por')->constrained('users');
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->string('motivo', 500);
            $table->enum('estado', ['pendiente', 'aprobada', 'rechazada', 'aplicada'])->default('pendiente');
            $table->foreignId('autorizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comentario_autorizacion', 500)->nullable();
            $table->timestamp('fecha_decision')->nullable();
            $table->timestamps();

            $table->index(['abono_conciliacion_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_conciliacion');
    }
};
