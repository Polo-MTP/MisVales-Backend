<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ChangePasswordRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado puede cambiar su propia contraseña.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 'current_password' valida contra la contraseña del usuario autenticado del guard
            // por defecto -- necesario porque quien crea una cuenta de personal ya no elige su
            // contraseña (se genera aleatoria y se manda por correo, ver
            // UsuarioController::crearPersonalSucursal()), así que este es el único lugar donde
            // el propio usuario puede cambiarla sin depender de "olvidé mi contraseña".
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
