<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_aumento_credito', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distribuidora_id')->constrained('distribuidoras')->cascadeOnDelete();
            $table->foreignId('solicitado_por')->constrained('users')->cascadeOnDelete();
            $table->decimal('limite_credito_anterior', 12, 2);
            $table->decimal('monto_solicitado', 12, 2);
            $table->decimal('monto_otorgado', 12, 2)->nullable();
            $table->string('motivo', 500)->nullable();
            $table->string('estado', 20)->default('pendiente');
            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comentario_decision', 500)->nullable();
            $table->timestamp('fecha_decision')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_aumento_credito');
    }
};
