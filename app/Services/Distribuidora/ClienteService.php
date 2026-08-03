<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Cliente;
use App\Models\DatosPersonales;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\HistorialClienteDistr;
use App\Models\User;
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
            'numero_distribuidora' => 'DIST-'.str_pad((string) $usuario->id, 5, '0', STR_PAD_LEFT),
            'limite_credito' => 0.00,
            'credito_disponible' => 0.00,
            'puntos_acumulados' => 0,
            'estado' => true,
        ]);

        return $distribuidora;
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
     * Obtiene la lista paginada de clientes activos/asignados a la distribuidora del usuario.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listarClientes(User $usuario, array $filters = []): LengthAwarePaginator
    {
        $distribuidora = $this->obtenerOAsegurarDistribuidora($usuario);

        $query = Cliente::query()
            ->with(['datosPersonales.direccion', 'historialDistribuidoras' => function ($q) use ($distribuidora): void {
                $q->where('distribuidor_id', $distribuidora->id)->whereNull('fecha_fin');
            }])
            ->whereHas('historialDistribuidoras', function ($q) use ($distribuidora): void {
                $q->where('distribuidor_id', $distribuidora->id)->whereNull('fecha_fin');
            });

        if (isset($filters['estado'])) {
            $estado = filter_var($filters['estado'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($estado !== null) {
                $query->where('estado', $estado);
            }
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->whereHas('datosPersonales', function ($q) use ($search): void {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('apellido_paterno', 'like', "%{$search}%")
                    ->orWhere('apellido_materno', 'like', "%{$search}%")
                    ->orWhere('curp', 'like', "%{$search}%");
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

        $distribuidora = $this->obtenerOAsegurarDistribuidora($usuario);

        $esSuperusuario = in_array($usuario->role?->name, ['Gerente General', 'Administrador', 'Gerente de Sucursal'], true);

        if (! $esSuperusuario) {
            $perteneceADistribuidora = $cliente->historialDistribuidoras()
                ->where('distribuidor_id', $distribuidora->id)
                ->whereNull('fecha_fin')
                ->exists();

            if (! $perteneceADistribuidora) {
                abort(403, 'Acceso Denegado. Este cliente no está asignado a tu distribuidora.');
            }
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
}
