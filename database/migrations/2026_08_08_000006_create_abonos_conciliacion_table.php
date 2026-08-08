<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abonos_conciliacion', function (Blueprint $table): void {
            $table->id();

            // Null mientras no se logre conciliar (referencia mal capturada por el cliente/banco)
            $table->foreignId('relacion_id')->nullable()->constrained('relaciones')->nullOnDelete();

            // Tal como viene del Excel del banco, sin normalizar: puede no coincidir con ninguna relación
            $table->string('referencia_leida', 40);
            $table->decimal('monto', 12, 2);
            $table->string('folio_pago', 50)->nullable();
            $table->date('fecha_pago');
            $table->time('hora_pago')->nullable();
            $table->enum('tipo_pago', ['transferencia', 'banca_en_linea', 'pago_en_ventanilla', 'otro'])->default('otro');

            $table->foreignId('convenio_bancario_id')->nullable()->constrained('convenios_bancarios')->nullOnDelete();

            $table->enum('estado', ['conciliado', 'sin_coincidencia', 'conciliado_manual'])->default('sin_coincidencia');
            $table->foreignId('conciliado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('autorizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_manual', 500)->nullable();

            // Identificador del archivo Excel subido en el que llegó este movimiento (trazabilidad de la carga)
            $table->string('lote_archivo')->nullable();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('referencia_leida');
            $table->index(['relacion_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abonos_conciliacion');
    }
};
