<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Producto;

final class ProductoPolicy
{
    /**
     * Cualquier usuario autenticado puede listar productos.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Cualquier usuario autenticado puede ver el detalle de un producto.
     */
    public function view(User $user, Producto $producto): bool
    {
        return true;
    }

    /**
     * Solo el Gerente General puede crear productos.
     */
    public function create(User $user): bool
    {
        return $user->role?->name === 'Gerente General';
    }

    /**
     * Solo el Gerente General puede editar productos.
     */
    public function update(User $user, Producto $producto): bool
    {
        return $user->role?->name === 'Gerente General';
    }

    /**
     * Solo el Gerente General puede desactivar productos.
     */
    public function delete(User $user, Producto $producto): bool
    {
        return $user->role?->name === 'Gerente General';
    }
}