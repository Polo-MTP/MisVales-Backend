<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | VPN allowed CIDRs
    |--------------------------------------------------------------------------
    |
    | Rango(s) de IP de la VPN con acceso a los endpoints de decisión (aprobar/rechazar)
    | marcados con el middleware 'vpn' (ver App\Http\Middleware\VerifyVpnAccess). Vacío =
    | no bloquea nada — la restricción real va en el firewall del droplet, esto es solo
    | una segunda capa de defensa.
    |
    */

    'vpn_allowed_cidrs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VPN_ALLOWED_CIDRS', ''))
    ))),

    // TRUSTED_PROXIES (para el balanceador) NO vive aquí a propósito: se configura
    // directo en bootstrap/app.php con env(), porque ese archivo corre antes de que el
    // contenedor tenga registrado el binding 'config' — llamar config() ahí tumba el
    // boot de la app por completo. Es de los pocos lugares donde env() directo sigue
    // siendo el patrón correcto (ver el comentario junto a trustProxies() ahí mismo).

];
