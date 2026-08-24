<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captura de pantalla de la transferencia que la distribuidora adjunta al levantar una
 * queja (ver ConciliacionBancariaService::levantarQueja) -- opcional, ayuda a la cajera
 * a ver de un vistazo qué dato no coincide sin tener que pedirlo aparte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abonos_conciliacion', function (Blueprint $table): void {
            $table->string('queja_evidencia_url', 500)->nullable()->after('queja_fecha');
        });
    }

    public function down(): void
    {
        Schema::table('abonos_conciliacion', function (Blueprint $table): void {
            $table->dropColumn('queja_evidencia_url');
        });
    }
};
