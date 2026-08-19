<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Vale;

use Illuminate\Foundation\Http\FormRequest;

final class ValidarValeRequest extends FormRequest
{
    /**
     * Cualquier usuario autenticado puede enviarlo (el rol Cajera lo exige el middleware de ruta).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * numero_tarjeta es opcional aquí a propósito: solo es obligatorio la primera vez que se
     * valida un vale del cliente (si aún no tiene uno registrado) — esa regla vive en
     * ValeService::validar(), no aquí, porque depende de datos ya guardados del cliente.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'numero_tarjeta' => ['nullable', 'string', 'max:30'],
        ];
    }
}
