<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

final class DecidirTransferenciaClienteRequest extends FormRequest
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
            'decision' => ['required', 'string', 'in:autorizada,rechazada'],
            'comentario' => ['nullable', 'string', 'max:500'],
        ];
    }
}
