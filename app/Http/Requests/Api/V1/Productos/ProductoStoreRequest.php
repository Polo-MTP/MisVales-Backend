<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Productos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductoStoreRequest extends FormRequest
{
    /**
     * Gerente General y Gerente de Sucursal pueden crear productos del catálogo.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && in_array($user->role->name, ['Gerente General', 'Gerente de Sucursal'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'monto' => [
                'required',
                'numeric',
                'min:100',
                'multiple_of:100',
                Rule::unique('productos', 'monto')->where(function ($query): void {
                    $this->filled('quincenas') ? $query->where('quincenas', $this->input('quincenas')) : $query->whereNull('quincenas');
                    $this->filled('variante') ? $query->where('variante', $this->input('variante')) : $query->whereNull('variante');
                }),
            ],
            'quincenas' => 'nullable|integer|min:1',
            'variante' => 'nullable|string|max:50',
            'descripcion' => 'nullable|string|max:255',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'monto.multiple_of' => 'El monto debe ser múltiplo de 100.',
            'monto.unique' => 'Ya existe un producto con este monto, número de quincenas y variante.',
        ];
    }
}
