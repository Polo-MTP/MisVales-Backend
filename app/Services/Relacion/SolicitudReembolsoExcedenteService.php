<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\ExcedenteMovimiento;
use App\Models\SolicitudReembolsoExcedente;
use App\Models\User;
use App\Models\Vale;
use App\Services\Notificacion\NotificacionService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Cuando un vale ya quedó 'pagado' (liquidó su última cuota) pero le sobra saldo a favor, ya
 * no hay ninguna cuota futura de ESE vale que lo consuma solo -- ExcedenteConciliacionService
 * solo aplica el saldo a las cuotas pendientes de un vale, y este ya no tiene ninguna. La
 * cajera solicita el reembolso; el Gerente lo autoriza. El dinero real se transfiere FUERA del
 * sistema (igual que el resto de los flujos de este módulo -- aumento de crédito, perdón de
 * deuda): al aprobar, el sistema solo deja constancia de que ya se resolvió.
 */
final class SolicitudReembolsoExcedenteService
{
    public function __construct(
        private readonly NotificacionService $notificacionService,
    ) {}

    public function solicitar(Vale $vale, User $cajera, ?string $motivo): SolicitudReembolsoExcedente
    {
        $distribuidora = $vale->distribuidora;

        if (! $distribuidora || $distribuidora->sucursal_id !== $cajera->sucursal_id) {
            abort(403, 'No puedes solicitar el reembolso de un vale de otra sucursal.');
        }

        if ($vale->estado !== 'pagado') {
            throw new DomainException('Solo se puede solicitar el reembolso del saldo a favor de un vale ya liquidado por completo -- mientras tenga cuotas pendientes, el saldo se le sigue aplicando solo.');
        }

        if ((float) $vale->saldo_excedente <= 0) {
            throw new DomainException('Este vale no tiene saldo a favor pendiente de reembolsar.');
        }

        $yaTienePendiente = SolicitudReembolsoExcedente::query()
            ->where('vale_id', $vale->id)
            ->where('estado', 'pendiente')
            ->exists();

        if ($yaTienePendiente) {
            throw new DomainException('Ya hay una solicitud de reembolso pendiente para este vale.');
        }

        /** @var SolicitudReembolsoExcedente $solicitud */
        $solicitud = SolicitudReembolsoExcedente::query()->create([
            'vale_id' => $vale->id,
            'distribuidora_id' => $distribuidora->id,
            'sucursal_id' => $cajera->sucursal_id,
            'monto' => $vale->saldo_excedente,
            'solicitado_por' => $cajera->id,
            'motivo' => $motivo,
            'estado' => 'pendiente',
        ]);

        // Mueve dinero real -- quien autoriza necesita enterarse de que hay algo esperando, no
        // descubrirlo entrando a la pantalla de solicitudes por su cuenta.
        $this->notificacionService->notificarRolEnSucursal(
            'Gerente de Sucursal',
            $cajera->sucursal_id,
            'reembolso_excedente_solicitado',
            'Vale #'.$vale->id.' — $'.number_format((float) $vale->saldo_excedente, 2),
            $cajera
        );

        return $solicitud->fresh(['vale.cliente.datosPersonales', 'distribuidora', 'solicitante']);
    }

    public function decidir(SolicitudReembolsoExcedente $solicitud, string $decision, ?string $comentario, User $gerente): SolicitudReembolsoExcedente
    {
        if ($solicitud->estado !== 'pendiente') {
            throw new DomainException('Esta solicitud ya fue resuelta.');
        }

        $role = $gerente->role?->name;

        if ($role !== 'Gerente General' && $solicitud->sucursal_id !== $gerente->sucursal_id) {
            abort(403, 'No puedes decidir solicitudes de otra sucursal.');
        }

        if ($decision !== 'aprobada') {
            $solicitud->update([
                'estado' => 'rechazada',
                'autorizado_por' => $gerente->id,
                'comentario_autorizacion' => $comentario,
                'fecha_decision' => now(),
            ]);

            $this->notificarDecision($solicitud, 'reembolso_excedente_rechazado', $gerente);

            return $solicitud->fresh(['vale.cliente.datosPersonales', 'distribuidora', 'solicitante', 'autorizador']);
        }

        return DB::transaction(function () use ($solicitud, $comentario, $gerente): SolicitudReembolsoExcedente {
            /** @var Vale $vale */
            $vale = $solicitud->vale()->lockForUpdate()->firstOrFail();

            // Reembolsa lo que REALMENTE haya ahora, no el monto snapshoteado al solicitar --
            // pudo cambiar entre que se pidió y se autorizó (ej. otro abono con concepto le
            // agregó más excedente a este mismo vale mientras tanto).
            $montoReembolsado = (float) $vale->saldo_excedente;

            if ($montoReembolsado <= 0) {
                throw new DomainException('Este vale ya no tiene saldo a favor -- probablemente ya se consumió o reembolsó por otro lado. Rechaza esta solicitud.');
            }

            ExcedenteMovimiento::query()->create([
                'distribuidora_id' => $vale->distribuidora_id,
                'vale_id' => $vale->id,
                'tipo' => 'reembolsado',
                'monto' => -$montoReembolsado,
                'motivo' => 'Reembolsado por '.$gerente->name.' (solicitud #'.$solicitud->id.')',
            ]);

            $vale->update(['saldo_excedente' => 0]);

            $solicitud->update([
                'estado' => 'aprobada',
                'monto' => $montoReembolsado,
                'autorizado_por' => $gerente->id,
                'comentario_autorizacion' => $comentario,
                'fecha_decision' => now(),
            ]);

            $this->notificarDecision($solicitud, 'reembolso_excedente_aprobado', $gerente);

            return $solicitud->fresh(['vale.cliente.datosPersonales', 'distribuidora', 'solicitante', 'autorizador']);
        });
    }

    /**
     * Lista solicitudes visibles: Cajera ve las que ella misma pidió; Coordinador/Gerente de
     * Sucursal las de su sucursal; Gerente General todas.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listar(User $usuario, array $filters = []): LengthAwarePaginator
    {
        $query = SolicitudReembolsoExcedente::query()->with(['vale.cliente.datosPersonales', 'distribuidora', 'solicitante', 'autorizador']);

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

    private function notificarDecision(SolicitudReembolsoExcedente $solicitud, string $accion, User $gerente): void
    {
        $recurso = 'Vale #'.$solicitud->vale_id.' — $'.number_format((float) $solicitud->monto, 2);

        if ($solicitante = $solicitud->solicitante) {
            $this->notificacionService->crear($solicitante, $accion, $recurso, $gerente);
        }

        // La distribuidora es la dueña real del dinero -- necesita saber que ya se resolvió,
        // no solo la cajera que lo tramitó.
        if ($distribuidoraUsuario = $solicitud->distribuidora?->usuario) {
            $this->notificacionService->crear($distribuidoraUsuario, $accion, $recurso, $gerente);
        }
    }
}
