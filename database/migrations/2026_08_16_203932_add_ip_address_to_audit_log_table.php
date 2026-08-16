<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            // login_attempts ya capturaba Qué/Quién/Cuándo/Dónde; audit_log (creación/edición/
            // borrado de los modelos de negocio) tenía Qué/Quién/Cuándo pero no Dónde.
            $table->string('ip_address', 45)->nullable()->after('resource');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->dropColumn('ip_address');
        });
    }
};
