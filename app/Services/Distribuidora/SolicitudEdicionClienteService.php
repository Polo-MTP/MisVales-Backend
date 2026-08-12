<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Cliente;
use App\Models\SolicitudEdicionCliente;
use App\Models\User;
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
        /** @var SolicitudEdicionCliente $solicitud */
        $solicitud = SolicitudEdicionCliente::query()->create([
            'cliente_id' => $cliente->id,
            'solicitado_por' => $cajera->id,
            'sucursal_id' => $cajera->sucursal_id,
            'campos_propuestos' => [
                'datos_personales' => $datosPersonalesPropuestos,
                'direccion' => $direccionPropuesta,
            ],
            'motivo' => $motivo,
            'estado' => 'pendiente',
        ]);

        return $solicitud->fresh(['cliente', 'solicitante']);
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

        return $solicitud->fresh(['cliente', 'solicitante', 'autorizador']);
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
        $query = SolicitudEdicionCliente::query()->with(['cliente.datosPersonales', 'solicitante', 'autorizador']);

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
