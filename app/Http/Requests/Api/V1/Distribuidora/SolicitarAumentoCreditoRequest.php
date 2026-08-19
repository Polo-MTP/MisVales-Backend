<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

final class SolicitarAumentoCreditoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'monto_solicitado' => ['required', 'numeric', 'gt:0'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }
}
