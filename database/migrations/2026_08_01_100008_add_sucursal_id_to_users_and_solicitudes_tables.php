<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('sucursal_id')->nullable()->after('role_id')->constrained('sucursales')->nullOnDelete();
        });

        Schema::table('solicitudes_proveedor', function (Blueprint $table): void {
            $table->foreignId('sucursal_id')->nullable()->after('datos_id')->constrained('sucursales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['sucursal_id']);
            $table->dropColumn('sucursal_id');
        });

        Schema::table('solicitudes_proveedor', function (Blueprint $table): void {
            $table->dropForeign(['sucursal_id']);
            $table->dropColumn('sucursal_id');
        });
    }
};
