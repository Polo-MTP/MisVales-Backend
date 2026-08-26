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
     * Registra servicios personalizados en el contenedor.
     */
    public function register(): void
    {
        $this->app->singleton(\Resend\Contracts\Client::class, static function ($app): \Resend\Client {
            $apiKey = config('resend.api_key') ?? config('services.resend.key');

            if (! is_string($apiKey) || $apiKey === '') {
                throw \Resend\Laravel\Exceptions\ApiKeyIsMissing::create();
            }

            $key = \Resend\ValueObjects\ApiKey::from($apiKey);
            $baseUri = \Resend\ValueObjects\Transporter\BaseUri::from(getenv('RESEND_BASE_URL') ?: 'api.resend.com');
            $headers = \Resend\ValueObjects\Transporter\Headers::withAuthorization($key);

            $guzzleOptions = [];
            if ($app->environment('local', 'testing')) {
                $guzzleOptions['verify'] = false;
            }

            $client = new \GuzzleHttp\Client($guzzleOptions);
            $transporter = new \Resend\Transporters\HttpTransporter($client, $baseUri, $headers);

            return new \Resend\Client($transporter);
        });

        $this->app->alias(\Resend\Contracts\Client::class, 'resend');
        $this->app->alias(\Resend\Contracts\Client::class, \Resend\Client::class);
    }

    /**
     * Configura rate limiting, auditoría de modelos y política de contraseñas al arrancar la app.
     */
    public function boot(): void
    {
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

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
     * Registro completo de todos los modelos de negocio, catálogos y operaciones del sistema
     * en la bitácora forense de auditoría (`audit_log`).
     */
    private function configureAuditLog(): void
    {
        foreach ([
            \App\Models\Vale::class,
            \App\Models\Distribuidora::class,
            \App\Models\CategoriaDistribuidora::class,
            \App\Models\Cliente::class,
            \App\Models\AbonoConciliacion::class,
            \App\Models\Relacion::class,
            \App\Models\RelacionDetalle::class,
            \App\Models\RelacionPerdon::class,
            \App\Models\PuntoMovimiento::class,
            \App\Models\SolicitudProveedor::class,
            \App\Models\SolicitudConciliacion::class,
            \App\Models\SolicitudEdicionCliente::class,
            \App\Models\SolicitudAumentoCredito::class,
            \App\Models\SolicitudTransferenciaCliente::class,
            \App\Models\Sucursal::class,
            \App\Models\Producto::class,
            \App\Models\SeguroTabla::class,
            \App\Models\Evidencia::class,
            \App\Models\ConvenioBancario::class,
            \App\Models\Configuracion::class,
            \App\Models\ConfiguracionFechas::class,
            // Faltaban -- el Verificador corrige nombre/CURP/dirección directo sobre estas dos
            // filas (ver SolicitudProveedorService::verificarSolicitud()) y quedaba sin rastro
            // en el log que ve Administrador: SolicitudProveedor sí está arriba, pero su propio
            // 'updated' solo trae los campos que le pertenecen a ÉL (estado, cumple...), nunca
            // los de estas dos tablas relacionadas.
            \App\Models\DatosPersonales::class,
            \App\Models\Direccion::class,
        ] as $modelo) {
            $modelo::observe(AuditLogObserver::class);
        }

        // Auditoría explícita de cuentas de usuario
        User::created(function (User $user): void {
            $actor = auth()->user();
            AuditLog::query()->create([
                'user_id' => $actor?->id,
                'sucursal_id' => $user->sucursal_id,
                'action' => 'User.creado',
                'modulo' => 'Usuarios',
                'nivel' => 'INFO',
                'descripcion' => "Se dio de alta el usuario {$user->name} ({$user->email}) con rol {$user->role?->name}.",
                'resource' => 'User#'.$user->id,
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->userAgent(),
                'datos_adicionales' => [
                    'tipo' => 'creacion_usuario',
                    'nuevo_usuario' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role_id' => $user->role_id,
                        'sucursal_id' => $user->sucursal_id,
                    ],
                    'creado_por' => $actor ? ['id' => $actor->id, 'name' => $actor->name, 'email' => $actor->email] : 'Sistema',
                ],
            ]);
        });

        User::updated(function (User $user): void {
            if ($user->wasChanged(['is_active', 'role_id', 'sucursal_id', 'is_locked'])) {
                $actor = auth()->user();
                $cambios = $user->getChanges();
                $original = array_intersect_key($user->getOriginal(), $cambios);

                AuditLog::query()->create([
                    'user_id' => $actor?->id,
                    'sucursal_id' => $user->sucursal_id,
                    'action' => 'User.estado_modificado',
                    'modulo' => 'Usuarios',
                    'nivel' => 'WARNING',
                    'descripcion' => "Se modificó el estado o perfil del usuario {$user->name} ({$user->email}).",
                    'resource' => 'User#'.$user->id,
                    'ip_address' => request()->ip() ?? '127.0.0.1',
                    'user_agent' => request()->userAgent(),
                    'datos_adicionales' => [
                        'tipo' => 'cambio_estado_usuario',
                        'cambios' => [
                            'antes' => $original,
                            'despues' => $cambios,
                        ],
                        'modificado_por' => $actor ? ['id' => $actor->id, 'name' => $actor->name, 'email' => $actor->email] : 'Sistema',
                    ],
                ]);
            }
        });
    }
}
