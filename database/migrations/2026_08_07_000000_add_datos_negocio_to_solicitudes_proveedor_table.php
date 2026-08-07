<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_proveedor', function (Blueprint $table): void {
            $table->string('razon_social', 255)->nullable()->after('datos_id');
            $table->string('rfc', 13)->nullable()->unique()->after('razon_social');
            $table->json('datos_familiares')->nullable()->after('rfc');
            $table->json('datos_vehiculos')->nullable()->after('datos_familiares');
            $table->json('datos_vivienda')->nullable()->after('datos_vehiculos');
            $table->string('referencia_laboral')->nullable()->after('datos_vivienda');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_proveedor', function (Blueprint $table): void {
            $table->dropColumn([
                'razon_social',
                'rfc',
                'datos_familiares',
                'datos_vehiculos',
                'datos_vivienda',
                'referencia_laboral',
            ]);
        });
    }
};
