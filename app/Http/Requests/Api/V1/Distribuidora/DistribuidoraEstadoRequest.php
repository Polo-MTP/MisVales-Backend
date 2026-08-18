<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DistribuidoraEstadoRequest extends FormRequest
{
    /**
     * Cada estado destino solo lo puede asignar cierto rol: EN_VERIFICACION el Verificador,
     * RECHAZADO Verificador o Gerente de Sucursal, ACTIVO/MOROSO Gerente de Sucursal o General.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $role = $user?->role?->name;
        $estado = $this->input('estado');
        $permisos = [
            'EN_VERIFICACION' => $role === 'Verificador',
            'RECHAZADO'       => in_array($role, ['Verificador', 'Gerente de Sucursal'], true),
            'ACTIVO'          => in_array($role, ['Gerente de Sucursal', 'Gerente General'], true),
            'MOROSO'          => in_array($role, ['Gerente de Sucursal', 'Gerente General'], true),
        ];
        return $permisos[$estado] ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', 'string', Rule::in(['EN_VERIFICACION', 'RECHAZADO', 'ACTIVO', 'MOROSO'])],
            'motivo' => 'nullable|string|max:500',
        ];
    }
}