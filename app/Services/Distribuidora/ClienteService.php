<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\HistorialClienteDistr;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ClienteService
{
    /**
     * Obtiene o asegura la existencia de la Distribuidora asociada a un usuario.
     */
    public function obtenerOAsegurarDistribuidora(User $usuario): Distribuidora
    {
        if ($usuario->distribuidora) {
            return $usuario->distribuidora;
        }

        /** @var Distribuidora $distribuidora */
        $distribuidora = Distribuidora::query()->create([
            'usuario_id' => $usuario->id,
            'numero_distribuidora' => 'DIST-'.mb_str_pad((string) $usuario->id, 5, '0', STR_PAD_LEFT),
            'limite_credito' => 0.00,
            'puntos_acumulados' => 0,
            'estado' => 'ACTIVO',
        ]);

        return $distribuidora;
    }

    /**
     * Busca UN cliente por CURP exacta, sin importar de qué distribuidora sea — a diferencia de
     * listarClientes(), que solo muestra la cartera propia. Se usa para solicitar la transferencia
     * de un cliente que hoy pertenece a otra distribuidora: se exige coincidencia exacta (no
     * búsqueda parcial por nombre) para no dejar "hojear" la cartera ajena, solo confirmar un
     * cliente puntual del que ya se tiene la CURP de otra fuente (ej. el cliente te la dio).
     */
    public function buscarPorCurpExacta(string $curp): ?Cliente
    {
        return Cliente::query()
            ->with(['datosPersonales.direccion', 'historialDistribuidoras.distribuidora'])
            ->whereHas('datosPersonales', fn ($q) => $q->where('curp', $curp))
            ->first();
    }

    /**
     * Registra un nuevo cliente para una distribuidora.
     *
     * @param  array<string, mixed>  $data
     */
    public function crearCliente(array $data, User $usuario): Cliente
    {
        $distribuidora = $this->obtenerOAsegurarDistribuidora($usuario);

        Log::debug('ClienteService: Registrando nuevo cliente de distribuidora', [
            'usuario_id' => $usuario->id,
            'distribuidora_id' => $distribuidora->id,
            'curp' => $data['curp'] ?? null,
        ]);

        return DB::transaction(function () use ($data, $distribuidora): Cliente {
            /** @var Direccion $direccion */
            $direccion = Direccion::query()->create([
                'calle' => $data['calle'],
                'colonia' => $data['colonia'],
                'numero_ext' => $data['numero_ext'],
                'numero_int' => $data['numero_int'] ?? null,
                'codigo_postal' => $data['codigo_postal'],
                'estado' => $data['estado'],
                'ciudad' => $data['ciudad'],
            ]);

            /** @var DatosPersonales $datosPersonales */
            $datosPersonales = DatosPersonales::query()->create([
                'nombre' => $data['nombre'],
                'apellido_paterno' => $data['apellido_paterno'],
                'apellido_materno' => $data['apellido_materno'] ?? null,
                'curp' => $data['curp'] ?? null,
                'direccion_id' => $direccion->id,
                'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
                'lugar_nacimiento' => $data['lugar_nacimiento'] ?? null,
            ]);

            /** @var Cliente $cliente */
            $cliente = Cliente::query()->create([
                'datos_id' => $datosPersonales->id,
                'estado' => true,
            ]);

            HistorialClienteDistr::query()->create([
                'distribuidor_id' => $distribuidora->id,
                'cliente_id' => $cliente->id,
                'fecha_inicio' => now(),
                'fecha_fin' => null,
            ]);

            Log::debug('ClienteService: Cliente creado exitosamente', [
                'cliente_id' => $cliente->id,
                'distribuidora_id' => $distribuidora->id,
            ]);

            return $cliente->load(['datosPersonales.direccion', 'historialDistribuidoras.distribuidora']);
        });
    }

    /**
     * Obtiene la lista paginada de clientes. Para Distribuidora, solo los suyos. Para staff
     * (Cajera/Gerente de Sucursal), los de su sucursal; Gerente General ve todos.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listarClientes(User $usuario, array $filters = []): LengthAwarePaginator
    {
        $query = Cliente::query()->with(['datosPersonales.direccion', 'historialDistribuidoras.distribuidora']);

        if ($this->esStaff($usuario)) {
            $role = $usuario->role?->name;

            $query->whereHas('historialDistribuidoras', function ($q) use ($usuario, $role): void {
                $q->whereNull('fecha_fin');

                if ($role !== 'Gerente General') {
                    $q->whereHas('distribuidora', fn ($dq) => $dq->where('sucursal_id', $usuario->sucursal_id));
                }
            });
        } else {
            $distribuidora = $this->obtenerOAsegurarDistribuidora($usuario);

            $query->whereHas('historialDistribuidoras', function ($q) use ($distribuidora): void {
                $q->where('distribuidor_id', $distribuidora->id)->whereNull('fecha_fin');
            });
        }

        if (isset($filters['estado'])) {
            $estado = filter_var($filters['estado'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($estado !== null) {
                $query->where('estado', $estado);
            }
        }

        if (! empty($filters['search'])) {
            // Cada palabra del término debe coincidir en algún campo, pero no necesariamente en
            // el mismo -- si no, buscar "Juana Reyes" (nombre="Juana", apellido="Reyes") no
            // encontraba nada porque ninguna columna por separado contiene las dos palabras.
            $palabras = preg_split('/\s+/', trim((string) $filters['search']), -1, PREG_SPLIT_NO_EMPTY);

            $query->whereHas('datosPersonales', function ($q) use ($palabras): void {
                foreach ($palabras as $palabra) {
                    $q->where(function ($sub) use ($palabra): void {
                        $sub->where('nombre', 'like', "%{$palabra}%")
                            ->orWhere('apellido_paterno', 'like', "%{$palabra}%")
                            ->orWhere('apellido_materno', 'like', "%{$palabra}%")
                            ->orWhere('curp', 'like', "%{$palabra}%");
                    });
                }
            });
        }

        return $query->latest('id')->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Obtiene el detalle de un cliente asegurando permisos.
     */
    public function obtenerCliente(int $clienteId, User $usuario): Cliente
    {
        /** @var Cliente $cliente */
        $cliente = Cliente::query()
            ->with(['datosPersonales.direccion', 'historialDistribuidoras.distribuidora'])
            ->findOrFail($clienteId);

        $role = $usuario->role?->name;

        if (in_array($role, ['Gerente General', 'Administrador'], true)) {
            return $cliente;
        }

        if (in_array($role, ['Cajera', 'Gerente de Sucursal'], true)) {
            $perteneceASucursal = $cliente->historialDistribuidoras()
                ->whereNull('fecha_fin')
                ->whereHas('distribuidora', fn ($q) => $q->where('sucursal_id', $usuario->sucursal_id))
                ->exists();

            if (! $perteneceASucursal) {
                abort(403, 'Acceso Denegado. Este cliente no está asignado a tu sucursal.');
            }

            return $cliente;
        }

        $distribuidora = $this->obtenerOAsegurarDistribuidora($usuario);

        $perteneceADistribuidora = $cliente->historialDistribuidoras()
            ->where('distribuidor_id', $distribuidora->id)
            ->whereNull('fecha_fin')
            ->exists();

        if (! $perteneceADistribuidora) {
            abort(403, 'Acceso Denegado. Este cliente no está asignado a tu distribuidora.');
        }

        return $cliente;
    }

    /**
     * Edita los datos personales o dirección de un cliente.
     *
     * @param  array<string, mixed>  $data
     */
    public function actualizarCliente(Cliente $cliente, array $data, User $usuario): Cliente
    {
        $this->obtenerCliente($cliente->id, $usuario);

        return DB::transaction(function () use ($cliente, $data): Cliente {
            $cliente->load(['datosPersonales.direccion']);
            $datosPersonales = $cliente->datosPersonales;
            $direccion = $datosPersonales?->direccion;

            if (! empty($data['datos_personales']) && is_array($data['datos_personales']) && $datosPersonales) {
                $datosPersonales->fill(array_filter($data['datos_personales'], fn ($val) => $val !== null));
                $datosPersonales->save();
            }

            if (! empty($data['direccion']) && is_array($data['direccion']) && $direccion) {
                $direccion->fill(array_filter($data['direccion'], fn ($val) => $val !== null));
                $direccion->save();
            }

            return $cliente->fresh(['datosPersonales.direccion', 'historialDistribuidoras.distribuidora']);
        });
    }

    /**
     * Cambia el estado del cliente (activar / desactivar).
     */
    public function cambiarEstadoCliente(Cliente $cliente, bool $nuevoEstado, User $usuario): Cliente
    {
        $this->obtenerCliente($cliente->id, $usuario);

        $cliente->estado = $nuevoEstado;
        $cliente->save();

        Log::debug('ClienteService: Estado de cliente actualizado', [
            'cliente_id' => $cliente->id,
            'nuevo_estado' => $nuevoEstado,
        ]);

        return $cliente->load(['datosPersonales.direccion']);
    }

    /**
     * Mueve de un solo golpe TODOS los clientes activos de una distribuidora a otra. Pensado
     * para cuando una distribuidora deja de operar y el coordinador necesita reubicar su
     * cartera sin reasignar cliente por cliente. Solo el Coordinador dueño de AMBAS
     * distribuidoras puede hacerlo -- mover un cliente fuera de la cartera del propio
     * coordinador es una transferencia individual con autorización (SolicitudTransferenciaCliente),
     * no esto.
     */
    public function reasignarTodos(Distribuidora $origen, Distribuidora $destino, User $usuario): int
    {
        if ($usuario->role?->name !== 'Coordinador' || $origen->coordinador_id !== $usuario->id || $destino->coordinador_id !== $usuario->id) {
            abort(403, 'Solo puedes reasignar clientes entre distribuidoras que tú coordinas.');
        }

        if ($origen->id === $destino->id) {
            throw new DomainException('La distribuidora destino debe ser diferente de la distribuidora de origen.');
        }

        if (! in_array($destino->estado, ['ACTIVO', 'EN_VERIFICACION'], true)) {
            throw new DomainException('La distribuidora destino no puede recibir clientes en su estado actual.');
        }

        return DB::transaction(function () use ($origen, $destino): int {
            $historiales = HistorialClienteDistr::query()
                ->where('distribuidor_id', $origen->id)
                ->whereNull('fecha_fin')
                ->lockForUpdate()
                ->get();

            foreach ($historiales as $historial) {
                $historial->fecha_fin = now();
                $historial->save();

                HistorialClienteDistr::query()->create([
                    'distribuidor_id' => $destino->id,
                    'cliente_id' => $historial->cliente_id,
                    'fecha_inicio' => now(),
                    'fecha_fin' => null,
                ]);
            }

            Log::debug('ClienteService: Reasignación masiva de clientes entre distribuidoras', [
                'origen_id' => $origen->id,
                'destino_id' => $destino->id,
                'total' => $historiales->count(),
            ]);

            return $historiales->count();
        });
    }

    /**
     * Roles de staff (no son ellos mismos una distribuidora) que necesitan ver clientes de
     * varias distribuidoras a la vez -- ej. la cajera buscando a quién corregirle datos.
     */
    private function esStaff(User $usuario): bool
    {
        return in_array($usuario->role?->name, ['Cajera', 'Gerente de Sucursal', 'Gerente General'], true);
    }
}
