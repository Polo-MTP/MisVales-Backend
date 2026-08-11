<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

final class SolicitarEdicionClienteRequest extends FormRequest
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
            'datos_personales' => ['nullable', 'array'],
            'datos_personales.nombre' => ['sometimes', 'string', 'max:255'],
            'datos_personales.apellido_paterno' => ['sometimes', 'string', 'max:255'],
            'datos_personales.apellido_materno' => ['sometimes', 'nullable', 'string', 'max:255'],
            'datos_personales.curp' => ['sometimes', 'string', 'max:18'],
            'datos_personales.fecha_nacimiento' => ['sometimes', 'nullable', 'date'],
            'datos_personales.lugar_nacimiento' => ['sometimes', 'nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'array'],
            'direccion.calle' => ['sometimes', 'string', 'max:255'],
            'direccion.colonia' => ['sometimes', 'string', 'max:255'],
            'direccion.numero_ext' => ['sometimes', 'string', 'max:50'],
            'direccion.numero_int' => ['sometimes', 'nullable', 'string', 'max:50'],
            'direccion.codigo_postal' => ['sometimes', 'string', 'max:10'],
            'direccion.estado' => ['sometimes', 'string', 'max:255'],
            'direccion.ciudad' => ['sometimes', 'string', 'max:255'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }
}
