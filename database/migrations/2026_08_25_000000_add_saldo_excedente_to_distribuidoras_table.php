<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidoras', function (Blueprint $table): void {
            $table->decimal('saldo_excedente', 12, 2)->default(0)->after('puntos_acumulados');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidoras', function (Blueprint $table): void {
            $table->dropColumn('saldo_excedente');
        });
    }
};
