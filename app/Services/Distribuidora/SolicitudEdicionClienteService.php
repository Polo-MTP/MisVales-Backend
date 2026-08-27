<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Cliente;
use App\Models\SolicitudEdicionCliente;
use App\Models\User;
use App\Services\Notificacion\NotificacionService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * La cajera comprueba los datos del pre-vale/vale del cliente; si algo necesita corrección,
 * pide autorización a su Coordinador, Gerente de Sucursal o Gerente General. Solo tras esa
 * autorización puede aplicar exactamente los campos que quedaron autorizados.
 */
final class SolicitudEdicionClienteService
{
    public function __construct(
        private readonly NotificacionService $notificacionService,
    ) {}

    /**
     * @param  array<string, mixed>  $datosPersonalesPropuestos
     * @param  array<string, mixed>  $direccionPropuesta
     */
    public function solicitar(
        Cliente $cliente,
        array $datosPersonalesPropuestos,
        array $direccionPropuesta,
        string $motivo,
        User $cajera,
    ): SolicitudEdicionCliente {
        $cliente->loadMissing('datosPersonales.direccion');

        /** @var SolicitudEdicionCliente $solicitud */
        $solicitud = SolicitudEdicionCliente::query()->create([
            'cliente_id' => $cliente->id,
            'solicitado_por' => $cajera->id,
            'sucursal_id' => $cajera->sucursal_id,
            'campos_propuestos' => [
                'datos_personales' => $datosPersonalesPropuestos,
                'direccion' => $direccionPropuesta,
                // Snapshot de cuándo se editó por última vez el registro real al momento de
                // pedir la autorización -- aplicar() lo compara contra el valor actual antes de
                // sobreescribir nada. Ver la nota completa en aplicar().
                '_snapshot' => [
                    'datos_personales_updated_at' => $cliente->datosPersonales?->updated_at?->toISOString(),
                    'direccion_updated_at' => $cliente->datosPersonales?->direccion?->updated_at?->toISOString(),
                ],
            ],
            'motivo' => $motivo,
            'estado' => 'pendiente',
        ]);

        $solicitud = $solicitud->fresh(['cliente.datosPersonales.direccion', 'solicitante']);

        // Sin esto, el Gerente de Sucursal tenía que entrar a "Ediciones Pendientes" a ciegas
        // para descubrir que una cajera está esperando su autorización para corregir un dato.
        $this->notificacionService->notificarRolEnSucursal(
            'Gerente de Sucursal',
            $cajera->sucursal_id,
            'edicion_cliente_solicitada',
            $this->nombreCliente($solicitud),
            $cajera
        );

        return $solicitud;
    }

    public function decidir(SolicitudEdicionCliente $solicitud, string $decision, ?string $comentario, User $autorizador): SolicitudEdicionCliente
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

        $solicitud = $solicitud->fresh(['cliente.datosPersonales.direccion', 'solicitante', 'autorizador']);

        // La cajera es quien tiene que APLICAR la corrección una vez aprobada: si no se entera
        // de la decisión, la solicitud se queda aprobada pero sin aplicar indefinidamente.
        if ($solicitante = $solicitud->solicitante) {
            $this->notificacionService->crear(
                $solicitante,
                $solicitud->estado === 'aprobada' ? 'edicion_cliente_aprobada' : 'edicion_cliente_rechazada',
                $this->nombreCliente($solicitud),
                $autorizador
            );
        }

        return $solicitud;
    }

    /** Etiqueta legible para la notificación: sin esto solo se vería un id de cliente. */
    private function nombreCliente(SolicitudEdicionCliente $solicitud): string
    {
        $datos = $solicitud->cliente?->datosPersonales;

        return $datos
            ? trim("{$datos->nombre} {$datos->apellido_paterno}")
            : 'Cliente #'.$solicitud->cliente_id;
    }

    public function aplicar(SolicitudEdicionCliente $solicitud, User $cajera): Cliente
    {
        if ($solicitud->estado !== 'aprobada') {
            throw new DomainException('Esta solicitud aún no ha sido aprobada.');
        }

        if ($solicitud->solicitado_por !== $cajera->id) {
            abort(403, 'Solo la cajera que solicitó la autorización puede aplicar esta edición.');
        }

        return DB::transaction(function () use ($solicitud): Cliente {
            /** @var Cliente $cliente */
            $cliente = $solicitud->cliente()->with('datosPersonales.direccion')->firstOrFail();
            $campos = $solicitud->campos_propuestos;
            $datosPersonales = $cliente->datosPersonales;

            // La solicitud guarda una foto estática de los valores propuestos, capturada cuando
            // la cajera la pidió -- sin esto, si alguien más (Distribuidora, Gerente General)
            // edita el mismo cliente directamente (PUT clientes/{id}) MIENTRAS la solicitud
            // sigue pendiente, aprobarla y aplicarla aquí sobreescribe esa edición más reciente
            // con el valor propuesto viejo, sin avisar a nadie -- un revert silencioso. Se
            // compara el 'updated_at' real contra el snapshot tomado al solicitar; si no
            // coincide, alguien más tocó el registro desde entonces. 'updated_at' solo guarda
            // hasta el segundo, así que una edición directa dentro del mismo segundo que el
            // snapshot no se detecta -- ventana angosta, mismo tipo de límite de precisión ya
            // aceptado en otras partes de este código (ver aumentoCreditoSinConsumir()).
            $snapshot = $campos['_snapshot'] ?? null;
            if ($snapshot) {
                $datosCambiaronDesdeLaSolicitud = $datosPersonales
                    && $datosPersonales->updated_at?->toISOString() !== $snapshot['datos_personales_updated_at'];
                $direccionCambioDesdeLaSolicitud = $datosPersonales?->direccion
                    && $datosPersonales->direccion->updated_at?->toISOString() !== $snapshot['direccion_updated_at'];

                if ($datosCambiaronDesdeLaSolicitud || $direccionCambioDesdeLaSolicitud) {
                    throw new DomainException('Este cliente fue editado por otra vía después de que se pidió esta autorización. Vuelve a solicitar la edición con los datos actuales antes de aplicarla, para no sobreescribir un cambio más reciente.');
                }
            }

            if (! empty($campos['datos_personales']) && $datosPersonales) {
                $datosPersonales->fill(array_filter($campos['datos_personales'], fn ($val) => $val !== null));
                $datosPersonales->save();
            }

            if (! empty($campos['direccion']) && $datosPersonales?->direccion) {
                $datosPersonales->direccion->fill(array_filter($campos['direccion'], fn ($val) => $val !== null));
                $datosPersonales->direccion->save();
            }

            $solicitud->update(['estado' => 'aplicada']);

            return $cliente->fresh(['datosPersonales.direccion']);
        });
    }

    /**
     * Lista solicitudes de edición de cliente. Cajera ve solo las suyas; Coordinador/Gerente
     * de Sucursal ven las de su sucursal; Gerente General ve todas.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listar(User $usuario, array $filters = []): LengthAwarePaginator
    {
        $query = SolicitudEdicionCliente::query()->with(['cliente.datosPersonales.direccion', 'solicitante', 'autorizador']);

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
