<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidoras', function (Blueprint $table) {
            // 1. Agregar columnas (todas nullable para evitar conflictos)
            $table->foreignId('sucursal_id')->after('id')->nullable();
            $table->foreignId('coordinador_id')->after('sucursal_id')->nullable();
            $table->foreignId('verificador_id')->nullable()->after('coordinador_id');
            $table->string('razon_social', 255)->nullable()->after('numero_distribuidora');
            $table->string('rfc', 13)->unique()->nullable()->after('razon_social');
            $table->string('usuario_acceso', 100)->unique()->nullable()->after('estado');
            $table->string('password_hash')->nullable()->after('usuario_acceso');
            $table->text('comentarios_verificador')->nullable()->after('password_hash');
            $table->timestamp('fecha_aprobacion')->nullable()->after('comentarios_verificador');
            $table->foreignId('aprobado_por')->nullable()->after('fecha_aprobacion');

            // 2. Cambiar estado de boolean a string (requiere doctrine/dbal)
            $table->string('estado', 50)->default('EN_CAPTURA')->change();

            // 3. Eliminar 'credito_disponible'
            $table->dropColumn('credito_disponible');

            // 4. Añadir índices
            $table->index(['sucursal_id', 'estado'], 'idx_sucursal_estado');
            $table->index(['coordinador_id', 'estado'], 'idx_coordinador_estado');

            // 5. Después de agregar todas las columnas, creamos las llaves foráneas
            // con nombres de tabla explícitos
            $table->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
            $table->foreign('coordinador_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('verificador_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('aprobado_por')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidoras', function (Blueprint $table) {
            // Eliminar llaves foráneas
            $table->dropForeign(['sucursal_id']);
            $table->dropForeign(['coordinador_id']);
            $table->dropForeign(['verificador_id']);
            $table->dropForeign(['aprobado_por']);

            // Eliminar columnas agregadas
            $table->dropColumn([
                'sucursal_id',
                'coordinador_id',
                'verificador_id',
                'razon_social',
                'rfc',
                'usuario_acceso',
                'password_hash',
                'comentarios_verificador',
                'fecha_aprobacion',
                'aprobado_por'
            ]);

            // Revertir estado a boolean
            $table->boolean('estado')->default(true)->change();

            // Recuperar credito_disponible
            $table->decimal('credito_disponible', 12, 2)->default(0.00)->after('limite_credito');
        });
    }
};