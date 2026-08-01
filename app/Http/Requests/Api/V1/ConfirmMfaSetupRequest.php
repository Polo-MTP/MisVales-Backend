<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $mfa_method_id
 * @property string $code
 */
final class ConfirmMfaSetupRequest extends FormRequest
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
            'mfa_method_id' => ['required', 'uuid', 'exists:mfa_methods,id'],
            'code' => ['required', 'numeric', 'digits:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mfa_method_id.required' => 'El ID del método MFA es obligatorio.',
            'mfa_method_id.exists' => 'El método MFA especificado no existe.',
            'code.required' => 'El código de confirmación es obligatorio.',
            'code.digits' => 'El código debe ser de 6 dígitos.',
        ];
    }
}
