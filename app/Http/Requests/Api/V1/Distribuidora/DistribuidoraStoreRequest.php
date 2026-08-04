<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

final class DistribuidoraStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && in_array($user->role->name, ['coordinador', 'gerente-sucursal', 'Gerente General']);
    }

    public function rules(): array
    {
        return [
            'sucursal_id' => 'required|exists:sucursales,id',
            'razon_social' => 'required|string|max:255',
            'rfc' => 'required|string|size:13|unique:distribuidoras,rfc',
            'coordinador_id' => 'nullable|exists:users,id',
            'datos_personales' => 'nullable|array',
            'datos_personales.nombre_representante' => 'nullable|string|max:100',
            'datos_personales.apellido_paterno' => 'nullable|string|max:100',
            'datos_personales.apellido_materno' => 'nullable|string|max:100',
            'datos_personales.curp' => 'nullable|string|size:18|unique:distribuidor_datos_personales,curp',
            'datos_personales.rfc_personal' => 'nullable|string|size:13',
            'datos_personales.fecha_nacimiento' => 'nullable|date',
            'datos_personales.calle' => 'nullable|string|max:150',
            'datos_personales.numero' => 'nullable|string|max:20',
            'datos_personales.colonia' => 'nullable|string|max:100',
            'datos_personales.cp' => 'nullable|string|max:10',
            'datos_personales.estado' => 'nullable|string|max:100',
            'datos_personales.ciudad' => 'nullable|string|max:100',
            'datos_personales.datos_familiares' => 'nullable|array',
            'datos_personales.datos_vehiculos' => 'nullable|array',
            'datos_personales.datos_vivienda' => 'nullable|array',
        ];
    }
}