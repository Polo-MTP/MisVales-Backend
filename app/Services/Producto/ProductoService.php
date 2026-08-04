<?php

namespace App\Services\Producto;

use App\Models\Producto;
use Illuminate\Support\Facades\Auth;

class ProductoService
{
    public function listar(bool $soloActivos = true)
    {
        $query = Producto::query();
        if ($soloActivos) {
            $query->activo();
        }
        return $query->orderBy('monto')->get();
    }

    public function crear(array $data): Producto
    {
        $data['created_by'] = Auth::id();
        return Producto::create($data);
    }

    public function actualizar(Producto $producto, array $data): Producto
    {
        $producto->update($data);
        return $producto;
    }

    public function desactivar(Producto $producto): void
    {
        $producto->update(['activo' => false]);
    }
}