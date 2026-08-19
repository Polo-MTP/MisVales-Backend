<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | VPN host
    |--------------------------------------------------------------------------
    |
    | Dominio/subdominio (sin protocolo, p.ej. "vpn.misvales.com") desde el que deben
    | contestar los endpoints de decisión (aprobar/rechazar) marcados con el middleware
    | 'vpn' (ver App\Http\Middleware\VerifyVpnAccess). La detección es solo por el host de
    | la petición, no por IP — infra publica esas rutas en un dominio aparte, resoluble
    | únicamente desde dentro de la VPN. Vacío = no bloquea nada — la restricción real va
    | en el firewall/DNS del droplet, esto es solo una segunda capa de defensa.
    |
    */

    'vpn_host' => trim((string) env('VPN_HOST', '')),

    // TRUSTED_PROXIES (para el balanceador) NO vive aquí a propósito: se configura
    // directo en bootstrap/app.php con env(), porque ese archivo corre antes de que el
    // contenedor tenga registrado el binding 'config' — llamar config() ahí tumba el
    // boot de la app por completo. Es de los pocos lugares donde env() directo sigue
    // siendo el patrón correcto (ver el comentario junto a trustProxies() ahí mismo). Ya
    // no lo usa VerifyVpnAccess (ver arriba, ahora es por host), pero sigue siendo
    // necesario para que $request->ip() sea confiable en throttle/audit log.

];
