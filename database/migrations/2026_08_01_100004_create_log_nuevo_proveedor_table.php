<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_nuevo_proveedor', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes_proveedor')->cascadeOnDelete();
            $table->string('entidad_tipo');
            $table->unsignedBigInteger('entidad_id');
            $table->string('campo');
            $table->text('valor_anterior')->nullable();
            $table->text('valor_nuevo')->nullable();
            $table->foreignId('modificado_por')->constrained('users');
            $table->timestamp('fecha_hora');
            $table->string('dispositivo')->nullable();
            $table->enum('accion', ['creacion', 'edicion_verificador', 'aprobacion_gerente', 'rechazo_gerente']);
            $table->text('motivo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_nuevo_proveedor');
    }
};
