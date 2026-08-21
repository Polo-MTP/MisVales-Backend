<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            // Aparte de 'last_used_at' (que Sanctum ya pisa con "ahora" durante la propia
            // autenticación de cada petición, antes de que EnsureTokenNotIdle pueda leerlo):
            // esta es la única fuente confiable para detectar inactividad real entre peticiones.
            $table->timestamp('last_activity_at')->nullable()->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('last_activity_at');
        });
    }
};
