<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecidirSolicitudEdicionClienteRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado puede enviar la decisión (la restricción de rol
     * autorizador se aplica en el Service).
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
            'decision' => ['required', 'string', Rule::in(['aprobada', 'rechazada'])],
            'comentario' => ['nullable', 'string', 'max:500'],
        ];
    }
}
