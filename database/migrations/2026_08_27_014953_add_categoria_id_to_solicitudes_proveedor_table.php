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
            $table->foreignId('categoria_id')->nullable()->after('referencia_laboral')
                ->constrained('categorias_distribuidoras')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_proveedor', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('categoria_id');
        });
    }
};
