<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El excedente de conciliación nació amarrado a la DISTRIBUIDORA (un pozo agregado que se
 * aplicaba al total del siguiente corte, sin importar de qué cliente/vale venía). Se corrige
 * para que viva en el VALE que realmente lo generó -- así el excedente de un cliente nunca
 * termina pagando la deuda de otro cliente de la misma distribuidora, y se puede saber
 * exactamente cuándo ese saldo ya no tiene ninguna cuota futura que lo consuma (vale ya
 * 'pagado' con saldo_excedente > 0 -- ver SolicitudReembolsoExcedente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vales', function (Blueprint $table): void {
            $table->decimal('saldo_excedente', 12, 2)->default(0)->after('estado');
        });

        // Migra cualquier saldo ya acumulado en distribuidoras (entorno donde el excedente por
        // distribuidora ya se usó) a alguno de los vales activos de esa distribuidora, para no
        // perder el dinero de más -- si no tiene ningún vale con cuota pendiente, se queda sin
        // migrar (ver aviso abajo, revisar a mano si aplica).
        DB::table('distribuidoras')->where('saldo_excedente', '>', 0)->orderBy('id')->get()->each(function ($distribuidora): void {
            $vale = DB::table('vales')
                ->where('distribuidora_id', $distribuidora->id)
                ->where('activo', true)
                ->whereIn('estado', ['autorizado', 'parcial', 'vencido'])
                ->orderBy('id')
                ->first();

            if ($vale) {
                DB::table('vales')->where('id', $vale->id)->update(['saldo_excedente' => $distribuidora->saldo_excedente]);
            }
        });

        Schema::table('distribuidoras', function (Blueprint $table): void {
            $table->dropColumn('saldo_excedente');
        });

        Schema::table('excedente_movimientos', function (Blueprint $table): void {
            $table->foreignId('vale_id')->nullable()->after('relacion_id')->constrained('vales')->nullOnDelete();
            // enum -> string (mismo criterio ya usado en vales.estado): agregar 'reembolsado'
            // a un enum real depende del ALTER TYPE de cada motor de BD. La validación de
            // valores válidos vive en el servicio, no en una constraint.
            $table->string('tipo', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('excedente_movimientos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vale_id');
        });

        Schema::table('distribuidoras', function (Blueprint $table): void {
            $table->decimal('saldo_excedente', 12, 2)->default(0)->after('puntos_acumulados');
        });

        Schema::table('vales', function (Blueprint $table): void {
            $table->dropColumn('saldo_excedente');
        });
    }
};
