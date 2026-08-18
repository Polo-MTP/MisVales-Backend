<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Rules\Recaptcha;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property int $user_id
 * @property string $code
 * @property string $recaptcha
 */
final class VerifyOtpRequest extends FormRequest
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
            'user_id' => ['required', 'integer'],
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
            'user_id.required' => 'El ID de usuario es obligatorio.',
            'code.required' => 'El código OTP es obligatorio.',
            'code.digits' => 'El código OTP debe tener exactamente 6 dígitos.',
        ];
    }
}
