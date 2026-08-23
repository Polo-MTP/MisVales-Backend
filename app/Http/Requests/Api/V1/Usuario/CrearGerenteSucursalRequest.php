<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Usuario;

use Illuminate\Foundation\Http\FormRequest;

final class CrearGerenteSucursalRequest extends FormRequest
{
    /**
     * Solo el Gerente General puede dar de alta Gerentes de Sucursal.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->role?->name === 'Gerente General';
    }

    /**
     * No se pide contraseña: se genera aleatoria y se le manda por correo al nuevo gerente, igual
     * que en CrearPersonalSucursalRequest -- quien da de alta nunca llega a conocer la contraseña
     * de otra persona.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'sucursal_id' => 'required|integer|exists:sucursales,id',
        ];
    }
}
