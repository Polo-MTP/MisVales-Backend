<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sucursal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SucursalUpdateRequest extends FormRequest
{
    /**
     * Solo el Gerente General puede editar sucursales.
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
        $id = $this->route('sucursal');

        return [
            'nombre' => 'required|string|max:100',
            'codigo' => ['required', 'string', 'max:20', Rule::unique('sucursales', 'codigo')->ignore($id)],
            'es_matriz' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
