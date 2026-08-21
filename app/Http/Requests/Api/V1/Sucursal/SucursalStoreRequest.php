<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sucursal;

use Illuminate\Foundation\Http\FormRequest;

final class SucursalStoreRequest extends FormRequest
{
    /**
     * Solo el Gerente General puede dar de alta sucursales.
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
            'nombre' => 'required|string|max:100',
            'codigo' => 'required|string|max:20|unique:sucursales,codigo',
            'es_matriz' => 'sometimes|boolean',
        ];
    }
}
