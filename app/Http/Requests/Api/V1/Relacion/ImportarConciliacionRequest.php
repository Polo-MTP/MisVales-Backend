<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Relacion;

use Illuminate\Foundation\Http\FormRequest;

final class ImportarConciliacionRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado puede importar el archivo de conciliación.
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
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'convenio_bancario_id' => ['nullable', 'integer', 'exists:convenios_bancarios,id'],
        ];
    }
}
