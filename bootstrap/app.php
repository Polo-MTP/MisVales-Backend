<?php

declare(strict_types=1);

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureTokenNotIdle;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LogApiRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyVpnAccess;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'force.json' => ForceJsonResponse::class,
            'log.api' => LogApiRequests::class,
            'verified' => EnsureEmailVerified::class,
            'active' => EnsureUserIsActive::class,
            'idle' => EnsureTokenNotIdle::class,
            'role' => CheckRole::class,
            'security.headers' => SecurityHeaders::class,
            'vpn' => VerifyVpnAccess::class,
        ]);

        // El alias por sí solo no aplica el middleware a ninguna ruta: hay que
        // adjuntarlo al grupo 'api' para que corra en todas las respuestas de la API.
        $middleware->api(append: [
            SecurityHeaders::class,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
        ]);

        // Activa el modo "stateful" de Sanctum: peticiones que vienen de un dominio listado en
        // SANCTUM_STATEFUL_DOMAINS se autentican por cookie de sesión httpOnly en vez de Bearer
        // token (evita que un XSS pueda robar la sesión leyendo localStorage -- ver auditoría de
        // seguridad, hallazgo H-02). Cualquier petición que SÍ traiga un Bearer token válido
        // sigue funcionando igual que antes; este modo solo se activa para el dominio del SPA.
        $middleware->statefulApi();

        // Detrás del balanceador nuevo, sin esto $request->ip() siempre regresa la IP
        // del balanceador para TODAS las peticiones — rompe throttle:auth (5/min pasa
        // de ser por usuario a compartido entre todos), el ip_address de login_attempts
        // y de audit_log, y cualquier check de IP-VPN. Vacío por defecto (no confía en
        // nadie) hasta que infra dé la IP interna real del balanceador; nunca usar '*'
        // aquí, eso permitiría spoofear la IP vía X-Forwarded-For desde cualquier lado.
        //
        // env() directo aquí (no config()) a propósito: withMiddleware() corre antes de
        // que el contenedor tenga el binding 'config' registrado — usar config() aquí
        // literalmente tumba el boot de la app ("Target class [config] does not exist"),
        // ya lo comprobé. bootstrap/app.php es de los pocos lugares donde env() directo
        // es el patrón correcto (así lo muestra la propia guía de Laravel para esto).
        $middleware->trustProxies(
            at: array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // App 100% API: cualquier excepción se renderiza como JSON, nunca como la
        // página HTML de error de Laravel (que en debug=true expone el stack trace).
        $exceptions->shouldRenderJsonWhen(fn (): bool => true);

        // Antes de este archivo, withExceptions() estaba vacío: toda excepción no
        // atrapada explícitamente por un controller cae en el renderer default de
        // Laravel, que no usa el formato {success, message} del resto de la API y,
        // en debug=true, devuelve el stack trace completo en el cuerpo de la respuesta.
        // Con esto, el formato queda unificado y el detalle técnico nunca sale por la
        // API — sí se registra completo en storage/logs para quien lo necesite depurar.

        $exceptions->render(fn (ValidationException $e, Request $request): JsonResponse => response()->json([
            'success' => false,
            'message' => 'Los datos enviados no son válidos.',
            'errors' => $e->errors(),
        ], 422));

        $exceptions->render(fn (AuthenticationException $e, Request $request): JsonResponse => response()->json([
            'success' => false,
            'message' => 'No autenticado. Inicia sesión para continuar.',
        ], 401));

        // NotFoundHttpException/MethodNotAllowedHttpException las lanza Laravel mismo
        // (ruta inexistente, modelo no encontrado por route-model-binding, método HTTP
        // no soportado) — su mensaje NUNCA lo escribió nadie pensando en el usuario:
        // trae el nombre completo de la clase del modelo ("No query results for model
        // [App\Models\Relacion] 999") o la lista de métodos permitidos de la ruta. Van
        // antes que el HttpExceptionInterface genérico de abajo para que las atrape
        // primero (son subclases de HttpException, matchearían ahí también si no).
        $exceptions->render(fn (NotFoundHttpException $e, Request $request): JsonResponse => response()->json([
            'success' => false,
            'message' => 'El recurso solicitado no existe.',
        ], 404));

        $exceptions->render(fn (MethodNotAllowedHttpException $e, Request $request): JsonResponse => response()->json([
            'success' => false,
            'message' => 'Método HTTP no permitido para esta ruta.',
        ], 405));

        // Cubre los abort($codigo, 'mensaje') usados en toda la app (403, 409, 422...).
        // El mensaje de un abort() sí es siempre texto que el propio desarrollador
        // escribió pensando en el usuario final, así que es seguro devolverlo tal cual.
        $exceptions->render(fn (HttpExceptionInterface $e, Request $request): JsonResponse => response()->json([
            'success' => false,
            'message' => $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo procesar la solicitud.',
        ], $e->getStatusCode()));

        $exceptions->render(fn (DomainException $e, Request $request): JsonResponse => response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 422));

        // Red de seguridad final: cualquier otra excepción (bug real, error de BD, etc.)
        // se registra completa en el log del servidor, pero el mensaje que sale por la
        // API siempre es genérico — independientemente de APP_DEBUG. La verbosidad de
        // depuración no debe depender de una variable de entorno que alguien puede
        // dejar mal puesta en producción.
        $exceptions->render(function (Throwable $e, Request $request): JsonResponse {
            Log::error('Excepción no controlada', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'url' => $request->fullUrl(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error interno. Intenta de nuevo más tarde.',
            ], 500);
        });
    })->create();
