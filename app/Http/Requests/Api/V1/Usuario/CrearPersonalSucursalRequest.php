<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Usuario;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class CrearPersonalSucursalRequest extends FormRequest
{
    /**
     * Da de alta Coordinador, Verificador o Cajera. Gerente General y Gerente de Sucursal
     * pueden usar este endpoint; la diferencia de a qué sucursal/gerente quedan asignados
     * se resuelve en el controller, no aquí.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && in_array($user->role?->name, ['Gerente General', 'Gerente de Sucursal'], true);
    }

    /**
     * 'sucursal_id' y 'gerente_id' son requeridos solo cuando quien hace la petición es
     * Gerente General -- si es Gerente de Sucursal, el controller los ignora y los fija a su
     * propia sucursal/id, así que aquí basta con que sean opcionales para ese caso.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $esGerenteGeneral = $this->user()?->role?->name === 'Gerente General';

        return [
            'rol' => ['required', 'string', Rule::in(['Coordinador', 'Verificador', 'Cajera'])],
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
            'sucursal_id' => [$esGerenteGeneral ? 'required' : 'nullable', 'integer', 'exists:sucursales,id'],
            'gerente_id' => [$esGerenteGeneral ? 'required' : 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
