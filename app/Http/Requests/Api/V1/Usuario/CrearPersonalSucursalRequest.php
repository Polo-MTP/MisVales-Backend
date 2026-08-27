<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Usuario;

use App\Http\Requests\Api\V1\Usuario\Concerns\ValidaDatosPersonales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CrearPersonalSucursalRequest extends FormRequest
{
    use ValidaDatosPersonales;

    /**
     * Da de alta Coordinador, Verificador o Cajera. Gerente General, Administrador y Gerente
     * de Sucursal pueden usar este endpoint; la diferencia de a qué sucursal/gerente quedan
     * asignados se resuelve en el controller, no aquí.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && in_array($user->role?->name, ['Gerente General', 'Administrador', 'Gerente de Sucursal'], true);
    }

    /**
     * 'sucursal_id' y 'gerente_id' son requeridos cuando quien hace la petición es Gerente
     * General o Administrador -- ninguno de los dos está atado a una sucursal propia, así que
     * deben elegir explícitamente. Si es Gerente de Sucursal, el controller los ignora y los
     * fija a su propia sucursal/id, así que aquí basta con que sean opcionales para ese caso.
     *
     * No se pide contraseña: el controller genera una aleatoria y se la manda por correo al
     * nuevo usuario -- así quien lo da de alta nunca llega a conocer/elegir la contraseña de
     * otra persona.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $debeElegirSucursal = in_array($this->user()?->role?->name, ['Gerente General', 'Administrador'], true);

        return [
            ...$this->reglasDatosPersonales(),
            'rol' => ['required', 'string', Rule::in(['Coordinador', 'Verificador', 'Cajera'])],
            'email' => 'required|email|max:255|unique:users,email',
            'sucursal_id' => [$debeElegirSucursal ? 'required' : 'nullable', 'integer', 'exists:sucursales,id'],
            'gerente_id' => [$debeElegirSucursal ? 'required' : 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
