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
            'coordinador_id' => 'nullable|exists:users,id', // si no se envía, se asigna el usuario autenticado
            'datos_personales' => 'nullable|array',
            'datos_personales.nombre_representante' => 'required_with:datos_personales|string|max:100',
            'datos_personales.apellido_paterno' => 'nullable|string|max:100',
            'datos_personales.apellido_materno' => 'nullable|string|max:100',
            'datos_personales.curp' => 'nullable|string|size:18',
            // ... todos los campos que necesites
        ];
    }
}