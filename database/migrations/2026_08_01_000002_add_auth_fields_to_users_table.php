<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->after('password')->constrained('roles')->onDelete('set null');
            $table->boolean('is_active')->default(true)->after('role_id');
            $table->boolean('is_locked')->default(false)->after('is_active');
            $table->integer('failed_attempts')->default(0)->after('is_locked');
            $table->timestamp('locked_until')->nullable()->after('failed_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
            $table->dropColumn(['role_id', 'is_active', 'is_locked', 'failed_attempts', 'locked_until']);
        });
    }
};
