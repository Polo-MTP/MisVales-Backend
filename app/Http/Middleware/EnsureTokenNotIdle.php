<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra la sesión (revoca el token) si pasó demasiado tiempo sin actividad — independiente
 * de la expiración absoluta del token (ver config/sanctum.php 'expiration').
 *
 * Usa su propia columna 'last_activity_at' en vez de 'last_used_at' de Sanctum: ese campo lo
 * actualiza el propio Sanctum a "ahora" durante la autenticación de ESTA MISMA petición (en
 * 'auth:sanctum', que corre antes que este middleware) — para cuando llegamos aquí siempre
 * diría "recién usado" y jamás detectaría inactividad. 'last_activity_at' es de este
 * middleware nada más, así que sí conserva el valor de la petición anterior hasta que la
 * actualizamos al final de este mismo método.
 *
 * $token->exists (no solo `instanceof`): Sanctum::actingAs(), que usan casi todos los tests
 * de este proyecto, autentica con un mock de Mockery del modelo — pasa el `instanceof`, pero
 * no es un registro real de base de datos y forceFill()/save() truena sobre él. `exists` es
 * una propiedad pública normal de Eloquent (no pasa por __get mágico), así que en el mock se
 * queda en su valor por defecto (false) aunque el resto del objeto esté simulado.
 */
final class EnsureTokenNotIdle
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! $token->exists) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $minutosInactividadPermitidos = (int) config('security.idle_timeout_minutes', 15);
        $ultimaActividad = $token->last_activity_at;

        // abs(): Carbon 3 cambió el default de diffInMinutes() a devolver el valor CON signo
        // (antes de la 3, siempre regresaba la diferencia absoluta) — sin esto, como
        // $ultimaActividad siempre queda en el pasado respecto a "ahora", el resultado es
        // negativo y la comparación ">" nunca se cumple.
        if ($ultimaActividad !== null && abs(now()->diffInMinutes($ultimaActividad)) > $minutosInactividadPermitidos) {
            $token->delete();

            return response()->json([
                'success' => false,
                'message' => 'Tu sesión se cerró por inactividad. Inicia sesión de nuevo.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $token->forceFill(['last_activity_at' => now()])->save();

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
