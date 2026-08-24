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
            $table->string('modulo', 50)->default('General')->after('action');
            $table->string('nivel', 20)->default('INFO')->after('modulo');
            $table->text('descripcion')->nullable()->after('nivel');
            $table->text('user_agent')->nullable()->after('ip_address');
            $table->foreignId('sucursal_id')->nullable()->after('user_id')->constrained('sucursales')->onDelete('set null');
            $table->json('datos_adicionales')->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->dropForeign(['sucursal_id']);
            $table->dropColumn([
                'modulo',
                'nivel',
                'descripcion',
                'user_agent',
                'sucursal_id',
                'datos_adicionales',
            ]);
        });
    }
};
