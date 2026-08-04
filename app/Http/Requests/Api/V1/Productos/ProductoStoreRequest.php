<?php

namespace App\Http\Requests\Api\V1\Productos;

use Illuminate\Foundation\Http\FormRequest;

class ProductoStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && in_array($user->role->name, ['coordinador', 'gerente-sucursal', 'Gerente General']);
    }
    public function rules(): array
    {
        return [
            'monto' => 'required|numeric|min:100|multiple_of:100|unique:productos,monto',
            'descripcion' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'monto.multiple_of' => 'El monto debe ser múltiplo de 100.',
            'monto.unique' => 'Este monto ya está registrado.',
        ];
    }
}