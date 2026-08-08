<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Relacion;

use Illuminate\Foundation\Http\FormRequest;

final class ImportarConciliacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'convenio_bancario_id' => ['nullable', 'integer', 'exists:convenios_bancarios,id'],
        ];
    }
}
