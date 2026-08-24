<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Relacion;

use Illuminate\Foundation\Http\FormRequest;

final class LevantarQuejaAbonoRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado puede enviarlo (la pertenencia del abono a su propia
     * distribuidora se valida en el Service).
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
            'motivo' => ['required', 'string', 'max:500'],
            // Captura de pantalla de la transferencia -- opcional, mismos tipos permitidos
            // que el resto de subidas de archivos de la app (ver UploadController).
            'evidencia' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }
}
