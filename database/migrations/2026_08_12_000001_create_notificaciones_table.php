<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table): void {
            $table->id();
            // Null = movimiento sin sucursal resoluble (solo lo ven Gerente General/Administrador).
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion');
            $table->string('recurso')->nullable();
            $table->timestamps();

            $table->index('sucursal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
