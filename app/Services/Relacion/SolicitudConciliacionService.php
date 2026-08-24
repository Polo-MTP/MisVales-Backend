<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\AbonoConciliacion;
use App\Models\Relacion;
use App\Models\SolicitudConciliacion;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Solo la cajera ejecuta una conciliación manual, pero necesita que un Coordinador, Gerente
 * de Sucursal o Gerente General autorice primero la solicitud puntual sobre ese abono.
 */
final class SolicitudConciliacionService
{
    public function __construct(
        private readonly ConciliacionBancariaService $conciliacionBancariaService,
        private readonly \App\Services\Notificacion\NotificacionService $notificacionService,
    ) {}

    /**
     * Crea la solicitud de autorización para conciliar manualmente un abono sin coincidencia.
     */
    public function solicitar(AbonoConciliacion $abono, int $relacionId, string $motivo, User $cajera): SolicitudConciliacion
    {
        if ($abono->estado !== 'sin_coincidencia') {
            throw new DomainException('Este abono ya fue conciliado previamente.');
        }

        /** @var Relacion $relacion */
        $relacion = Relacion::query()->findOrFail($relacionId);

        /** @var SolicitudConciliacion $solicitud */
        $solicitud = SolicitudConciliacion::query()->create([
            'abono_conciliacion_id' => $abono->id,
            'relacion_id' => $relacion->id,
            'solicitado_por' => $cajera->id,
            'sucursal_id' => $cajera->sucursal_id,
            'motivo' => $motivo,
            'estado' => 'pendiente',
        ]);

        // Mueve dinero real sobre un corte: quien autoriza necesita enterarse de que hay algo
        // esperando, no descubrirlo entrando a la pantalla de autorizaciones por su cuenta.
        $this->notificacionService->notificarRolEnSucursal(
            'Gerente de Sucursal',
            $cajera->sucursal_id,
            'conciliacion_manual_solicitada',
            'Abono #'.$abono->id.' → Ref. '.$relacion->referencia_pago,
            $cajera
        );

        return $solicitud->fresh(['abono', 'relacion', 'solicitante']);
    }

    /**
     * Autoriza o rechaza una solicitud de conciliación manual pendiente, restringido a
     * autorizadores de la misma sucursal (salvo Gerente General).
     */
    public function decidir(SolicitudConciliacion $solicitud, string $decision, ?string $comentario, User $autorizador): SolicitudConciliacion
    {
        if ($solicitud->estado !== 'pendiente') {
            throw new DomainException('Esta solicitud ya fue resuelta.');
        }

        $role = $autorizador->role?->name;

        if ($role !== 'Gerente General' && $autorizador->sucursal_id !== $solicitud->sucursal_id) {
            abort(403, 'No puedes autorizar solicitudes de otra sucursal.');
        }

        $solicitud->update([
            'estado' => $decision === 'aprobada' ? 'aprobada' : 'rechazada',
            'autorizado_por' => $autorizador->id,
            'comentario_autorizacion' => $comentario,
            'fecha_decision' => now(),
        ]);

        $solicitud = $solicitud->fresh(['abono', 'relacion', 'solicitante', 'autorizador']);

        // La cajera es quien debe EJECUTAR la conciliación aprobada (paso 3 del control de
        // cuatro ojos): sin aviso, el dinero se queda sin aplicar aunque ya esté autorizado.
        if ($solicitante = $solicitud->solicitante) {
            $this->notificacionService->crear(
                $solicitante,
                $solicitud->estado === 'aprobada' ? 'conciliacion_manual_aprobada' : 'conciliacion_manual_rechazada',
                'Abono #'.$solicitud->abono_conciliacion_id,
                $autorizador
            );
        }

        return $solicitud;
    }

    /**
     * Ejecuta la conciliación manual ya autorizada; solo la cajera que la solicitó puede hacerlo.
     */
    public function ejecutar(SolicitudConciliacion $solicitud, User $cajera): AbonoConciliacion
    {
        if ($solicitud->estado !== 'aprobada') {
            throw new DomainException('Esta solicitud aún no ha sido aprobada.');
        }

        if ($solicitud->solicitado_por !== $cajera->id) {
            abort(403, 'Solo la cajera que solicitó la autorización puede ejecutar esta conciliación manual.');
        }

        return DB::transaction(function () use ($solicitud, $cajera): AbonoConciliacion {
            $abono = $this->conciliacionBancariaService->conciliarManual(
                $solicitud->abono,
                $solicitud->relacion,
                $cajera,
                $solicitud->motivo,
                $solicitud->autorizado_por
            );

            $solicitud->update(['estado' => 'aplicada']);

            return $abono;
        });
    }

    /**
     * Lista solicitudes de conciliación manual. Cajera ve solo las suyas; Coordinador/Gerente
     * de Sucursal ven las de su sucursal; Gerente General ve todas.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listar(User $usuario, array $filters = []): LengthAwarePaginator
    {
        $query = SolicitudConciliacion::query()->with(['abono', 'relacion', 'solicitante', 'autorizador']);

        $role = $usuario->role?->name;

        if ($role === 'Cajera') {
            $query->where('solicitado_por', $usuario->id);
        } elseif ($role !== 'Gerente General') {
            $query->where('sucursal_id', $usuario->sucursal_id);
        }

        if (! empty($filters['estado'])) {
            $query->where('estado', $filters['estado']);
        }

        return $query->latest('id')->paginate((int) ($filters['per_page'] ?? 15));
    }
}
