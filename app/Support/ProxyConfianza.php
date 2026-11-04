<?php

namespace App\Support;

final class ProxyConfianza
{
    /**
     * Direcciones de proxy aceptadas. Un comodín no concede confianza.
     *
     * @return list<string>
     */
    public static function proxies(string $lista): array
    {
        $proxies = [];

        foreach (explode(',', $lista) as $parte) {
            $parte = trim($parte);
            if ($parte === '' || $parte === '*' || $parte === '**') {
                continue;
            }
            $proxies[] = $parte;
        }

        return array_values(array_unique($proxies));
    }

    /**
     * Patrones de host exactos. Incluye el loopback para el chequeo local de Nginx.
     *
     * @return list<string>
     */
    public static function hosts(string $lista, ?string $appUrl, ?string $publicUrl): array
    {
        $nombres = ['127.0.0.1', 'localhost'];

        foreach ([$appUrl, $publicUrl] as $url) {
            $host = parse_url((string) $url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $nombres[] = $host;
            }
        }

        foreach (explode(',', $lista) as $parte) {
            $parte = trim($parte);
            if ($parte !== '' && $parte !== '*') {
                $nombres[] = $parte;
            }
        }

        return array_values(array_unique(array_map(
            static fn (string $host): string => '^'.preg_quote($host, '/').'$',
            $nombres
        )));
    }
}
