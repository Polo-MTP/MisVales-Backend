<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Usuario;

use Illuminate\Foundation\Http\FormRequest;

final class CrearGerenteGeneralRequest extends FormRequest
{
    /**
     * Administrador y Gerente General pueden dar de alta Gerentes Generales -- Administrador
     * porque necesita poder arrancar/reponer la cadena de mando, y Gerente General porque debe
     * poder dar de alta cualquier rol de staff, incluido el suyo propio.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && in_array($user->role?->name, ['Administrador', 'Gerente General'], true);
    }

    /**
     * No se pide contraseña ni sucursal_id: la contraseña se genera aleatoria y se manda por
     * correo (igual que CrearAdministradorRequest), y un Gerente General no queda atado a una
     * sucursal específica -- ve todo, así que el controller lo asigna a la matriz.
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
