<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Usuario;

use Illuminate\Foundation\Http\FormRequest;

final class ReasignarPersonalRequest extends FormRequest
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
            'gerente_origen_id' => ['required', 'integer', 'exists:users,id'],
            'gerente_destino_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
