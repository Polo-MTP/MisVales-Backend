<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'referencia_pago' deja de ser única por corte -- ahora es fija por distribuidora (ver
     * RelacionCalculoService::construirReferenciaPago()), así que varias Relacion de la misma
     * distribuidora comparten a propósito el mismo valor. Se cambia la restricción unique por
     * un índice normal (sigue siendo necesario para las búsquedas de conciliación).
     */
    public function up(): void
    {
        Schema::table('relaciones', function (Blueprint $table): void {
            $table->dropUnique(['referencia_pago']);
            $table->index('referencia_pago');
        });

        // Backfill: las Relacion que ya existen guardaron su referencia con la fecha de corte
        // incluida (formato viejo) -- se recalculan al formato nuevo (fija por distribuidora)
        // para que una distribuidora vea el mismo número sin importar cuándo se generó cada corte.
        DB::table('relaciones')->orderBy('id')->chunkById(500, function ($relaciones): void {
            foreach ($relaciones as $relacion) {
                DB::table('relaciones')->where('id', $relacion->id)->update([
                    'referencia_pago' => str_pad((string) $relacion->distribuidora_id, 18, '0', STR_PAD_LEFT),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('relaciones', function (Blueprint $table): void {
            $table->dropIndex(['referencia_pago']);
            $table->unique('referencia_pago');
        });
    }
};
