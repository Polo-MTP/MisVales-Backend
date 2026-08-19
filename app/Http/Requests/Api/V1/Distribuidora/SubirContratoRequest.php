<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

final class SubirContratoRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado puede enviarlo (el rol + VPN lo exige el middleware de ruta).
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
            'archivo' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }
}
