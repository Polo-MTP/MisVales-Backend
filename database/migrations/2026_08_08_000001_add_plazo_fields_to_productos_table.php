<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table): void {
            $table->dropUnique(['monto']);
            $table->unsignedSmallInteger('quincenas')->nullable()->after('monto');
            $table->string('variante', 50)->nullable()->after('quincenas');
            $table->unique(['monto', 'quincenas', 'variante']);
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table): void {
            $table->dropUnique(['monto', 'quincenas', 'variante']);
            $table->dropColumn(['quincenas', 'variante']);
            $table->unique('monto');
        });
    }
};
