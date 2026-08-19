<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deja constancia explícita de qué documentos revisó la cajera al validar (INE y
     * comprobante de domicilio), no solo que "alguien validó en algún momento".
     */
    public function up(): void
    {
        Schema::table('vales', function (Blueprint $table): void {
            $table->boolean('ine_verificada')->nullable()->after('fecha_validacion');
            $table->boolean('comprobante_domicilio_verificado')->nullable()->after('ine_verificada');
        });
    }

    public function down(): void
    {
        Schema::table('vales', function (Blueprint $table): void {
            $table->dropColumn(['ine_verificada', 'comprobante_domicilio_verificado']);
        });
    }
};
