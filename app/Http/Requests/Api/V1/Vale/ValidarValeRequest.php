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
     * clabe es opcional aquí a propósito: solo es obligatoria la primera vez que se valida un
     * vale del cliente (si aún no tiene una registrada) — esa regla vive en
     * ValeService::validar(), no aquí, porque depende de datos ya guardados del cliente.
     * ine_verificada/comprobante_domicilio_verificado sí son siempre obligatorios: la cajera
     * debe dejar constancia explícita de qué revisó, no solo que "validó" en abstracto.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // La CLABE interbancaria mexicana siempre son 18 dígitos, formato fijo.
            'clabe' => ['nullable', 'digits:18'],
            'ine_verificada' => ['required', 'boolean'],
            'comprobante_domicilio_verificado' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'clabe.digits' => 'La CLABE interbancaria debe tener exactamente 18 dígitos.',
            'ine_verificada.required' => 'Indica si revisaste la INE del cliente.',
            'comprobante_domicilio_verificado.required' => 'Indica si revisaste el comprobante de domicilio del cliente.',
        ];
    }
}
