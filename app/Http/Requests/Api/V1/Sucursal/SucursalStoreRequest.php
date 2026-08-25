<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sucursal;

use App\Models\Sucursal;
use Illuminate\Foundation\Http\FormRequest;

final class SucursalStoreRequest extends FormRequest
{
    /**
     * El Gerente General da de alta sucursales normales, pero solo Administrador puede dar de
     * alta la sucursal matriz -- de ahí sale la asignación automática de cualquier Gerente
     * General nuevo (ver UsuarioController::crearGerenteGeneral()), así que quien la crea debe
     * ser el mismo que controla esa cadena de mando.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        if ($this->boolean('es_matriz')) {
            return $user->role?->name === 'Administrador';
        }

        return $user->role?->name === 'Gerente General';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => 'required|string|max:100',
            'codigo' => 'required|string|max:20|unique:sucursales,codigo',
            'es_matriz' => ['sometimes', 'boolean', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->boolean('es_matriz') && Sucursal::query()->where('es_matriz', true)->exists()) {
                    $fail('Ya existe una sucursal matriz. Solo puede haber una.');
                }
            }],
        ];
    }
}
