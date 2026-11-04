<?php

use App\Support\ProxyConfianza;

return [

    /*
    |--------------------------------------------------------------------------
    | Proxies de confianza
    |--------------------------------------------------------------------------
    |
    | Lista separada por comas con IP o CIDR del proxy que termina TLS.
    | Vacía: Laravel no acepta X-Forwarded-* de la petición. El valor *
    | se descarta a propósito.
    |
    */

    'trusted_proxies' => ProxyConfianza::proxies((string) env('TRUSTED_PROXIES', '')),

    /*
    |--------------------------------------------------------------------------
    | Hosts de confianza
    |--------------------------------------------------------------------------
    |
    | Host exacto de APP_URL, de PQRSD_PUBLIC_URL y de TRUSTED_HOSTS.
    | No se habilitan subdominios por omisión. 127.0.0.1 queda incluido
    | para el healthcheck de Nginx dentro del contenedor.
    |
    */

    'trusted_hosts' => ProxyConfianza::hosts(
        (string) env('TRUSTED_HOSTS', ''),
        env('APP_URL'),
        env('PQRSD_PUBLIC_URL'),
    ),

];
