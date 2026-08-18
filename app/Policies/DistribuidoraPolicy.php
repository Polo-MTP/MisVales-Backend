<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Distribuidora;
use App\Models\User;

final class DistribuidoraPolicy
{
    /**
     * Roles con acceso al listado de distribuidoras.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, ['Coordinador', 'Gerente de Sucursal', 'Gerente General', 'Verificador', 'Administrador'], true);
    }

    /**
     * Gerente General/Administrador ven cualquiera; el resto solo la suya (por sucursal,
     * coordinador o verificador asignado, o si sigue en verificación).
     */
    public function view(User $user, Distribuidora $distribuidora): bool
    {
        $role = $user->role?->name;

        if ($role === 'Gerente General' || $role === 'Administrador') {
            return true;
        }
        if ($role === 'Gerente de Sucursal') {
            return $distribuidora->sucursal_id === $user->sucursal_id;
        }
        if ($role === 'Coordinador') {
            return $distribuidora->coordinador_id === $user->id;
        }
        if ($role === 'Verificador') {
            return $distribuidora->verificador_id === $user->id || $distribuidora->estado === 'EN_VERIFICACION';
        }
        return false;
    }

    /**
     * Roles que pueden dar de alta una distribuidora.
     */
    public function create(User $user): bool
    {
        return in_array($user->role?->name, ['Coordinador', 'Gerente de Sucursal', 'Gerente General'], true);
    }

    /**
     * Gerente General edita cualquiera; Gerente de Sucursal solo las de su sucursal;
     * Coordinador solo las suyas y mientras sigan en captura/verificación.
     */
    public function update(User $user, Distribuidora $distribuidora): bool
    {
        $role = $user->role?->name;

        if ($role === 'Gerente General') {
            return true;
        }
        if ($role === 'Gerente de Sucursal') {
            return $distribuidora->sucursal_id === $user->sucursal_id;
        }
        if ($role === 'Coordinador') {
            return $distribuidora->coordinador_id === $user->id && in_array($distribuidora->estado, ['EN_CAPTURA', 'EN_VERIFICACION'], true);
        }
        return false;
    }

    /**
     * Solo el Gerente General puede eliminar una distribuidora.
     */
    public function delete(User $user, Distribuidora $distribuidora): bool
    {
        return $user->role?->name === 'Gerente General';
    }

    /**
     * Gerente General cambia cualquier estado; Gerente de Sucursal solo de su sucursal;
     * Verificador solo mientras la distribuidora está en captura y en su sucursal.
     */
    public function cambiarEstado(User $user, Distribuidora $distribuidora): bool
    {
        $role = $user->role?->name;

        if ($role === 'Gerente General') {
            return true;
        }
        if ($role === 'Gerente de Sucursal') {
            return $distribuidora->sucursal_id === $user->sucursal_id;
        }
        if ($role === 'Verificador') {
            return $distribuidora->estado === 'EN_CAPTURA' && $distribuidora->sucursal_id === $user->sucursal_id;
        }
        return false;
    }

    /**
     * Gerente General asigna crédito a cualquiera; Gerente de Sucursal solo a las de su sucursal.
     */
    public function asignarCredito(User $user, Distribuidora $distribuidora): bool
    {
        $role = $user->role?->name;

        return in_array($role, ['Gerente de Sucursal', 'Gerente General'], true)
            && ($role === 'Gerente General' || $distribuidora->sucursal_id === $user->sucursal_id);
    }
}