<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solo puede haber un Administrador en todo el sistema -- esa cuenta se provisiona fuera de la
 * app (seeder/tinker), así que las reglas normales de FormRequest no alcanzan a protegerla; la
 * única forma de que esto sea de verdad imposible de violar es un candado en la base de datos.
 *
 * Un UNIQUE simple sobre role_id no sirve (bloquearía tener más de un Cajera, más de un
 * Coordinador, etc.). En su lugar, esta columna solo se llena (con el valor fijo 1) cuando el
 * usuario ES Administrador -- para cualquier otro rol se queda en NULL, y NULL nunca cuenta
 * como duplicado en un índice único (ni en MySQL/MariaDB ni en SQLite/Postgres). El resultado:
 * como mucho una fila en toda la tabla puede tener 1 aquí, sin importar cuántos usuarios de
 * otros roles existan. El valor lo mantiene solo App\Models\User (evento 'saving'), no hay que
 * tocarlo a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('es_el_unico_administrador')->nullable()->default(null)->after('role_id');
            $table->unique('es_el_unico_administrador');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['es_el_unico_administrador']);
            $table->dropColumn('es_el_unico_administrador');
        });
    }
};
