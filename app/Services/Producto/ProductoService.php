<?php

namespace App\Services\Producto;

use App\Models\Producto;
use Illuminate\Support\Facades\Auth;

class ProductoService
{
    /**
     * Lista los productos del catálogo, opcionalmente solo los activos.
     */
    public function listar(bool $soloActivos = true)
    {
        $query = Producto::query();
        if ($soloActivos) {
            $query->activo();
        }
        return $query->orderBy('monto')->get();
    }

    /**
     * Crea un producto del catálogo, asignando al usuario autenticado como creador.
     */
    public function crear(array $data): Producto
    {
        $data['created_by'] = Auth::id();
        return Producto::create($data)->fresh();
    }

    /**
     * Actualiza los datos de un producto existente.
     */
    public function actualizar(Producto $producto, array $data): Producto
    {
        $producto->update($data);
        return $producto;
    }

    /**
     * Desactiva un producto (baja lógica, no elimina el registro).
     */
    public function desactivar(Producto $producto): void
    {
        $producto->update(['activo' => false]);
    }
}