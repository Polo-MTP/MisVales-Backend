<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\HistorialCoordinador;
use App\Models\User;
use App\Services\Notificacion\NotificacionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DistribuidoraService
{
    public function __construct(
        private readonly DistribuidoraEstadoService $estadoService,
        private readonly NotificacionService $notificacionService,
    ) {}

    /**
     * Lista distribuidoras según el rol del usuario autenticado.
     *
     * @return Collection<int, Distribuidora>
     */
    public function listarPorRol(): Collection
    {
        $user = auth()->guard()->user();
        $query = Distribuidora::with(['sucursal', 'coordinador', 'categoria', 'usuario.datosPersonales']);

        if (! $user) {
            return $query->orderBy('created_at', 'desc')->get();
        }

        // Obtener el nombre del rol del usuario
        $roleName = $user->role?->name; // Asumiendo que tienes relación role()

        if ($roleName === 'Coordinador') {
            $query->where('coordinador_id', $user->id);
        } elseif ($roleName === 'Gerente de Sucursal' || $roleName === 'Cajera') {
            // La cajera necesita esto para elegir la distribuidora al capturar un canje de puntos.
            $query->whereHas('sucursal', fn ($q) => $q->where('id', $user->sucursal_id));
        } elseif ($roleName === 'Verificador') {
            $query->where(function ($q) use ($user) {
                $q->where('verificador_id', $user->id)
                    ->orWhere(function ($q2) use ($user) {
                        $q2->where('estado', 'EN_VERIFICACION')
                            ->whereHas('sucursal', fn ($s) => $s->where('id', $user->sucursal_id));
                    });
            });
        }
        // Gerente General y Administrador ven todas (sin filtro)

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Actualiza datos generales de la distribuidora.
     */
    public function actualizar(Distribuidora $distribuidora, array $data): Distribuidora
    {
        $distribuidora->update($data);

        // DistribuidoraResource usa whenLoaded() para 'categoria'/'datos_extras' y accede
        // directo a 'sucursal'/'coordinador'/'usuario.datosPersonales' -- sin este load(), la
        // respuesta de este endpoint queda incompleta/inconsistente frente a la de show().
        return $distribuidora->fresh(['usuario.datosPersonales.direccion', 'datosExtras', 'categoria', 'sucursal', 'coordinador', 'verificador']);
    }

    /**
     * Cambia el estado de una distribuidora (delega en DistribuidoraEstadoService).
     */
    public function cambiarEstado(Distribuidora $distribuidora, string $nuevoEstado, ?string $motivo = null, ?User $usuario = null): Distribuidora
    {
        $usuario = $usuario ?? auth()->guard()->user();

        return $this->estadoService->cambiarEstado($distribuidora, $nuevoEstado, $motivo ?? '', $usuario);
    }

    /**
     * Asigna límite de crédito y categoría, y genera credenciales de acceso.
     */
    public function asignarCredito(Distribuidora $distribuidora, float $limiteCredito, int $categoriaId): Distribuidora
    {
        return DB::transaction(function () use ($distribuidora, $limiteCredito, $categoriaId): Distribuidora {
            $limiteAnterior = (float) $distribuidora->limite_credito;

            $distribuidora->limite_credito = $limiteCredito;
            $distribuidora->categoria_id = $categoriaId;
            $distribuidora->save();

            // Generar usuario y contraseña para la distribuidora (si no tiene)
            if (empty($distribuidora->usuario_acceso)) {
                $usuario = 'dist'.$distribuidora->id;
                $password = Str::random(10);
                $distribuidora->usuario_acceso = $usuario;
                $distribuidora->password_hash = Hash::make($password);
                $distribuidora->save();

                // Aquí podrías enviar un email con las credenciales
                // event(new CredencialesGeneradas($distribuidora, $password));
            }

            // Cambiar estado a ACTIVO si estaba en PENDIENTE_APROBACION o EN_CAPTURA
            if (in_array($distribuidora->estado, ['EN_CAPTURA', 'PENDIENTE_APROBACION'])) {
                $this->cambiarEstado($distribuidora, 'ACTIVO', 'Asignación de crédito y aprobación automática');
            }

            if ($distribuidora->usuario) {
                $accion = match (true) {
                    $limiteAnterior <= 0 => 'credito_asignado',
                    $limiteCredito > $limiteAnterior => 'credito_incrementado',
                    $limiteCredito < $limiteAnterior => 'credito_reducido',
                    default => 'credito_actualizado',
                };

                $this->notificacionService->crear(
                    $distribuidora->usuario,
                    $accion,
                    'Nuevo límite: $'.number_format($limiteCredito, 2)
                );
            }

            return $distribuidora->fresh();
        });
    }

    /**
     * Obtiene el saldo disponible (crédito total - vales activos).
     */
    public function obtenerSaldoDisponible(Distribuidora $distribuidora): float
    {
        return $distribuidora->credito_disponible; // accesor del modelo
    }

    /**
     * Mueve TODA la cartera de distribuidoras de un coordinador a otro de un solo golpe.
     * Pensado para cuando un coordinador deja la empresa/sucursal: en vez de reasignar
     * distribuidora por distribuidora, el Gerente lo hace de una vez. Cierra el periodo
     * vigente en historial_coordinador de cada distribuidora y abre uno nuevo, igual que se
     * hace en la asignación inicial (SolicitudProveedorService::aprobar).
     */
    public function reasignarCoordinador(User $coordinadorOrigen, User $coordinadorDestino, User $usuario): int
    {
        $rolUsuario = $usuario->role?->name;

        if (! in_array($rolUsuario, ['Gerente de Sucursal', 'Gerente General'], true)) {
            abort(403, 'Solo un Gerente puede reasignar la cartera de un coordinador.');
        }

        if ($coordinadorOrigen->role?->name !== 'Coordinador' || $coordinadorDestino->role?->name !== 'Coordinador') {
            abort(422, 'Ambos usuarios deben tener el rol Coordinador.');
        }

        if ($coordinadorOrigen->id === $coordinadorDestino->id) {
            abort(422, 'El coordinador destino debe ser diferente del coordinador de origen.');
        }

        if ($coordinadorDestino->sucursal_id !== $coordinadorOrigen->sucursal_id) {
            abort(422, 'El coordinador destino debe pertenecer a la misma sucursal que el de origen.');
        }

        if ($rolUsuario === 'Gerente de Sucursal' && $coordinadorOrigen->sucursal_id !== $usuario->sucursal_id) {
            abort(403, 'Solo puedes reasignar coordinadores de tu propia sucursal.');
        }

        return DB::transaction(function () use ($coordinadorOrigen, $coordinadorDestino, $usuario): int {
            $distribuidoras = Distribuidora::query()
                ->where('coordinador_id', $coordinadorOrigen->id)
                ->lockForUpdate()
                ->get();

            foreach ($distribuidoras as $distribuidora) {
                $distribuidora->coordinador_id = $coordinadorDestino->id;
                $distribuidora->save();

                HistorialCoordinador::query()
                    ->where('distribuidor_id', $distribuidora->usuario_id)
                    ->where('coordinador_id', $coordinadorOrigen->id)
                    ->whereNull('fecha_fin')
                    ->update(['fecha_fin' => now()]);

                HistorialCoordinador::query()->create([
                    'distribuidor_id' => $distribuidora->usuario_id,
                    'coordinador_id' => $coordinadorDestino->id,
                    'fecha_inicio' => now(),
                    'asignado_por' => $usuario->id,
                    'motivo' => "Reasignación masiva de cartera (coordinador #{$coordinadorOrigen->id} dejó de operar).",
                ]);
            }

            return $distribuidoras->count();
        });
    }

    /**
     * Sube (o reemplaza) el contrato firmado de la distribuidora.
     */
    public function subirContrato(Distribuidora $distribuidora, UploadedFile $archivo): Distribuidora
    {
        $ruta = Storage::disk('public')->putFile('contratos', $archivo);

        $distribuidora->contrato_url = Storage::disk('public')->url($ruta);
        $distribuidora->save();

        return $distribuidora->fresh();
    }
}
