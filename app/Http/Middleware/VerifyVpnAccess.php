<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Segunda capa (defensa adicional) sobre el firewall/red real, que es quien de verdad
 * restringe estas rutas a la VPN a nivel de infraestructura — ocultar el botón en el
 * frontend no cuenta como seguridad, cualquiera puede pegarle al endpoint directo.
 *
 * Solo exige VPN a Gerente General y Gerente de Sucursal — son quienes de verdad autorizan
 * (aprueban/rechazan/deciden). Verificador y Coordinador comparten ruta con ellos en varios
 * endpoints, pero su parte del flujo (dictaminar, verificar) no requiere VPN; se les deja
 * pasar sin más, aunque lleguen por la red pública. Este middleware va DESPUÉS de 'role:' en
 * cada ruta, así que $request->user() ya viene autenticado cuando corre.
 *
 * Detecta VPN vs. red pública únicamente por el host con el que se llegó a la petición
 * (`Request::getHost()`), no por IP/CIDR: evita depender de que el balanceador reenvíe
 * bien X-Forwarded-For y de que TRUSTED_PROXIES esté configurado con la IP interna
 * correcta para que $request->ip() sea confiable. Infra publica estas rutas en un
 * dominio/subdominio aparte (solo resoluble/enrutable desde dentro de la VPN) y ese es el
 * único dato que este middleware necesita.
 *
 * Mientras VPN_HOST no esté configurado (infra todavía no da el dominio real), este
 * middleware no bloquea nada: no tiene caso tumbar estas rutas en dev/test antes de tener
 * el dato real. Lee config('security.vpn_host') en vez de env() directo — así se puede
 * reconfigurar en caliente en tests con config()->set(), y sigue la regla de nunca leer
 * env() fuera de un archivo de config.
 */
final class VerifyVpnAccess
{
    private const ROLES_QUE_REQUIEREN_VPN = ['Gerente General', 'Gerente de Sucursal'];

    public function handle(Request $request, Closure $next): Response
    {
        $rol = $request->user()?->role?->name;

        if (! in_array($rol, self::ROLES_QUE_REQUIEREN_VPN, true)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        /** @var string $vpnHost */
        $vpnHost = config('security.vpn_host', '');

        if ($vpnHost === '' || mb_strtolower($request->getHost()) === mb_strtolower($vpnHost)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        return response()->json([
            'success' => false,
            'message' => 'Esta acción solo está disponible desde la red autorizada.',
            'error_code' => ApiErrorCode::VPN_REQUIRED->value,
        ], 403);
    }
}
