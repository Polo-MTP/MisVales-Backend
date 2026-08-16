<?php

declare(strict_types=1);

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LogApiRequests;
use App\Http\Middleware\SecurityHeaders;
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
            'role' => CheckRole::class,
            'security.headers' => SecurityHeaders::class,
        ]);

        // El alias por sí solo no aplica el middleware a ninguna ruta: hay que
        // adjuntarlo al grupo 'api' para que corra en todas las respuestas de la API.
        $middleware->api(append: [
            SecurityHeaders::class,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
        ]);
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

        // Cubre tanto los abort($codigo, 'mensaje') usados en toda la app (403, 404,
        // 409, 422...) como los 404/405 nativos de Laravel (ruta o modelo inexistente,
        // método HTTP no permitido). El mensaje de un abort() es siempre texto que el
        // propio desarrollador escribió pensando en el usuario final, así que es seguro
        // devolverlo tal cual.
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
