<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\AltaProveedor;

use Illuminate\Foundation\Http\FormRequest;

final class SubirEvidenciaRequest extends FormRequest
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
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'tipo_documento' => ['required', 'string', 'max:100'],
        ];
    }
}
