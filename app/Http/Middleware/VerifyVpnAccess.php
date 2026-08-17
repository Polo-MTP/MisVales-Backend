<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Segunda capa (defensa adicional) sobre el firewall/red real, que es quien de verdad
 * restringe estas rutas al rango de la VPN a nivel de infraestructura — ocultar el botón
 * en el frontend no cuenta como seguridad, cualquiera puede pegarle al endpoint directo.
 *
 * Mientras VPN_ALLOWED_CIDRS no esté configurado (infra todavía no da el rango real de la
 * VPN), este middleware no bloquea nada: no tiene caso tumbar estas rutas en dev/test
 * antes de tener el dato real. Lee config('security.vpn_allowed_cidrs') en vez de env()
 * directo — así se puede reconfigurar en caliente en tests con config()->set(), y sigue
 * la regla de nunca leer env() fuera de un archivo de config.
 */
final class VerifyVpnAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $cidrs */
        $cidrs = config('security.vpn_allowed_cidrs', []);

        if ($cidrs === [] || IpUtils::checkIp($request->ip() ?? '', $cidrs)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        return response()->json([
            'success' => false,
            'message' => 'Esta acción solo está disponible desde la red autorizada.',
        ], 403);
    }
}
