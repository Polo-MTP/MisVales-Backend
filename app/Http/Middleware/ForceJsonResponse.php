<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ForceJsonResponse
{
    /**
     * Ensure all responses are JSON and set proper Accept header.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            return $response;
        }

        // Convert non-JSON responses to JSON, con el mismo formato {success, message, error_code}
        // que usa el resto de la API (ver bootstrap/app.php) -- antes esto quedaba en inglés y
        // sin error_code, inconsistente con cualquier otro error de la API.
        if ($response instanceof Response) {
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al procesar la solicitud.',
                'error_code' => ApiErrorCode::fromHttpStatus($response->getStatusCode())->value,
                'data' => $response->getContent(),
            ], $response->getStatusCode());
        }

        return $response;
    }
}
