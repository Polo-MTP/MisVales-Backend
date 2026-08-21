<?php

declare(strict_types=1);

namespace App\Services\Producto;

use App\Models\Distribuidora;
use App\Models\Producto;
use Illuminate\Support\Facades\Auth;

final class ProductoService
{
    /**
     * Lista los productos del catálogo, opcionalmente solo los activos.
     *
     * Si se pasa $distribuidora, el catálogo se filtra a lo que ella puede pedir AHORA MISMO
     * (Distribuidora::montoMaximoDisponible) — crédito disponible y, si es su primer vale (o
     * el primero tras un aumento sin estrenar), el tope del 'regla_50_pct'. Es dinámico: lo
     * que ve hoy puede no ser lo que veía ayer, cambia con cada vale/corte. El staff (sin
     * $distribuidora) siempre ve el catálogo completo.
     */
    public function listar(bool $soloActivos = true, ?Distribuidora $distribuidora = null)
    {
        $query = Producto::query();
        if ($soloActivos) {
            $query->activo();
        }

        if ($distribuidora) {
            $esPrimerVale = ! $distribuidora->vales()->exists();
            $query->where('monto', '<=', $distribuidora->montoMaximoDisponible($esPrimerVale));
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
