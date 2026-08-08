<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use App\Models\Cliente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'datos_personales.curp.unique' => 'Este CURP ya está registrado con otra distribuidora u otro cliente.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $datosId = Cliente::query()->find($this->route('id'))?->datos_id;

        return [
            'datos_personales' => ['nullable', 'array'],
            'datos_personales.nombre' => ['nullable', 'string', 'max:255'],
            'datos_personales.apellido_paterno' => ['nullable', 'string', 'max:255'],
            'datos_personales.apellido_materno' => ['nullable', 'string', 'max:255'],
            'datos_personales.curp' => ['nullable', 'string', 'size:18', Rule::unique('datos_personales', 'curp')->ignore($datosId)],
            'datos_personales.fecha_nacimiento' => ['nullable', 'date'],
            'datos_personales.lugar_nacimiento' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'array'],
            'direccion.calle' => ['nullable', 'string', 'max:255'],
            'direccion.colonia' => ['nullable', 'string', 'max:255'],
            'direccion.numero_ext' => ['nullable', 'string', 'max:50'],
            'direccion.numero_int' => ['nullable', 'string', 'max:50'],
            'direccion.codigo_postal' => ['nullable', 'string', 'max:10'],
            'direccion.estado' => ['nullable', 'string', 'max:255'],
            'direccion.ciudad' => ['nullable', 'string', 'max:255'],
        ];
    }
}
