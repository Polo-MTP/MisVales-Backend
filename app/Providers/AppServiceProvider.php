<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AbonoConciliacion;
use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Distribuidora;
use App\Models\PuntoMovimiento;
use App\Models\Relacion;
use App\Models\SolicitudConciliacion;
use App\Models\SolicitudEdicionCliente;
use App\Models\SolicitudProveedor;
use App\Models\User;
use App\Models\Vale;
use App\Observers\AuditLogObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configurePasswordPolicy();
    }

    /**
     * Regla única de complejidad de contraseña para toda la app (alta de distribuidora,
     * reset de contraseña...). Antes cada FormRequest traía su propia regla suelta
     * (algunas solo pedían min:8, sin exigir mayúsculas/números).
     */
    private function configurePasswordPolicy(): void
    {
        Password::defaults(fn (): Password => Password::min(8)->mixedCase()->numbers());
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

        // User se registra aparte (no vía AuditLogObserver genérico): un login fallido
        // o exitoso guarda el modelo (failed_attempts, locked_until...) en CADA intento,
        // así que observar 'updated' ahí inundaría audit_log con ruido. Solo interesa
        // dejar rastro de cuándo se crea una cuenta.
        User::created(function (User $user): void {
            AuditLog::query()->create([
                'user_id' => auth()->id(),
                'action' => 'User.registrado',
                'resource' => 'User#'.$user->id,
                'ip_address' => request()->ip(),
            ]);
        });
    }
}
