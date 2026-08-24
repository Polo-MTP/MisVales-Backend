<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Usuario;

use Illuminate\Foundation\Http\FormRequest;

final class CrearAdministradorRequest extends FormRequest
{
    /**
     * Solo Gerente General puede dar de alta Administradores -- un Administrador ve todo el
     * sistema sin acotar por sucursal (igual que Gerente General), así que dejar que un
     * Gerente de Sucursal cree uno sería escalar su propio alcance más allá de su sucursal.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->role?->name === 'Gerente General';
    }

    /**
     * No se pide contraseña ni sucursal_id: la contraseña se genera aleatoria y se manda por
     * correo (igual que CrearGerenteSucursalRequest), y un Administrador no queda atado a una
     * sucursal específica -- ve todo, igual que Gerente General (ver
     * UsuarioController::index()) -- así que el controller lo asigna a la matriz.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
        ];
    }
}
