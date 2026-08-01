<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('token_hash');
            $table->integer('factors_completed')->default(1);
            $table->boolean('is_fully_authenticated')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('email_attempted', 255)->nullable();
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->string('status', 30);
            $table->integer('factor_step')->default(1);
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignUuid('session_id')->nullable()->constrained('user_sessions')->onDelete('set null');
            $table->string('action');
            $table->string('resource')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('user_sessions');
    }
};
