<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Cliente;
use App\Models\Distribuidora;
use App\Models\HistorialClienteDistr;
use App\Models\SolicitudTransferenciaCliente;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Una distribuidora quiere quedarse con un cliente que hoy pertenece a otra. A diferencia de
 * la reasignación masiva (ClienteService::reasignarTodos, que mueve TODA la cartera de un
 * coordinador a otro dentro de la misma estructura), esto es una solicitud puntual entre dos
 * distribuidoras que puede venir de fuera de la cartera del coordinador que autoriza, así que
 * exige tres pasos: la destino solicita -> el coordinador/gerente de la ORIGEN autoriza -> la
 * destino confirma que sigue queriendo al cliente (pudo cambiar de opinión o de capacidad de
 * crédito entre la solicitud y la autorización).
 */
final class SolicitudTransferenciaClienteService
{
    public function solicitar(Cliente $cliente, User $usuario, string $motivo): SolicitudTransferenciaCliente
    {
        if ($usuario->role?->name !== 'Distribuidora' || ! $usuario->distribuidora) {
            abort(403, 'Solo una distribuidora puede solicitar la transferencia de un cliente.');
        }

        $distribuidoraDestino = $usuario->distribuidora;

        $historialActivo = HistorialClienteDistr::query()
            ->where('cliente_id', $cliente->id)
            ->whereNull('fecha_fin')
            ->first();

        if (! $historialActivo) {
            abort(422, 'Este cliente no tiene una distribuidora activa asignada.');
        }

        if ($historialActivo->distribuidor_id === $distribuidoraDestino->id) {
            abort(422, 'Este cliente ya pertenece a tu distribuidora.');
        }

        $yaTienePendiente = SolicitudTransferenciaCliente::query()
            ->where('cliente_id', $cliente->id)
            ->whereIn('estado', ['pendiente_autorizacion', 'autorizada'])
            ->exists();

        if ($yaTienePendiente) {
            abort(422, 'Ya existe una solicitud de transferencia en curso para este cliente.');
        }

        /** @var SolicitudTransferenciaCliente $solicitud */
        $solicitud = SolicitudTransferenciaCliente::query()->create([
            'cliente_id' => $cliente->id,
            'distribuidora_origen_id' => $historialActivo->distribuidor_id,
            'distribuidora_destino_id' => $distribuidoraDestino->id,
            'solicitado_por' => $usuario->id,
            'motivo' => $motivo,
        ]);

        return $solicitud->fresh(['cliente.datosPersonales', 'distribuidoraOrigen', 'distribuidoraDestino', 'solicitante']);
    }

    public function decidir(SolicitudTransferenciaCliente $solicitud, string $decision, ?string $comentario, User $usuario): SolicitudTransferenciaCliente
    {
        if ($solicitud->estado !== 'pendiente_autorizacion') {
            throw new DomainException('Esta solicitud ya no está pendiente de autorización.');
        }

        $this->verificarAutoridadSobreOrigen($solicitud->distribuidoraOrigen, $usuario);

        $solicitud->update([
            'estado' => $decision === 'autorizada' ? 'autorizada' : 'rechazada',
            'autorizado_por' => $usuario->id,
            'comentario_autorizacion' => $comentario,
            'fecha_autorizacion' => now(),
        ]);

        return $solicitud->fresh(['cliente.datosPersonales', 'distribuidoraOrigen', 'distribuidoraDestino', 'solicitante', 'autorizador']);
    }

    public function decidirAceptacion(SolicitudTransferenciaCliente $solicitud, string $decision, User $usuario): SolicitudTransferenciaCliente
    {
        if ($solicitud->estado !== 'autorizada') {
            throw new DomainException('Esta solicitud aún no ha sido autorizada.');
        }

        if ($usuario->distribuidora?->id !== $solicitud->distribuidora_destino_id) {
            abort(403, 'Solo la distribuidora que solicitó la transferencia puede confirmarla.');
        }

        if ($decision !== 'aceptada') {
            $solicitud->update(['estado' => 'rechazada']);

            return $solicitud->fresh(['cliente.datosPersonales', 'distribuidoraOrigen', 'distribuidoraDestino']);
        }

        return DB::transaction(function () use ($solicitud): SolicitudTransferenciaCliente {
            $historialActivo = HistorialClienteDistr::query()
                ->where('cliente_id', $solicitud->cliente_id)
                ->whereNull('fecha_fin')
                ->lockForUpdate()
                ->first();

            if (! $historialActivo || $historialActivo->distribuidor_id !== $solicitud->distribuidora_origen_id) {
                throw new DomainException('El cliente ya no pertenece a la distribuidora de origen; esta solicitud quedó obsoleta.');
            }

            $destino = Distribuidora::query()->findOrFail($solicitud->distribuidora_destino_id);
            if (! in_array($destino->estado, ['ACTIVO', 'EN_VERIFICACION'], true)) {
                throw new DomainException('Tu distribuidora no puede recibir clientes en su estado actual.');
            }

            $historialActivo->fecha_fin = now();
            $historialActivo->save();

            HistorialClienteDistr::query()->create([
                'distribuidor_id' => $solicitud->distribuidora_destino_id,
                'cliente_id' => $solicitud->cliente_id,
                'fecha_inicio' => now(),
                'fecha_fin' => null,
            ]);

            $solicitud->update(['estado' => 'aceptada', 'fecha_aceptacion' => now()]);

            return $solicitud->fresh(['cliente.datosPersonales', 'distribuidoraOrigen', 'distribuidoraDestino']);
        });
    }

    /**
     * Lista solicitudes visibles: Distribuidora ve las que ella pidió; Coordinador/Gerente de
     * Sucursal ven las de las distribuidoras que coordinan/su sucursal (como origen o destino);
     * Gerente General ve todas.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listar(User $usuario, array $filters = []): LengthAwarePaginator
    {
        $query = SolicitudTransferenciaCliente::query()
            ->with(['cliente.datosPersonales', 'distribuidoraOrigen', 'distribuidoraDestino', 'solicitante', 'autorizador']);

        $role = $usuario->role?->name;

        if ($role === 'Distribuidora') {
            $query->where('distribuidora_destino_id', $usuario->distribuidora?->id);
        } elseif ($role === 'Coordinador') {
            $query->whereHas('distribuidoraOrigen', fn ($q) => $q->where('coordinador_id', $usuario->id));
        } elseif ($role !== 'Gerente General') {
            $query->where(function ($q) use ($usuario): void {
                $q->whereHas('distribuidoraOrigen', fn ($dq) => $dq->where('sucursal_id', $usuario->sucursal_id))
                    ->orWhereHas('distribuidoraDestino', fn ($dq) => $dq->where('sucursal_id', $usuario->sucursal_id));
            });
        }

        if (! empty($filters['estado'])) {
            $query->where('estado', $filters['estado']);
        }

        return $query->latest('id')->paginate((int) ($filters['per_page'] ?? 15));
    }

    private function verificarAutoridadSobreOrigen(Distribuidora $origen, User $usuario): void
    {
        $role = $usuario->role?->name;

        if ($role === 'Gerente General') {
            return;
        }

        if ($role === 'Gerente de Sucursal' && $origen->sucursal_id === $usuario->sucursal_id) {
            return;
        }

        if ($role === 'Coordinador' && $origen->coordinador_id === $usuario->id) {
            return;
        }

        abort(403, 'No tienes autoridad sobre la distribuidora de origen de este cliente.');
    }
}
