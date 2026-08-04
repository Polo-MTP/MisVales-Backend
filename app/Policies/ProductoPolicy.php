<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Producto;

final class ProductoPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Producto $producto): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('gerente-general');
    }

    public function update(User $user, Producto $producto): bool
    {
        return $user->hasRole('gerente-general');
    }

    public function delete(User $user, Producto $producto): bool
    {
        return $user->hasRole('gerente-general');
    }
}