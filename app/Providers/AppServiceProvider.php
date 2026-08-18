<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AbonoConciliacion;
use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Direccion;
use App\Models\Distribuidora;
use App\Models\PuntoMovimiento;
use App\Models\Relacion;
use App\Models\SolicitudConciliacion;
use App\Models\SolicitudEdicionCliente;
use App\Models\SolicitudProveedor;
use App\Models\User;
use App\Models\Vale;
use App\Observers\AuditLogObserver;
use App\Observers\DireccionObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Sin bindings propios de la aplicación por ahora.
     */
    public function register(): void
    {
        //
    }

    /**
     * Configura rate limiting, auditoría de modelos y política de contraseñas al arrancar la app.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureAuditLog();
        $this->configurePasswordPolicy();
        $this->configurePasswordResetUrl();
        $this->configureGeocoding();
    }

    /**
     * Geocodifica automáticamente cada dirección nueva o editada (ver DireccionObserver),
     * para que el Verificador pueda ver el pin en el mapa durante la visita física.
     */
    private function configureGeocoding(): void
    {
        Direccion::observe(DireccionObserver::class);
    }

    /**
     * Password::sendResetLink() arma el link por defecto apuntando a una ruta con nombre
     * 'password.reset' — como esta API no tiene vistas propias, sin este override el correo
     * termina apuntando a la URL de la API (GET, mientras la ruta real es POST-only) en vez
     * de a la pantalla del frontend que el usuario debe ver.
     */
    private function configurePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

            return "{$frontendUrl}/auth/reset-password?token={$token}&email=".urlencode($user->email);
        });
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

    /**
     * Límites de tasa por defecto de la API: general, login y endpoints autenticados.
     */
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
