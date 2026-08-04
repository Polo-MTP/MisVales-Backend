<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Distribuidora;
use App\Models\User;

final class DistribuidoraPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['coordinador', 'gerente-sucursal', 'gerente-general', 'verificador', 'administrador']);
    }

    public function view(User $user, Distribuidora $distribuidora): bool
    {
        if ($user->hasRole('gerente-general') || $user->hasRole('administrador')) {
            return true;
        }
        if ($user->hasRole('gerente-sucursal')) {
            return $distribuidora->sucursal_id === $user->sucursal_id;
        }
        if ($user->hasRole('coordinador')) {
            return $distribuidora->coordinador_id === $user->id;
        }
        if ($user->hasRole('verificador')) {
            return $distribuidora->verificador_id === $user->id || $distribuidora->estado === 'EN_VERIFICACION';
        }
        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['coordinador', 'gerente-sucursal', 'gerente-general']);
    }

    public function update(User $user, Distribuidora $distribuidora): bool
    {
        if ($user->hasRole('gerente-general')) {
            return true;
        }
        if ($user->hasRole('gerente-sucursal')) {
            return $distribuidora->sucursal_id === $user->sucursal_id;
        }
        if ($user->hasRole('coordinador')) {
            return $distribuidora->coordinador_id === $user->id && in_array($distribuidora->estado, ['EN_CAPTURA', 'EN_VERIFICACION']);
        }
        return false;
    }

    public function delete(User $user, Distribuidora $distribuidora): bool
    {
        return $user->hasRole('gerente-general');
    }

    public function cambiarEstado(User $user, Distribuidora $distribuidora): bool
    {
        if ($user->hasRole('gerente-general')) {
            return true;
        }
        if ($user->hasRole('gerente-sucursal')) {
            return $distribuidora->sucursal_id === $user->sucursal_id;
        }
        if ($user->hasRole('verificador')) {
            return $distribuidora->estado === 'EN_CAPTURA' && $distribuidora->sucursal_id === $user->sucursal_id;
        }
        return false;
    }

    public function asignarCredito(User $user, Distribuidora $distribuidora): bool
    {
        return $user->hasAnyRole(['gerente-sucursal', 'gerente-general'])
            && ($user->hasRole('gerente-general') || $distribuidora->sucursal_id === $user->sucursal_id);
    }
}