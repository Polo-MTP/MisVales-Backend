<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

final class DistribuidoraCreditoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && in_array($user->role->name, ['Gerente de Sucursal', 'Gerente General']);
    }

    public function rules(): array
    {
        return [
            'limite_credito' => 'required|numeric|min:0|max:99999999.99',
            'categoria_id' => 'required|exists:categorias_distribuidoras,id',
        ];
    }
}