<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Usuario;

use App\Http\Requests\Api\V1\Usuario\Concerns\ValidaDatosPersonales;
use Illuminate\Foundation\Http\FormRequest;

final class CrearGerenteGeneralRequest extends FormRequest
{
    use ValidaDatosPersonales;

    /**
     * Solo Administrador puede dar de alta Gerentes Generales -- necesita poder arrancar/reponer
     * la cadena de mando. Gerente General NO puede crear otro Gerente General: dejarlo
     * auto-perpetuarse sin que Administrador se entere sería escalar su propio alcance sin
     * ningún control por fuera.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->role?->name === 'Administrador';
    }

    /**
     * No se pide contraseña ni sucursal_id: la contraseña se genera aleatoria y se manda por
     * correo, y un Gerente General no queda atado a una sucursal específica -- ve todo, así que
     * el controller lo asigna a la matriz. 'name' tampoco se pide directo: se calcula de
     * nombre/apellidos (ver ValidaDatosPersonales).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->reglasDatosPersonales(),
            'email' => 'required|email|max:255|unique:users,email',
        ];
    }
}
