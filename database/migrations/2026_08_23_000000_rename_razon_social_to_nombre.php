<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Razón social" es exclusivo de persona moral en México; estas distribuidoras se dan
 * de alta como persona física (RFC de 13 caracteres, con CURP), así que el campo
 * correcto es "nombre".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidoras', function (Blueprint $table): void {
            $table->renameColumn('razon_social', 'nombre');
        });

        Schema::table('solicitudes_proveedor', function (Blueprint $table): void {
            $table->renameColumn('razon_social', 'nombre');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidoras', function (Blueprint $table): void {
            $table->renameColumn('nombre', 'razon_social');
        });

        Schema::table('solicitudes_proveedor', function (Blueprint $table): void {
            $table->renameColumn('nombre', 'razon_social');
        });
    }
};
