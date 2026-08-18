<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Rules\Recaptcha;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $email
 * @property string $password
 * @property string $recaptcha
 */
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'recaptcha' => ['required', 'string', new Recaptcha()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El formato del correo es inválido.',
            'password.required' => 'La contraseña es obligatoria.',
            'recaptcha.required' => 'La verificación anti-robots (reCAPTCHA) es obligatoria.',
        ];
    }
}
