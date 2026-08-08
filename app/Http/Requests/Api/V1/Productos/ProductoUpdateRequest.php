<?php

namespace App\Http\Requests\Api\V1\Productos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductoUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && $user->role->name === 'Gerente General';
    }
    public function rules(): array
    {
        $id = $this->route('producto');
        return [
            'monto' => [
                'required',
                'numeric',
                'min:100',
                'multiple_of:100',
                Rule::unique('productos', 'monto')->ignore($id),
            ],
            'descripcion' => 'nullable|string|max:255',
            'activo' => 'sometimes|boolean',
        ];
    }
}