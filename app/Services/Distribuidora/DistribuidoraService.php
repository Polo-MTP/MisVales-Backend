<?php

declare(strict_types=1);

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\HistorialEstadoDistribuidora;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class DistribuidoraService
{
    public function __construct(
        private readonly DistribuidoraEstadoService $estadoService
    ) {}

    /**
     * Lista distribuidoras según el rol del usuario autenticado.
     *
     * @return Collection<int, Distribuidora>
     */
    public function listarPorRol(): Collection
    {
        $user = auth()->guard()->user();
        $query = Distribuidora::with(['sucursal', 'coordinador', 'categoria']);

        if (!$user) {
            return $query->orderBy('created_at', 'desc')->get();
        }

        // Obtener el nombre del rol del usuario
        $roleName = $user->role?->name; // Asumiendo que tienes relación role()

        if ($roleName === 'coordinador') {
            $query->where('coordinador_id', $user->id);
        } elseif ($roleName === 'gerente-sucursal') {
            $query->whereHas('sucursal', fn($q) => $q->where('id', $user->sucursal_id));
        } elseif ($roleName === 'verificador') {
            $query->where(function ($q) use ($user) {
                $q->where('verificador_id', $user->id)
                ->orWhere(function ($q2) use ($user) {
                    $q2->where('estado', 'EN_VERIFICACION')
                        ->whereHas('sucursal', fn($s) => $s->where('id', $user->sucursal_id));
                });
            });
        }
        // Gerente General y Administrador ven todas (sin filtro)

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Crea una nueva distribuidora (estado inicial: EN_CAPTURA).
     */
    public function crear(array $data, User $usuario): Distribuidora
    {
        $data['usuario_id'] = $usuario->id;
        $data['coordinador_id'] = $data['coordinador_id'] ?? $usuario->id;
        $data['estado'] = 'EN_CAPTURA';
        $data['numero_distribuidora'] = $this->generarNumeroDistribuidora();

        return DB::transaction(function () use ($data): Distribuidora {
            $distribuidora = Distribuidora::create($data);

            if (! empty($data['datos_personales'])) {
                $distribuidora->datosPersonales()->create($data['datos_personales']);
            }

            return $distribuidora->fresh();
        });
    }

    /**
     * Actualiza datos generales de la distribuidora.
     */
    public function actualizar(Distribuidora $distribuidora, array $data): Distribuidora
    {
        $distribuidora->update($data);
        return $distribuidora->fresh();
    }

    /**
     * Cambia el estado de una distribuidora (delega en DistribuidoraEstadoService).
     */
    public function cambiarEstado(Distribuidora $distribuidora, string $nuevoEstado, string $motivo = null, User $usuario = null): Distribuidora
    {
        $usuario = $usuario ?? auth()->guard()->user();
        // Convertir el string a booleano para el servicio existente (si usa booleano)
        // Pero tu servicio espera bool, así que mapeamos estados a boolean
        $estadoBool = in_array($nuevoEstado, ['ACTIVO', 'EN_VERIFICACION']) ? true : false;

        // Llamamos al servicio existente que ya maneja auditoría
        return $this->estadoService->cambiarEstado($distribuidora, $estadoBool, $motivo ?? '', $usuario);
    }

    /**
     * Asigna límite de crédito y categoría, y genera credenciales de acceso.
     */
    public function asignarCredito(Distribuidora $distribuidora, float $limiteCredito, int $categoriaId): Distribuidora
    {
        return DB::transaction(function () use ($distribuidora, $limiteCredito, $categoriaId): Distribuidora {
            $distribuidora->limite_credito = $limiteCredito;
            $distribuidora->categoria_id = $categoriaId;
            $distribuidora->save();

            // Generar usuario y contraseña para la distribuidora (si no tiene)
            if (empty($distribuidora->usuario_acceso)) {
                $usuario = 'dist' . $distribuidora->id;
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
     * Genera un número de distribuidora único.
     */
    private function generarNumeroDistribuidora(): string
    {
        $year = date('Y');
        $last = Distribuidora::whereYear('created_at', $year)->count() + 1;
        return 'DIST-' . $year . '-' . str_pad((string) $last, 4, '0', STR_PAD_LEFT);
    }
}