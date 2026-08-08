<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Relacion;

use Illuminate\Foundation\Http\FormRequest;

final class ConciliarManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'relacion_id' => ['required', 'integer', 'exists:relaciones,id'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }
}
