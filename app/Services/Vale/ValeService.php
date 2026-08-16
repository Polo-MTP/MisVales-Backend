<?php

declare(strict_types=1);

namespace App\Services\Vale;

use App\Models\Cliente;
use App\Models\Distribuidora;
use App\Models\Producto;
use App\Models\User;
use App\Models\Vale;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ValeService
{
    /**
     * Registra la solicitud de un vale: la Distribuidora elige un cliente propio y un
     * producto del catálogo activo. Queda en estado 'solicitado' hasta que alguien con
     * autoridad (Coordinador/Gerente) lo autorice — solo entonces cuenta contra el crédito
     * disponible y entra en el cálculo de cortes (RelacionCalculoService).
     *
     * @param  array<string, mixed>  $data
     */
    public function solicitar(array $data, User $usuario): Vale
    {
        $distribuidora = $usuario->distribuidora;

        if (! $distribuidora) {
            abort(403, 'Este usuario no tiene una distribuidora asociada.');
        }

        /** @var Cliente $cliente */
        $cliente = Cliente::query()->findOrFail($data['cliente_id']);

        $perteneceADistribuidora = $cliente->historialDistribuidoras()
            ->where('distribuidor_id', $distribuidora->id)
            ->whereNull('fecha_fin')
            ->exists();

        if (! $perteneceADistribuidora) {
            abort(403, 'Este cliente no está asignado a tu distribuidora.');
        }

        /** @var Producto $producto */
        $producto = Producto::query()->where('activo', true)->findOrFail($data['producto_id']);

        $esPrimerVale = ! $distribuidora->vales()->exists();

        if (! $distribuidora->puedeSolicitarVale((float) $producto->monto, $esPrimerVale)) {
            abort(422, 'La distribuidora no cumple las condiciones para solicitar este vale (crédito disponible insuficiente o límite del primer vale excedido).');
        }

        return DB::transaction(function () use ($distribuidora, $cliente, $producto, $data): Vale {
            /** @var Vale $vale */
            $vale = Vale::query()->create([
                'distribuidora_id' => $distribuidora->id,
                'cliente_id' => $cliente->id,
                'producto_id' => $producto->id,
                'monto' => $producto->monto,
                'quincenas' => $producto->quincenas,
                'tipo' => $data['tipo'] ?? 'pre-vale',
                'estado' => 'solicitado',
                'fecha_solicitud' => now(),
            ]);

            return $vale->fresh(['distribuidora', 'cliente.datosPersonales', 'producto']);
        });
    }

    /**
     * Autoriza un vale solicitado: a partir de aquí cuenta contra el crédito disponible
     * de la distribuidora y queda elegible para el corte (RelacionCalculoService).
     */
    public function autorizar(Vale $vale, User $usuario): Vale
    {
        if ($vale->estado !== 'solicitado') {
            abort(422, "Solo se pueden autorizar vales en estado 'solicitado' (actual: {$vale->estado}).");
        }

        $vale->estado = 'autorizado';
        $vale->fecha_autorizacion = now();
        $vale->save();

        return $vale->fresh(['distribuidora', 'cliente.datosPersonales', 'producto']);
    }

    /**
     * La distribuidora puede desactivar/activar sus propios vales libremente, sin autorización
     * de nadie más, pero solo mientras el vale sigue en 'solicitado' (aún no cuenta contra el
     * crédito ni entra a un corte). Una vez autorizado, pagado, vencido o parcial, el vale ya
     * está comprometido: desactivarlo no debe poder "liberar" ese crédito artificialmente.
     */
    public function desactivar(Vale $vale, User $usuario): Vale
    {
        $this->verificarPropiedad($vale, $usuario);

        if ($vale->estado !== 'solicitado') {
            abort(422, "Solo se pueden desactivar vales en estado 'solicitado' (actual: {$vale->estado}). Un vale autorizado, pagado, vencido o parcial ya cuenta contra el crédito y no puede desactivarse desde aquí.");
        }

        $vale->update(['activo' => false]);

        return $vale->fresh(['distribuidora', 'cliente.datosPersonales', 'producto']);
    }

    public function activar(Vale $vale, User $usuario): Vale
    {
        $this->verificarPropiedad($vale, $usuario);

        if ($vale->estado !== 'solicitado') {
            abort(422, "Solo se pueden reactivar vales en estado 'solicitado' (actual: {$vale->estado}).");
        }

        $vale->update(['activo' => true]);

        return $vale->fresh(['distribuidora', 'cliente.datosPersonales', 'producto']);
    }

    /**
     * Lista vales. Para el rol Distribuidora, solo los suyos; para Cajera y Gerente de Sucursal,
     * solo los de distribuidoras de su propia sucursal; para Coordinador/Gerente General, todos
     * (opcionalmente filtrados por distribuidora_id/estado).
     *
     * @param  array<string, mixed>  $filters
     */
    public function listar(User $usuario, array $filters = []): LengthAwarePaginator
    {
        $query = Vale::query()->with(['distribuidora', 'cliente.datosPersonales', 'producto']);

        $role = $usuario->role?->name;

        if ($role === 'Distribuidora') {
            $distribuidora = $usuario->distribuidora;
            $query->where('distribuidora_id', $distribuidora?->id ?? 0);
        } elseif (in_array($role, ['Cajera', 'Gerente de Sucursal'], true)) {
            $sucursalId = $usuario->sucursal_id ?? 0;
            $query->whereHas('distribuidora', fn ($q) => $q->where('sucursal_id', $sucursalId));

            if (! empty($filters['distribuidora_id'])) {
                $query->where('distribuidora_id', (int) $filters['distribuidora_id']);
            }
        } elseif (! empty($filters['distribuidora_id'])) {
            $query->where('distribuidora_id', (int) $filters['distribuidora_id']);
        }

        if (! empty($filters['estado'])) {
            $query->where('estado', $filters['estado']);
        }

        return $query->latest('id')->paginate((int) ($filters['per_page'] ?? 15));
    }

    private function verificarPropiedad(Vale $vale, User $usuario): void
    {
        if ($vale->distribuidora_id !== $usuario->distribuidora?->id) {
            abort(403, 'Este vale no pertenece a tu distribuidora.');
        }
    }
}
