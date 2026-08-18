<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DistribuidoraUpdateRequest extends FormRequest
{
    /**
     * Solo Coordinador, Gerente de Sucursal o Gerente General pueden editar una distribuidora.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && in_array($user->role->name, ['Coordinador', 'Gerente de Sucursal', 'Gerente General']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('distribuidora')?->id;

        return [
            'razon_social' => 'sometimes|string|max:255',
            'rfc' => ['sometimes', 'string', 'size:13', Rule::unique('distribuidoras', 'rfc')->ignore($id)],
            'sucursal_id' => 'sometimes|exists:sucursales,id',
            'coordinador_id' => 'sometimes|exists:users,id',
            'comentarios_verificador' => 'nullable|string|max:500',
            'datos_personales' => 'nullable|array',
            'datos_personales.nombre' => 'nullable|string|max:255',
            'datos_personales.apellido_paterno' => 'nullable|string|max:255',
            'datos_personales.apellido_materno' => 'nullable|string|max:255',
            'datos_personales.fecha_nacimiento' => 'nullable|date',
            'datos_personales.lugar_nacimiento' => 'nullable|string|max:255',
            'datos_extras' => 'nullable|array',
            'datos_extras.datos_familiares' => 'nullable|array',
            'datos_extras.datos_vehiculos' => 'nullable|array',
            'datos_extras.datos_vivienda' => 'nullable|array',
            'datos_extras.referencia_laboral' => 'nullable|string|max:255',
        ];
    }
}
