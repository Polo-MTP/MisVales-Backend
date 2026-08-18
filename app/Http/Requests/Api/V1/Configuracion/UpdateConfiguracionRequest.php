<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Configuracion;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateConfiguracionRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado puede enviar la solicitud (la restricción de rol
     * se aplica en el controller/policy).
     */
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
            'clave' => ['required', 'string', 'max:100'],
            'valor' => ['required', 'string', 'max:255'],
            'tipo_dato' => ['required', 'string', 'in:decimal,int,boolean,string'],
        ];
    }
}
