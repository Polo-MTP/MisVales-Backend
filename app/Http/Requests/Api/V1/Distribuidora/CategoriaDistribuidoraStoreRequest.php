<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

final class CategoriaDistribuidoraStoreRequest extends FormRequest
{
    /**
     * Solo el Gerente General puede crear categorías de distribuidora.
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
        return [
            'nombre' => 'required|string|max:50|unique:categorias_distribuidoras,nombre',
            'porcentaje_comision' => 'required|numeric|min:0|max:100',
            'descripcion' => 'nullable|string',
            'activo' => 'sometimes|boolean',
        ];
    }
}
