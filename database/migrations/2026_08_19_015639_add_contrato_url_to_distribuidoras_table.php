<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidoras', function (Blueprint $table): void {
            $table->string('contrato_url')->nullable()->after('fecha_aprobacion');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidoras', function (Blueprint $table): void {
            $table->dropColumn('contrato_url');
        });
    }
};
