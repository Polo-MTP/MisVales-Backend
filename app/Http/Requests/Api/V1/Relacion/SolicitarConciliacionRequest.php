<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Relacion;

use Illuminate\Foundation\Http\FormRequest;

final class SolicitarConciliacionRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado puede solicitar la conciliación manual.
     */
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
            'relacion_id' => ['required', 'integer', 'exists:relaciones,id'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }
}
