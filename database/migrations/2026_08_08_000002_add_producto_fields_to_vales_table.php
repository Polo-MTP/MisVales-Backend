<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vales', function (Blueprint $table): void {
            $table->foreignId('producto_id')->nullable()->after('cliente_id')->constrained('productos')->nullOnDelete();
            // Snapshot de quincenas al momento del alta: si el catálogo cambia después, este vale no debe recalcular distinto.
            $table->unsignedSmallInteger('quincenas')->nullable()->after('producto_id');
        });
    }

    public function down(): void
    {
        Schema::table('vales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('producto_id');
            $table->dropColumn('quincenas');
        });
    }
};
