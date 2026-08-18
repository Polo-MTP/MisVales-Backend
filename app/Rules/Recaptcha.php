<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Valida un token de reCAPTCHA contra el endpoint de verificación de Google.
 * Se omite en local/testing si no hay clave secreta configurada o se envía 'bypass-recaptcha'.
 */
final class Recaptcha implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // En desarrollo/testing si no hay clave secreta o se pasa 'bypass', omitimos
        if (app()->environment('local', 'testing') && (empty(config('services.recaptcha.secret')) || $value === 'bypass-recaptcha')) {
            return;
        }

        try {
            $response = Http::asForm()
                ->withoutVerifying()
                ->timeout(5)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => config('services.recaptcha.secret'),
                    'response' => $value,
                ]);
        } catch (Throwable) {
            $fail('No se pudo verificar el reCAPTCHA debido a problemas de conexión con el servicio de verificación.');

            return;
        }

        /** @var array{success?: bool, score?: float} $body */
        $body = $response->json() ?? [];

        if (! isset($body['success']) || ! $body['success']) {
            $fail('La verificación anti-robots (reCAPTCHA) ha fallado. Intenta de nuevo.');

            return;
        }

        if (isset($body['score']) && $body['score'] < 0.5) {
            $fail('Se detectó comportamiento inusual. No has pasado la prueba de reCAPTCHA.');
        }
    }
}
