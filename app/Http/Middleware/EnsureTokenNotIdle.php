<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra la sesión si pasó demasiado tiempo sin actividad — independiente de la expiración
 * absoluta de la sesión (ver config/session.php 'lifetime').
 *
 * La sesión se autentica por cookie httpOnly (ver bootstrap/app.php: statefulApi()), así que el
 * rastro de actividad vive en la propia sesión de Laravel ('last_activity_at' en el store de
 * sesión), no en una fila de token como antes -- no hay una fila de "este dispositivo" aparte
 * que borrar, cerrar la sesión actual es la operación completa.
 */
final class EnsureTokenNotIdle
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->user()) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $minutosInactividadPermitidos = (int) config('security.idle_timeout_minutes', 15);
        $ultimaActividad = $request->session()->get('last_activity_at');

        // abs(): Carbon 3 cambió el default de diffInMinutes() a devolver el valor CON signo
        // (antes de la 3, siempre regresaba la diferencia absoluta) — sin esto, como
        // $ultimaActividad siempre queda en el pasado respecto a "ahora", el resultado es
        // negativo y la comparación ">" nunca se cumple.
        if ($ultimaActividad !== null && abs(now()->diffInMinutes($ultimaActividad)) > $minutosInactividadPermitidos) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'success' => false,
                'message' => 'Tu sesión se cerró por inactividad. Inicia sesión de nuevo.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $request->session()->put('last_activity_at', now());

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
