<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * TrimStrings/ConvertEmptyStringsToNull (Laravel) no estaban en el grupo 'api' de
 * bootstrap/app.php. Password queda excluido del trim por defecto del propio middleware
 * (nunca se debe recortar una contraseña).
 */
it('recorta espacios al inicio/final de los campos de texto antes de validar', function (): void {
    $role = Role::firstOrCreate(['name' => 'Cajera'], ['factor_count' => 1]);
    $user = User::factory()->create(['role_id' => $role->id, 'password' => bcrypt('Passw0rd1'), 'is_active' => true]);

    // Sin TrimStrings, un email con espacios falla la regla `email` de validación
    // (filter_var rechaza direcciones con espacios) y el login nunca llega a intentarse.
    $this->postJson('/api/v1/login', [
        'email' => '  '.$user->email.'  ',
        'password' => 'Passw0rd1',
    ])->assertStatus(200);
});
