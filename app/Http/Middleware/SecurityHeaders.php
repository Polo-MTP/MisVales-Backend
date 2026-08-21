<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Este middleware solo corre en el grupo 'api' (ver bootstrap/app.php) -- nunca en
        // 'web' -- así que ninguna respuesta bajo este header es HTML con scripts/estilos
        // inline reales. 'unsafe-inline' no protege nada aquí y solo debilita la política.
        $response->headers->set('Content-Security-Policy', "default-src 'self'; connect-src 'self' https://www.google.com/recaptcha/; script-src 'self' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; style-src 'self'; frame-src https://www.google.com/recaptcha/ https://www.google.com/recaptcha/api2/");

        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
