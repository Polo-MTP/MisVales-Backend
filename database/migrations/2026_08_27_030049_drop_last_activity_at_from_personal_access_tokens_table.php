<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 'last_activity_at' en personal_access_tokens solo existía para el cierre de sesión por
 * inactividad basado en Bearer token (EnsureTokenNotIdle), que ya se quitó -- la sesión del
 * SPA se autentica por cookie httpOnly desde hace rato y ese rastro de actividad vivía en el
 * store de sesión de Laravel, no en esta columna. Queda huérfana, nadie la lee ni la escribe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->timestamp('last_activity_at')->nullable()->after('last_used_at');
        });
    }
};
