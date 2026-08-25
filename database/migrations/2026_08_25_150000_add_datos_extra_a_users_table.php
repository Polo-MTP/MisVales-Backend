<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El alta de personal interno (Gerente de Sucursal, Administrador, Coordinador/Verificador/
 * Cajera, Gerente General) ahora captura el mismo expediente que la alta de una distribuidora
 * (Datos Personales + Dirección, ya cubiertos por users.datos_id -- ver DatosPersonales) más
 * RFC y referencia laboral, que para una distribuidora viven en distribuidoras.rfc y
 * solicitudes_proveedor.referencia_laboral respectivamente. El personal interno no tiene fila
 * en 'distribuidoras' ni 'solicitudes_proveedor', así que van directo en 'users'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('rfc', 13)->nullable()->unique()->after('gerente_id');
            $table->string('referencia_laboral')->nullable()->after('rfc');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['rfc', 'referencia_laboral']);
        });
    }
};
