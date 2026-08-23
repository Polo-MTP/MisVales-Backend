<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Rules\Recaptcha;
use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    /**
     * Cualquier visitante puede solicitar el restablecimiento de contraseña.
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
            // Sin 'exists:users,email' a propósito: que la validación falle solo para correos no
            // registrados es un oráculo de enumeración de cuentas (ver auditoría de seguridad).
            // El controller responde siempre el mismo mensaje genérico, exista o no la cuenta.
            'email' => ['required', 'email'],
            'recaptcha' => ['required', 'string', new Recaptcha()],
        ];
    }
}
