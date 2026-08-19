<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

final class ReasignarCoordinadorRequest extends FormRequest
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
            'coordinador_origen_id' => ['required', 'integer', 'exists:users,id'],
            'coordinador_destino_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
