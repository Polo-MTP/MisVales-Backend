<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Rules\Recaptcha;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $mfa_method_id
 * @property string $code
 * @property string $recaptcha
 */
final class VerifyMfaRequest extends FormRequest
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
            'mfa_method_id' => ['required', 'uuid'],
            'code' => ['required', 'numeric', 'digits:6'],
            'recaptcha' => ['required', 'string', new Recaptcha()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mfa_method_id.required' => 'El ID del método es requerido.',
            'mfa_method_id.uuid' => 'El ID del método ha sido manipulado.',
            'code.required' => 'El código es obligatorio.',
            'code.numeric' => 'El código solo debe contener números.',
            'code.digits' => 'El código debe tener exactamente 6 dígitos.',
        ];
    }
}
