<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CLABE interbancaria (18 dígitos) a la que se transfiere el pago del vale. Se captura una
     * sola vez, la primera vez que la cajera valida un vale de ese cliente (ver
     * ValeService::validar); columna `text` porque el valor cifrado no cabe en un varchar corto.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->text('clabe')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropColumn('clabe');
        });
    }
};
