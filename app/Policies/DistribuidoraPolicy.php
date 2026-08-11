<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Distribuidora;
use App\Models\User;

final class DistribuidoraPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, ['Coordinador', 'Gerente de Sucursal', 'Gerente General', 'Verificador', 'Administrador'], true);
    }

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

    public function create(User $user): bool
    {
        return in_array($user->role?->name, ['Coordinador', 'Gerente de Sucursal', 'Gerente General'], true);
    }

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

    public function delete(User $user, Distribuidora $distribuidora): bool
    {
        return $user->role?->name === 'Gerente General';
    }

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

    public function asignarCredito(User $user, Distribuidora $distribuidora): bool
    {
        $role = $user->role?->name;

        return in_array($role, ['Gerente de Sucursal', 'Gerente General'], true)
            && ($role === 'Gerente General' || $distribuidora->sucursal_id === $user->sucursal_id);
    }
}