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
            // La distribuidora puede desactivar/activar sus propios vales sin autorización.
            // Nace activo en cuanto se crea el vale o pre-vale.
            $table->boolean('activo')->default(true)->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('vales', function (Blueprint $table): void {
            $table->dropColumn('activo');
        });
    }
};
