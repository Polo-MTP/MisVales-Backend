<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Configuracion;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateConfiguracionFechasRequest extends FormRequest
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
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'dia_corte' => ['required', 'integer', 'between:1,31'],
            'dia_limite_pago' => ['required', 'integer', 'between:1,31'],
            'dias_pago_anticipado' => ['required', 'integer', 'between:0,30'],
        ];
    }
}
