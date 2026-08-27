<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\HistorialEstadoDistribuidora;
use App\Models\Relacion;
use App\Models\User;
use App\Services\Notificacion\NotificacionService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DistribuidoraEstadoService
{
    public function __construct(
        private readonly NotificacionService $notificacionService,
    ) {}

    /**
     * Cambia el estado de una distribuidora y genera la bitácora de auditoría histórica.
     * $usuario es null cuando el cambio lo dispara el propio sistema (ej. MOROSO automático
     * en RelacionEstadoService), no una acción humana explícita.
     */
    public function cambiarEstado(Distribuidora $distribuidora, string $nuevoEstado, string $motivo, ?User $usuario): Distribuidora
    {
        $estadoAnterior = (string) $distribuidora->estado;

        // Reactivar una distribuidora MOROSA no debe ser un clic sin más -- la marca automática
        // (ver RelacionEstadoService::evaluarMorosidad()) existe justo para que el impago
        // quede registrado y visible; permitir volver a ACTIVO/EN_VERIFICACION mientras sigan
        // existiendo relaciones 'vencida'/'en_perdida' sin resolver dejaba que un humano
        // revirtiera esa marca con un clic sin que la deuda se hubiera liquidado o perdonado.
        if ($estadoAnterior === 'MOROSO' && in_array($nuevoEstado, ['ACTIVO', 'EN_VERIFICACION'], true)) {
            $relacionesSinResolver = Relacion::query()
                ->where('distribuidora_id', $distribuidora->id)
                ->whereIn('estado', ['vencida', 'en_perdida'])
                ->count();

            if ($relacionesSinResolver > 0) {
                throw new DomainException(
                    "No se puede reactivar: la distribuidora todavía tiene {$relacionesSinResolver} relación(es) vencida(s) o en pérdida sin resolver (perdonar o liquidar) antes de poder reactivarla."
                );
            }
        }

        Log::debug('DistribuidoraEstadoService: Cambiando estado de distribuidora', [
            'distribuidora_id' => $distribuidora->id,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $nuevoEstado,
            'motivo' => $motivo,
            'cambiado_por' => $usuario?->id,
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
                'cambiado_por' => $usuario?->id,
                'fecha' => now(),
            ]);

            Log::debug('DistribuidoraEstadoService: Cambio de estado registrado exitosamente', [
                'distribuidora_id' => $distribuidora->id,
            ]);

            if ($nuevoEstado === 'MOROSO') {
                $this->notificacionService->notificarRolEnSucursal(
                    'Cajera',
                    $distribuidora->sucursal_id,
                    'distribuidora_morosa',
                    'Distribuidora '.$distribuidora->numero_distribuidora,
                    $usuario
                );
            }

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
