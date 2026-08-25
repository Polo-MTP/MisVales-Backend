<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agrega un código corto y estable (ej. "MV-301") como campo 'support_code' a CUALQUIER
 * respuesta de error de la API, para que el usuario pueda anotarlo y reportarlo a soporte sin
 * tener que describir el problema o pegar una captura. Es un concepto distinto de 'error_code'
 * (ApiErrorCode->value): ese es el identificador técnico en inglés que usa el FRONTEND para
 * decidir lógica; este es para que lo lea una PERSONA.
 *
 * A propósito NO se pega dentro de 'message' aquí -- decenas de tests en toda la suite hacen
 * match exacto contra ese texto (assertJsonPath('message', '...')), y cambiarlo desde el
 * backend los habría roto a todos sin necesidad. El frontend es quien lo muestra junto al
 * mensaje (ver auth.interceptor.ts), en un solo lugar global, sin tocar cada pantalla.
 *
 * Global y como el primero de la cadena (igual que SecurityHeaders, ver ese archivo) para
 * cubrir absolutamente cualquier respuesta de error sin tener que tocar cada controller,
 * middleware o service que arma una a mano -- incluidas las de bootstrap/app.php, el login
 * (que arma su propio {message, code} en LoginService), y la fallback route del paquete de
 * rutas. Una excepción no atrapada ya se convirtió en Response antes de "burbujear" de vuelta
 * hasta aquí, así que el código se agrega igual.
 */
final class AppendSupportCode
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->getData(true);

        if (! is_array($data) || ($data['success'] ?? true) !== false || empty($data['error_code'])) {
            return $response;
        }

        $errorCode = ApiErrorCode::tryFrom((string) $data['error_code']);
        if (! $errorCode) {
            return $response;
        }

        $data['support_code'] = $errorCode->supportCode();

        return $response->setData($data);
    }
}
