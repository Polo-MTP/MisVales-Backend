<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\HistorialEstadoDistribuidora;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DistribuidoraEstadoService
{
    /**
     * Cambia el estado de una distribuidora y genera la bitácora de auditoría histórica.
     */
    public function cambiarEstado(Distribuidora $distribuidora, string $nuevoEstado, string $motivo, User $usuario): Distribuidora
    {
        $estadoAnterior = (string) $distribuidora->estado;

        Log::debug('DistribuidoraEstadoService: Cambiando estado de distribuidora', [
            'distribuidora_id' => $distribuidora->id,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $nuevoEstado,
            'motivo' => $motivo,
            'cambiado_por' => $usuario->id,
        ]);

        return DB::transaction(function () use ($distribuidora, $estadoAnterior, $nuevoEstado, $motivo, $usuario): Distribuidora {
            // 1. Actualizar estado de la distribuidora
            $distribuidora->estado = $nuevoEstado;
            $distribuidora->save();

            // 2. Sincronizar estado de la cuenta de usuario si existe
            // (puede seguir accediendo si está ACTIVO o en proceso de verificación; RECHAZADO/MOROSO/EN_CAPTURA bloquean el acceso)
            if ($distribuidora->usuario) {
                $distribuidora->usuario->is_active = in_array($nuevoEstado, ['ACTIVO', 'EN_VERIFICACION'], true);
                $distribuidora->usuario->save();
            }

            // 3. Registrar auditoría en historial_estado_distribuidora
            HistorialEstadoDistribuidora::query()->create([
                'distribuidora_id' => $distribuidora->id,
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => $nuevoEstado,
                'motivo' => $motivo,
                'cambiado_por' => $usuario->id,
                'fecha' => now(),
            ]);

            Log::debug('DistribuidoraEstadoService: Cambio de estado registrado exitosamente', [
                'distribuidora_id' => $distribuidora->id,
            ]);

            return $distribuidora->fresh(['usuario', 'historialEstados.cambiadoPor']);
        });
    }

    /**
     * Obtiene la bitácora histórica de cambios de estado para una distribuidora.
     *
     * @return Collection<int, HistorialEstadoDistribuidora>
     */
    public function obtenerHistorialEstados(Distribuidora $distribuidora): Collection
    {
        return HistorialEstadoDistribuidora::query()
            ->with('cambiadoPor')
            ->where('distribuidora_id', $distribuidora->id)
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->get();
    }
}
