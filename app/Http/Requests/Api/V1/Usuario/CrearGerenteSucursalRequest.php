<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Usuario;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
            'sucursal_id' => 'required|integer|exists:sucursales,id',
        ];
    }
}
