<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direcciones', function (Blueprint $table): void {
            $table->decimal('latitud', 10, 7)->nullable()->after('ciudad');
            $table->decimal('longitud', 10, 7)->nullable()->after('latitud');
        });
    }

    public function down(): void
    {
        Schema::table('direcciones', function (Blueprint $table): void {
            $table->dropColumn(['latitud', 'longitud']);
        });
    }
};
