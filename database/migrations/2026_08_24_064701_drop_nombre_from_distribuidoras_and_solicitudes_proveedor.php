<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una distribuidora es persona física, no persona moral: no tiene un "nombre de negocio"
 * distinto de su propio nombre (datos_personales.nombre + apellidos). Guardar un "nombre"
 * aparte en distribuidoras/solicitudes_proveedor invitaba a que ambos se desincronizaran.
 * Distribuidora::getNombreAttribute() y SolicitudProveedor::getNombreAttribute() lo calculan
 * ahora a partir de datos_personales, así que todo el código que ya leía ->nombre (Resources,
 * notificaciones) sigue funcionando igual sin cambios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidoras', function (Blueprint $table): void {
            $table->dropColumn('nombre');
        });

        Schema::table('solicitudes_proveedor', function (Blueprint $table): void {
            $table->dropColumn('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidoras', function (Blueprint $table): void {
            $table->string('nombre')->nullable();
        });

        Schema::table('solicitudes_proveedor', function (Blueprint $table): void {
            $table->string('nombre')->nullable();
        });
    }
};
