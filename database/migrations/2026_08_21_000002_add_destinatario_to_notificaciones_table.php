<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificaciones', function (Blueprint $table): void {
            // 'user_id' ya existía pero era el actor (quien generó la acción) -- nunca "para
            // quién es este aviso". Sin 'destinatario_id' no había forma de que Distribuidora,
            // Verificador, Coordinador o Cajera vieran solo lo suyo (y de hecho ni siquiera
            // tenían acceso a GET /notificaciones).
            $table->foreignId('destinatario_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('leido_at')->nullable()->after('recurso');

            $table->index('destinatario_id');
        });
    }

    public function down(): void
    {
        Schema::table('notificaciones', function (Blueprint $table): void {
            $table->dropForeign(['destinatario_id']);
            $table->dropColumn(['destinatario_id', 'leido_at']);
        });
    }
};
