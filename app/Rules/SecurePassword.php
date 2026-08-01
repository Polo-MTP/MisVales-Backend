<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class SecurePassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('La contraseña debe ser una cadena de texto.');

            return;
        }

        if (! preg_match('/[a-z]/', $value)) {
            $fail('La contraseña debe contener al menos una letra minúscula.');

            return;
        }

        if (! preg_match('/[A-Z]/', $value)) {
            $fail('La contraseña debe contener al menos una letra mayúscula.');

            return;
        }

        if (! preg_match('/\d/', $value)) {
            $fail('La contraseña debe contener al menos un número.');

            return;
        }

        if (! preg_match('/[!@#$%^&*()\-_=+]/', $value)) {
            $fail('La contraseña debe contener al menos un carácter especial (ej. !, @, #, $, %, etc.).');

            return;
        }
    }
}
