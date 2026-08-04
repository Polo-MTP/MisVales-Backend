<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DistribuidoraEstadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $estado = $this->input('estado');
        $permisos = [
            'EN_VERIFICACION' => $user->hasRole('verificador'),
            'RECHAZADO'       => $user->hasAnyRole(['verificador', 'gerente-sucursal']),
            'ACTIVO'          => $user->hasAnyRole(['gerente-sucursal', 'Gerente General']),
            'MOROSO'          => $user->hasAnyRole(['gerente-sucursal', 'Gerente General']),
        ];
        return $permisos[$estado] ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', 'string', Rule::in(['EN_VERIFICACION', 'RECHAZADO', 'ACTIVO', 'MOROSO'])],
            'motivo' => 'nullable|string|max:500',
        ];
    }
}