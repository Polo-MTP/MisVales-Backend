<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Relacion;

use Illuminate\Foundation\Http\FormRequest;

final class EjecutarConciliacionManualRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado puede ejecutar la conciliación (requiere una
     * solicitud ya autorizada, validado en el Service).
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
            'solicitud_id' => ['required', 'integer', 'exists:solicitudes_conciliacion,id'],
        ];
    }
}
