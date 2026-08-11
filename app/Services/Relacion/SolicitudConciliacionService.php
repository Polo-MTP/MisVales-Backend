<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\AbonoConciliacion;
use App\Models\Relacion;
use App\Models\SolicitudConciliacion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Solo la cajera ejecuta una conciliación manual, pero necesita que un Coordinador, Gerente
 * de Sucursal o Gerente General autorice primero la solicitud puntual sobre ese abono.
 */
final class SolicitudConciliacionService
{
    public function __construct(
        private readonly ConciliacionBancariaService $conciliacionBancariaService,
    ) {}

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

        return $solicitud->fresh(['abono', 'relacion', 'solicitante']);
    }

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

        return $solicitud->fresh(['abono', 'relacion', 'solicitante', 'autorizador']);
    }

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
}
