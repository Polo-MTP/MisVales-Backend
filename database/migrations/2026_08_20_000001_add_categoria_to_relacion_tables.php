<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relacion_detalles', function (Blueprint $table): void {
            $table->decimal('categoria', 12, 2)->default(0)->after('seguro');
        });

        Schema::table('relaciones', function (Blueprint $table): void {
            $table->decimal('total_categoria', 12, 2)->default(0)->after('total_seguro');
        });
    }

    public function down(): void
    {
        Schema::table('relacion_detalles', function (Blueprint $table): void {
            $table->dropColumn('categoria');
        });

        Schema::table('relaciones', function (Blueprint $table): void {
            $table->dropColumn('total_categoria');
        });
    }
};
