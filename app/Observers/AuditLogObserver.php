<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AbonoConciliacion;
use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Distribuidora;
use App\Models\Notificacion;
use App\Models\PuntoMovimiento;
use App\Models\Relacion;
use App\Models\SolicitudConciliacion;
use App\Models\SolicitudEdicionCliente;
use App\Models\SolicitudProveedor;
use App\Models\Vale;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer genérico registrado sobre los modelos de negocio (ver AppServiceProvider::boot()).
 * Escribe dos cosas por cada evento: un `AuditLog` (para que el Administrador vea en /admin/logs
 * todo lo que se hace) y una `Notificacion` con sucursal resuelta (para que Gerente de Sucursal
 * vea solo los movimientos de su sucursal, y Gerente General/Administrador los vea todos).
 */
final class AuditLogObserver
{
    public function created(Model $model): void
    {
        $this->registrar('creado', $model);
    }

    public function updated(Model $model): void
    {
        $this->registrar('actualizado', $model);
    }

    public function deleted(Model $model): void
    {
        $this->registrar('eliminado', $model);
    }

    private function registrar(string $evento, Model $model): void
    {
        $accion = class_basename($model).'.'.$evento;
        $recurso = class_basename($model).'#'.$model->getKey();

        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => $accion,
            'resource' => $recurso,
        ]);

        Notificacion::query()->create([
            'sucursal_id' => $this->resolverSucursalId($model),
            'user_id' => auth()->id(),
            'accion' => $accion,
            'recurso' => $recurso,
        ]);
    }

    /**
     * Nula cuando no se puede resolver una sucursal para el modelo (solo la ven
     * Gerente General/Administrador, que ven todo sin filtrar).
     */
    private function resolverSucursalId(Model $model): ?int
    {
        return match (true) {
            $model instanceof Distribuidora,
            $model instanceof Relacion,
            $model instanceof SolicitudProveedor,
            $model instanceof SolicitudConciliacion,
            $model instanceof SolicitudEdicionCliente => $model->sucursal_id,

            $model instanceof Vale,
            $model instanceof PuntoMovimiento => $model->distribuidora?->sucursal_id,

            $model instanceof AbonoConciliacion => $model->relacion?->sucursal_id,

            $model instanceof Cliente => $model->historialDistribuidoras()
                ->whereNull('fecha_fin')
                ->with('distribuidora')
                ->first()?->distribuidora?->sucursal_id,

            default => null,
        };
    }
}
