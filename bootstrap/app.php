<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use Illuminate\Auth\Middleware\Authenticate;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureTokenNotIdle;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LogApiRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyVpnAccess;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
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
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
        ]);

        // SecurityHeaders (incluye X-Server-Number, ver la clase) va en el stack GLOBAL, no
        // solo en el grupo 'api': una ruta que no existe en absoluto ni siquiera llega a
        // ejecutar el middleware de un grupo -- Laravel no puede saber a qué grupo pertenece
        // algo que no matcheó ninguna ruta. Confirmado en vivo: este proyecto además tiene una
        // fallback route (grazulex/laravel-apiroute) que SÍ atrapa cualquier URL, con su propio
        // stack de middleware ajeno al de 'api' -- solo el global envuelve también a esa. Con
        // prepend() queda además como el middleware más externo posible, para que el header
        // sobreviva a cualquier otra cosa que truene después.
        $middleware->prepend(SecurityHeaders::class);

        // Sin esto, una petición sin sesión que además NO manda "Accept: application/json"
        // (el frontend real siempre lo manda -- ver auth.interceptor.ts -- pero cualquier otro
        // cliente: curl suelto, Postman sin configurar, una integración externa futura, no
        // necesariamente) truena en 500 en vez de devolver el 401 esperado. Authenticate::
        // redirectTo() por default intenta route('login') para construir un redirect -- esta
        // app es 100% API, esa ruta no existe, y construir esa URL avienta
        // RouteNotFoundException sin capturar antes de que la excepción de autenticación
        // siquiera se termine de armar. Con esto nunca intenta redirigir a ningún lado: el
        // flujo cae siempre al render(AuthenticationException) de abajo, con su error_code
        // UNAUTHENTICATED correcto, sin importar qué Accept mande el cliente.
        Authenticate::redirectUsing(fn () => null);

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
        $trustedProxies = env('TRUSTED_PROXIES', '*');

        $middleware->trustProxies(
            at: ($trustedProxies === '*' || $trustedProxies === '**')
                ? '*'
                : (empty($trustedProxies) ? null : array_values(array_filter(array_map('trim', explode(',', (string) $trustedProxies))))),
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

        // 'error_code' en cada respuesta de error: un identificador estable en inglés
        // (ver App\Enums\ApiErrorCode) para que un cliente distinga el TIPO de error sin
        // parsear 'message' (texto en español, puede cambiar de redacción sin previo aviso).

        $exceptions->render(fn (ValidationException $e, Request $request): JsonResponse => response()->json([
            'success' => false,
            'message' => 'Los datos enviados no son válidos.',
            'errors' => $e->errors(),
            'error_code' => ApiErrorCode::VALIDATION_ERROR->value,
        ], 422));

        $exceptions->render(fn (AuthenticationException $e, Request $request): JsonResponse => response()->json([
            'success' => false,
            'message' => 'No autenticado. Inicia sesión para continuar.',
            'error_code' => ApiErrorCode::UNAUTHENTICATED->value,
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
            'error_code' => ApiErrorCode::NOT_FOUND->value,
        ], 404));

        $exceptions->render(fn (MethodNotAllowedHttpException $e, Request $request): JsonResponse => response()->json([
            'success' => false,
            'message' => 'Método HTTP no permitido para esta ruta.',
            'error_code' => ApiErrorCode::METHOD_NOT_ALLOWED->value,
        ], 405));

        // ThrottleRequestsException (rate limiting: throttle:auth, throttle:api -- ver
        // AppServiceProvider) es subclase de HttpException, así que sin este renderer
        // específico caía en el HttpExceptionInterface genérico de abajo con el mensaje
        // default de Laravel ("Too Many Attempts."), el único texto que quedaba en inglés
        // en toda la API. Va antes que el genérico para que lo atrape primero.
        $exceptions->render(fn (ThrottleRequestsException $e, Request $request): JsonResponse => response()->json([
            'success' => false,
            'message' => 'Demasiados intentos. Intenta de nuevo más tarde.',
            'error_code' => ApiErrorCode::RATE_LIMITED->value,
        ], 429));

        // Cubre los abort($codigo, 'mensaje') usados en toda la app (403, 409, 422...).
        // El mensaje de un abort() sí es siempre texto que el propio desarrollador
        // escribió pensando en el usuario final, así que es seguro devolverlo tal cual.
        // error_code se infiere del status -- no hay forma genérica de saber la intención
        // fina de un abort() suelto, ver ApiErrorCode::fromHttpStatus().
        $exceptions->render(fn (HttpExceptionInterface $e, Request $request): JsonResponse => response()->json([
            'success' => false,
            'message' => $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo procesar la solicitud.',
            'error_code' => ApiErrorCode::fromHttpStatus($e->getStatusCode())->value,
        ], $e->getStatusCode()));

        $exceptions->render(fn (DomainException $e, Request $request): JsonResponse => response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'error_code' => ApiErrorCode::DOMAIN_ERROR->value,
        ], 422));

        // Connection::runQueryCallback() envuelve CUALQUIER falla del driver PDO (conexión
        // rechazada, timeout, servidor caído, "server has gone away"...) en QueryException --
        // por eso basta con atrapar esta única clase para cubrir también una caída total de la
        // BD, sin necesitar un handler aparte para PDOException. reconnectIfMissingConnection()
        // y el reintento automático de Connection ya se intentaron y fallaron antes de que la
        // excepción llegue hasta aquí.
        //
        // No toda QueryException es "la BD está caída" -- también cubre bugs reales (columna
        // que no existe, sintaxis inválida, constraint violado). causedByLostConnection() es el
        // mismo detector que usa el propio Connection::handleQueryException() para decidir si
        // vale la pena reintentar, así que es la forma correcta de distinguir "problema de
        // conectividad, reintentable" de "bug de query, reintentar no lo arregla". Si no es lo
        // primero, se regresa null para que caiga en el catch-all genérico de abajo (500).
        $exceptions->render(function (QueryException $e, Request $request): ?JsonResponse {
            if (! app(LostConnectionDetector::class)->causedByLostConnection($e)) {
                return null;
            }

            Log::error('Base de datos no disponible', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
                'url' => $request->fullUrl(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'El servicio no está disponible en este momento. Intenta de nuevo en unos minutos.',
                'error_code' => ApiErrorCode::SERVICE_UNAVAILABLE->value,
            ], 503);
        });

        // Red de seguridad final: cualquier otra excepción (bug real, incluida una
        // QueryException que NO fue por conectividad -- columna inexistente, sintaxis
        // inválida, constraint violado...) se registra completa en el log del servidor,
        // pero el mensaje que sale por la API siempre es genérico — independientemente
        // de APP_DEBUG. La verbosidad de depuración no debe depender de una variable de
        // entorno que alguien puede dejar mal puesta en producción.
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
                'error_code' => ApiErrorCode::SERVER_ERROR->value,
            ], 500);
        });
    })->create();
