<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Cliente;
use App\Models\Distribuidora;
use App\Models\HistorialClienteDistr;
use App\Models\SolicitudTransferenciaCliente;
use App\Models\User;
use App\Services\Notificacion\NotificacionService;
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
    public function __construct(
        private readonly NotificacionService $notificacionService,
    ) {}

    public function solicitar(Cliente $cliente, User $usuario, string $motivo): SolicitudTransferenciaCliente
    {
        if ($usuario->role?->name !== 'Distribuidora' || ! $usuario->distribuidora) {
            abort(403, 'Solo una distribuidora puede solicitar la transferencia de un cliente.');
        }

        $distribuidoraDestino = $usuario->distribuidora;

        $solicitud = DB::transaction(function () use ($cliente, $distribuidoraDestino, $usuario, $motivo): SolicitudTransferenciaCliente {
            // lockForUpdate() sobre el cliente: sin esto, dos distribuidoras pidiendo la
            // transferencia del mismo cliente casi al mismo tiempo podían pasar ambas el
            // exists() de abajo antes de que cualquiera guardara, colando dos solicitudes en
            // curso hacia destinos distintos sobre el mismo cliente.
            Cliente::query()->whereKey($cliente->id)->lockForUpdate()->first();

            $historialActivo = HistorialClienteDistr::query()
                ->where('cliente_id', $cliente->id)
                ->whereNull('fecha_fin')
                ->first();

            if (! $historialActivo) {
                throw new DomainException('Este cliente no tiene una distribuidora activa asignada.');
            }

            if ($historialActivo->distribuidor_id === $distribuidoraDestino->id) {
                throw new DomainException('Este cliente ya pertenece a tu distribuidora.');
            }

            $yaTienePendiente = SolicitudTransferenciaCliente::query()
                ->where('cliente_id', $cliente->id)
                ->whereIn('estado', ['pendiente_autorizacion', 'autorizada'])
                ->exists();

            if ($yaTienePendiente) {
                throw new DomainException('Ya existe una solicitud de transferencia en curso para este cliente.');
            }

            /** @var SolicitudTransferenciaCliente $solicitud */
            return SolicitudTransferenciaCliente::query()->create([
                'cliente_id' => $cliente->id,
                'distribuidora_origen_id' => $historialActivo->distribuidor_id,
                'distribuidora_destino_id' => $distribuidoraDestino->id,
                'solicitado_por' => $usuario->id,
                'motivo' => $motivo,
            ]);
        });

        $solicitud = $solicitud->fresh(['cliente.datosPersonales', 'distribuidoraOrigen.usuario.datosPersonales', 'distribuidoraOrigen.coordinador', 'distribuidoraDestino.usuario.datosPersonales', 'solicitante']);

        // Sin esto la transferencia era invisible para los dos que más la necesitan saber: la
        // distribuidora que va a PERDER al cliente (se le desaparecía de su cartera sin aviso)
        // y quien tiene que autorizarla (tenía que descubrirla entrando a la pantalla a ciegas).
        $nombreCliente = $solicitud->cliente?->datosPersonales?->nombre ?? 'Cliente #'.$solicitud->cliente_id;

        if ($origenUsuario = $solicitud->distribuidoraOrigen?->usuario) {
            $this->notificacionService->crear($origenUsuario, 'transferencia_cliente_solicitada', $nombreCliente, $usuario);
        }

        if ($autorizador = $solicitud->distribuidoraOrigen?->coordinador) {
            $this->notificacionService->crear($autorizador, 'transferencia_cliente_por_autorizar', $nombreCliente, $usuario);
        }

        return $solicitud;
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

        $solicitud = $solicitud->fresh(['cliente.datosPersonales', 'distribuidoraOrigen.usuario.datosPersonales', 'distribuidoraDestino.usuario.datosPersonales', 'solicitante', 'autorizador']);

        // La destino no tenía forma de enterarse de que ya puede confirmar más que entrando a
        // revisar la pantalla cada tanto; la origen, de que su cliente está por irse.
        $nombreCliente = $solicitud->cliente?->datosPersonales?->nombre ?? 'Cliente #'.$solicitud->cliente_id;
        $accion = $solicitud->estado === 'autorizada' ? 'transferencia_cliente_autorizada' : 'transferencia_cliente_rechazada';

        if ($destinoUsuario = $solicitud->distribuidoraDestino?->usuario) {
            $this->notificacionService->crear($destinoUsuario, $accion, $nombreCliente, $usuario);
        }

        if ($solicitud->estado === 'autorizada' && ($origenUsuario = $solicitud->distribuidoraOrigen?->usuario)) {
            $this->notificacionService->crear($origenUsuario, $accion, $nombreCliente, $usuario);
        }

        return $solicitud;
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

            return $solicitud->fresh(['cliente.datosPersonales', 'distribuidoraOrigen.usuario.datosPersonales', 'distribuidoraDestino.usuario.datosPersonales']);
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

            $solicitud = $solicitud->fresh(['cliente.datosPersonales', 'distribuidoraOrigen.usuario.datosPersonales', 'distribuidoraDestino.usuario.datosPersonales']);

            // Este es el momento en que el cliente REALMENTE sale de la cartera de la origen --
            // antes simplemente desaparecía de "Mis Clientes" sin ningún aviso.
            if ($origenUsuario = $solicitud->distribuidoraOrigen?->usuario) {
                $nombreCliente = $solicitud->cliente?->datosPersonales?->nombre ?? 'Cliente #'.$solicitud->cliente_id;
                $this->notificacionService->crear($origenUsuario, 'transferencia_cliente_aceptada', $nombreCliente);
            }

            return $solicitud;
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
            ->with(['cliente.datosPersonales', 'distribuidoraOrigen.usuario.datosPersonales', 'distribuidoraDestino.usuario.datosPersonales', 'solicitante', 'autorizador']);

        $role = $usuario->role?->name;

        if ($role === 'Distribuidora') {
            // Origen TAMBIÉN, no solo destino: la distribuidora que va a perder al cliente es
            // parte interesada en la transferencia -- antes solo la veía quien lo pedía, así
            // que al dueño actual el cliente se le esfumaba de la cartera sin explicación.
            $miId = $usuario->distribuidora?->id;
            $query->where(function ($q) use ($miId): void {
                $q->where('distribuidora_destino_id', $miId)
                    ->orWhere('distribuidora_origen_id', $miId);
            });
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
