<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer genérico registrado sobre los modelos de negocio (ver AppServiceProvider::boot())
 * para que el Administrador pueda ver en `audit_log` todo lo que se hace en el aplicativo.
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
        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => class_basename($model).'.'.$evento,
            'resource' => class_basename($model).'#'.$model->getKey(),
        ]);
    }
}
