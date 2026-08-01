<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('type');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('mfa_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('mfa_type_id')->constrained('mfa_types')->onDelete('restrict');
            $table->string('secret', 512);
            $table->integer('factor_step');
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('mfa_verifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('mfa_method_id')->constrained('mfa_methods')->onDelete('cascade');
            $table->string('code_hash');
            $table->boolean('used')->default(false);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_verifications');
        Schema::dropIfExists('mfa_methods');
        Schema::dropIfExists('mfa_types');
    }
};
