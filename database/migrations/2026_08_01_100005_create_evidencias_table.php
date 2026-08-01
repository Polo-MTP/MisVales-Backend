<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes_proveedor')->cascadeOnDelete();
            $table->string('entidad_tipo');
            $table->unsignedBigInteger('entidad_id');
            $table->string('tipo_documento'); // e.g. ine_frente, comprobante_domicilio, foto_fachada
            $table->string('url_archivo');
            $table->foreignId('subido_por')->constrained('users');
            $table->timestamp('fecha_subida');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidencias');
    }
};
