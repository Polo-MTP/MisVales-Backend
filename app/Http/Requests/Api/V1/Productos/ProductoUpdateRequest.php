<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Productos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductoUpdateRequest extends FormRequest
{
    /**
     * Gerente General y Gerente de Sucursal pueden editar productos del catálogo. La VPN la
     * exige la ruta (ver routes/api/v1.php), no este authorize() — es una capa de red, no de rol.
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
