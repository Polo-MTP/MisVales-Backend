<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

final class StoreClienteRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado puede registrar un cliente para su distribuidora.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'curp.unique' => 'Este CURP ya está registrado con otra distribuidora u otro cliente.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'apellido_paterno' => ['required', 'string', 'max:255'],
            'apellido_materno' => ['nullable', 'string', 'max:255'],
            'curp' => ['required', 'string', 'size:18', 'unique:datos_personales,curp'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'lugar_nacimiento' => ['nullable', 'string', 'max:255'],
            'calle' => ['required', 'string', 'max:255'],
            'colonia' => ['required', 'string', 'max:255'],
            'numero_ext' => ['required', 'string', 'max:50'],
            'numero_int' => ['nullable', 'string', 'max:50'],
            'codigo_postal' => ['required', 'string', 'max:10'],
            'estado' => ['required', 'string', 'max:255'],
            'ciudad' => ['required', 'string', 'max:255'],
        ];
    }
}
