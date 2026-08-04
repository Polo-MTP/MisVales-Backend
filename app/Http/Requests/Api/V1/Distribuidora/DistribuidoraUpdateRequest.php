<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DistribuidoraUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && in_array($user->role->name, ['coordinador', 'gerente-sucursal', 'Gerente General']);
    }

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
            'datos_personales.nombre_representante' => 'nullable|string|max:100',
            // ... resto de campos igual que en Store
        ];
    }
}