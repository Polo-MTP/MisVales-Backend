<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyEmailRequest extends FormRequest
{
    /**
     * Solo el propio usuario autenticado puede verificar su email (el ID de ruta debe coincidir).
     */
    public function authorize(): bool
    {
        return $this->user() && (int) $this->route('id') === $this->user()->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
