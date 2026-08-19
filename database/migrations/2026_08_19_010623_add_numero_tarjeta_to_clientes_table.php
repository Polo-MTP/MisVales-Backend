<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Número de tarjeta/cuenta al que se le transfiere el pago del vale. Se captura una sola
     * vez, la primera vez que la cajera valida un vale de ese cliente (ver ValeService::validar);
     * columna grande y sin formato fijo a propósito, porque en la práctica puede ser tarjeta
     * (16 dígitos) o CLABE interbancaria (18 dígitos).
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->text('numero_tarjeta')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropColumn('numero_tarjeta');
        });
    }
};
