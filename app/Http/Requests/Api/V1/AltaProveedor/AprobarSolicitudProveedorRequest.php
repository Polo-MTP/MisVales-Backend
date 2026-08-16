<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\AltaProveedor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class AprobarSolicitudProveedorRequest extends FormRequest
{
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
            'decision' => ['required', 'string', 'in:aprobado,rechazado'],
            'comentario_gerente' => ['nullable', 'string'],
            'limite_credito_asignado' => ['required_if:decision,aprobado', 'nullable', 'numeric', 'min:0'],
            'email' => ['required_if:decision,aprobado', 'nullable', 'email', 'unique:users,email'],
            'password' => ['required_if:decision,aprobado', 'nullable', Password::defaults()],
            'dispositivo' => ['nullable', 'string', 'max:255'],
        ];
    }
}
