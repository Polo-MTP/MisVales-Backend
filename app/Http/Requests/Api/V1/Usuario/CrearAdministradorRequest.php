<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Usuario;

use Illuminate\Foundation\Http\FormRequest;

final class CrearAdministradorRequest extends FormRequest
{
    /**
     * Gerente General y Gerente de Sucursal pueden dar de alta Administradores -- la
     * restricción real de "solo por red interna" la aplica el middleware 'vpn' en la ruta,
     * no este authorize() (mismo patrón que el resto de altas/decisiones que exigen VPN).
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && in_array($user->role?->name, ['Gerente General', 'Gerente de Sucursal'], true);
    }

    /**
     * No se pide contraseña ni sucursal_id: la contraseña se genera aleatoria y se manda por
     * correo (igual que CrearGerenteSucursalRequest), y un Administrador no queda atado a una
     * sucursal específica -- ve todo, igual que Gerente General (ver
     * UsuarioController::index()) -- así que el controller lo asigna a la matriz.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
        ];
    }
}
