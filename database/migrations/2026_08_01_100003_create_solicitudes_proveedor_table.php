<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_proveedor', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('datos_id')->constrained('datos_personales')->cascadeOnDelete();
            $table->enum('estado', ['pendiente_verificacion', 'en_verificacion', 'verificado', 'aprobado', 'rechazado'])
                ->default('pendiente_verificacion');
            $table->boolean('cumple')->nullable();
            $table->text('comentario_verificador')->nullable();
            $table->timestamp('fecha_verificacion')->nullable();

            $table->foreignId('coordinador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verificador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('gerente_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('comentario_gerente')->nullable();
            $table->enum('decision_gerente', ['pendiente', 'aprobado', 'rechazado'])->default('pendiente');
            $table->decimal('limite_credito_asignado', 12, 2)->nullable();
            $table->timestamp('fecha_decision')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_proveedor');
    }
};
