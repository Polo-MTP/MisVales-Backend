<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coordinador, Verificador y Cajera necesitan quedar relacionados a un Gerente de Sucursal
 * específico (quien los dio de alta, o el que le corresponde a su sucursal cuando los da de
 * alta el Gerente General) -- antes solo existía sucursal_id, sin forma de saber a qué
 * Gerente de Sucursal reportan dentro de esa sucursal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('gerente_id')->nullable()->after('sucursal_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('gerente_id');
        });
    }
};
