<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

final class DistribuidoraCreditoRequest extends FormRequest
{
    /**
     * Solo Gerente de Sucursal o Gerente General pueden asignar crédito/categoría.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && in_array($user->role->name, ['Gerente de Sucursal', 'Gerente General']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'limite_credito' => 'required|numeric|min:0|max:99999999.99',
            'categoria_id' => 'required|exists:categorias_distribuidoras,id',
        ];
    }
}