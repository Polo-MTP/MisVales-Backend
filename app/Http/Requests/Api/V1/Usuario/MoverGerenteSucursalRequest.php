<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Usuario;

use Illuminate\Foundation\Http\FormRequest;

final class MoverGerenteSucursalRequest extends FormRequest
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
            'sucursal_id' => 'required|integer|exists:sucursales,id',
        ];
    }
}
