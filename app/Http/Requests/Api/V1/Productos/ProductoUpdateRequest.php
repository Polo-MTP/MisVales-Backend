<?php

namespace App\Http\Requests\Api\V1\Productos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductoUpdateRequest extends FormRequest
{
    /**
     * Solo el Gerente General puede editar productos del catálogo.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && $user->role->name === 'Gerente General';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('producto');
        return [
            'monto' => [
                'required',
                'numeric',
                'min:100',
                'multiple_of:100',
                Rule::unique('productos', 'monto')->ignore($id)->where(function ($query): void {
                    $this->filled('quincenas') ? $query->where('quincenas', $this->input('quincenas')) : $query->whereNull('quincenas');
                    $this->filled('variante') ? $query->where('variante', $this->input('variante')) : $query->whereNull('variante');
                }),
            ],
            'quincenas' => 'nullable|integer|min:1',
            'variante' => 'nullable|string|max:50',
            'descripcion' => 'nullable|string|max:255',
            'activo' => 'sometimes|boolean',
        ];
    }
}