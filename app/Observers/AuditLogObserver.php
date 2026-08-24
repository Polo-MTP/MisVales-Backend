<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AbonoConciliacion;
use App\Models\AuditLog;
use App\Models\CategoriaDistribuidora;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\ConfiguracionFechas;
use App\Models\ConvenioBancario;
use App\Models\Distribuidora;
use App\Models\Evidencia;
use App\Models\Notificacion;
use App\Models\Producto;
use App\Models\PuntoMovimiento;
use App\Models\Relacion;
use App\Models\RelacionDetalle;
use App\Models\RelacionPerdon;
use App\Models\SeguroTabla;
use App\Models\SolicitudAumentoCredito;
use App\Models\SolicitudConciliacion;
use App\Models\SolicitudEdicionCliente;
use App\Models\SolicitudProveedor;
use App\Models\SolicitudTransferenciaCliente;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Observer genérico de auditoría registrado sobre todos los modelos de negocio.
 * Escribe un `AuditLog` detallado con diff JSON (antes vs después), sucursal resuelta,
 * IP real, User-Agent y nivel de severidad.
 */
final class AuditLogObserver
{
    private const CAMPOS_SENSIBLES = [
        'password',
        'secret',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    public function created(Model $model): void
    {
        $this->registrar('creado', 'INFO', $model, [
            'tipo' => 'creacion',
            'valores_iniciales' => $this->sanitizarAtributos($model->getAttributes()),
        ]);
    }

    public function updated(Model $model): void
    {
        $dirty = $this->sanitizarAtributos($model->getDirty());
        $original = $this->sanitizarAtributos(array_intersect_key($model->getOriginal(), $dirty));

        $this->registrar('actualizado', 'INFO', $model, [
            'tipo' => 'modificacion',
            'cambios' => [
                'antes' => $original,
                'despues' => $dirty,
            ],
        ]);
    }

    public function deleted(Model $model): void
    {
        $this->registrar('eliminado', 'WARNING', $model, [
            'tipo' => 'eliminacion',
            'valores_previos' => $this->sanitizarAtributos($model->getAttributes()),
        ]);
    }

    /**
     * Escribe el AuditLog y la Notificacion correspondientes al evento del modelo.
     *
     * @param  array<string, mixed>  $diffData
     */
    private function registrar(string $evento, string $nivel, Model $model, array $diffData): void
    {
        $modeloNombre = class_basename($model);
        $accion = $modeloNombre.'.'.$evento;
        $recurso = $modeloNombre.'#'.$model->getKey();
        $modulo = $this->resolverModulo($model);
        $sucursalId = $this->resolverSucursalId($model);
        $user = auth()->user();

        $datosAdicionales = array_merge($diffData, [
            'user_id' => $user?->id,
            'username' => $user?->name,
            'email' => $user?->email,
            'role' => $user?->role?->name ?? 'Invitado/Sistema',
            'sucursal_id' => $sucursalId,
            'method' => request()->method(),
            'url' => request()->fullUrl(),
        ]);

        AuditLog::query()->create([
            'user_id' => $user?->id,
            'sucursal_id' => $sucursalId,
            'action' => $accion,
            'modulo' => $modulo,
            'nivel' => $nivel,
            'descripcion' => $this->generarDescripcion($evento, $model),
            'resource' => $recurso,
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'user_agent' => request()->userAgent(),
            'datos_adicionales' => $datosAdicionales,
        ]);

        Notificacion::query()->create([
            'sucursal_id' => $sucursalId,
            'user_id' => $user?->id,
            'accion' => $accion,
            'recurso' => $recurso,
        ]);
    }

    private function resolverModulo(Model $model): string
    {
        return match (true) {
            $model instanceof Vale => 'Vales',
            $model instanceof Distribuidora,
            $model instanceof CategoriaDistribuidora,
            $model instanceof SolicitudAumentoCredito => 'Distribuidoras',
            $model instanceof Cliente,
            $model instanceof SolicitudEdicionCliente,
            $model instanceof SolicitudTransferenciaCliente => 'Clientes',
            $model instanceof AbonoConciliacion,
            $model instanceof SolicitudConciliacion => 'Conciliaciones',
            $model instanceof Sucursal => 'Sucursales',
            $model instanceof Producto,
            $model instanceof SeguroTabla,
            $model instanceof ConvenioBancario,
            $model instanceof Configuracion,
            $model instanceof ConfiguracionFechas => 'Configuración',
            $model instanceof SolicitudProveedor,
            $model instanceof Evidencia => 'Alta Proveedores',
            $model instanceof Relacion,
            $model instanceof RelacionDetalle,
            $model instanceof RelacionPerdon => 'Relaciones',
            $model instanceof PuntoMovimiento => 'Puntos y Lealtad',
            $model instanceof User => 'Usuarios',
            default => class_basename($model),
        };
    }

    private function generarDescripcion(string $evento, Model $model): string
    {
        $nombre = class_basename($model);
        $id = $model->getKey();

        return match ($evento) {
            'creado' => "Se registró un nuevo elemento en {$nombre} #{$id}.",
            'actualizado' => "Se modificaron los datos del registro {$nombre} #{$id}.",
            'eliminado' => "Se eliminó el registro {$nombre} #{$id}.",
            default => "Acción {$evento} sobre {$nombre} #{$id}.",
        };
    }

    private function resolverSucursalId(Model $model): ?int
    {
        return match (true) {
            $model instanceof Sucursal => $model->id,

            $model instanceof Distribuidora,
            $model instanceof Relacion,
            $model instanceof SolicitudProveedor,
            $model instanceof SolicitudConciliacion,
            $model instanceof SolicitudEdicionCliente => $model->sucursal_id,

            $model instanceof Vale,
            $model instanceof PuntoMovimiento => $model->distribuidora?->sucursal_id,

            $model instanceof AbonoConciliacion => $model->relacion?->sucursal_id,

            $model instanceof Evidencia => $model->solicitud?->sucursal_id,

            $model instanceof Cliente => $model->historialDistribuidoras()
                ->whereNull('fecha_fin')
                ->with('distribuidora')
                ->first()?->distribuidora?->sucursal_id,

            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    private function sanitizarAtributos(array $atributos): array
    {
        return Arr::except($atributos, self::CAMPOS_SENSIBLES);
    }
}

