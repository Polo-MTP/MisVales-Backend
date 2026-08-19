<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La distribuidora reporta que un abono no coincide con lo que ella pagó — es solo el
     * punto de entrada informativo; la cajera sigue siendo quien de verdad dispara
     * solicitar-autorizacion/conciliar-manual (ver ConciliacionBancariaService).
     */
    public function up(): void
    {
        Schema::table('abonos_conciliacion', function (Blueprint $table): void {
            $table->foreignId('queja_por')->nullable()->after('motivo_manual')->constrained('users')->nullOnDelete();
            $table->string('queja_motivo', 500)->nullable()->after('queja_por');
            $table->timestamp('queja_fecha')->nullable()->after('queja_motivo');
        });
    }

    public function down(): void
    {
        Schema::table('abonos_conciliacion', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('queja_por');
            $table->dropColumn(['queja_motivo', 'queja_fecha']);
        });
    }
};
