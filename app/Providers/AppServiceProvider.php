<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AbonoConciliacion;
use App\Models\Cliente;
use App\Models\Distribuidora;
use App\Models\PuntoMovimiento;
use App\Models\Relacion;
use App\Models\SolicitudConciliacion;
use App\Models\SolicitudEdicionCliente;
use App\Models\SolicitudProveedor;
use App\Models\Vale;
use App\Observers\AuditLogObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureAuditLog();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('authenticated', fn (Request $request) => $request->user()
            ? Limit::perMinute(120)->by($request->user()->id)
            : Limit::perMinute(60)->by($request->ip()));
    }

    /**
     * El Administrador solo ve logs: estos son los modelos de negocio cuyos cambios
     * quedan registrados en `audit_log` (ver AuditLogObserver).
     */
    private function configureAuditLog(): void
    {
        foreach ([
            Vale::class,
            Distribuidora::class,
            Cliente::class,
            AbonoConciliacion::class,
            Relacion::class,
            PuntoMovimiento::class,
            SolicitudProveedor::class,
            SolicitudConciliacion::class,
            SolicitudEdicionCliente::class,
        ] as $modelo) {
            $modelo::observe(AuditLogObserver::class);
        }
    }
}
