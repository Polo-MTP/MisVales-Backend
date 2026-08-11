<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Vale;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SolicitarValeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'producto_id' => ['required', 'integer', 'exists:productos,id'],
            'tipo' => ['nullable', 'string', Rule::in(['pre-vale', 'vale-digital'])],
        ];
    }
}
