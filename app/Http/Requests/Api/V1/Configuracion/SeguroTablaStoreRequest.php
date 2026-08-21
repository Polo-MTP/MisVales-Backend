<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Configuracion;

use Illuminate\Foundation\Http\FormRequest;

final class SeguroTablaStoreRequest extends FormRequest
{
    /**
     * Solo el Gerente General puede crear rangos de seguro.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->role->name === 'Gerente General';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'monto_desde' => 'required|numeric|min:0',
            'monto_hasta' => 'nullable|numeric|gte:monto_desde',
            'seguro_monto' => 'required|numeric|min:0',
            'activo' => 'sometimes|boolean',
        ];
    }
}
