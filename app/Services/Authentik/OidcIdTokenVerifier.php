<?php

namespace App\Services\Authentik;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Valida el ID Token del proveedor CEM PQRSD contra su documento de descubrimiento.
 * El issuer aceptado es el publicado por ese documento, no una URL local.
 */
class OidcIdTokenVerifier
{
    /**
     * @return array<string, mixed>
     */
    public function verificar(?string $idToken, ?string $nonce = null): array
    {
        if (! is_string($idToken) || substr_count($idToken, '.') !== 2) {
            throw new OidcException('firma');
        }

        [$encabezado64, $cuerpo64, $firma64] = explode('.', $idToken);
        $encabezado = json_decode($this->base64Url($encabezado64), true);
        $claims = json_decode($this->base64Url($cuerpo64), true);
        if (! is_array($encabezado) || ! is_array($claims)) {
            throw new OidcException('firma');
        }
        if (($encabezado['alg'] ?? '') !== 'RS256') {
            throw new OidcException('firma');
        }

        $documento = $this->descubrimiento();
        $firma = $this->base64Url($firma64);
        $pem = $this->pemDesdeJwks((string) $documento['jwks_uri'], (string) ($encabezado['kid'] ?? ''));
        $valida = openssl_verify($encabezado64.'.'.$cuerpo64, $firma, $pem, OPENSSL_ALGO_SHA256);
        if ($valida !== 1) {
            throw new OidcException('firma');
        }

        $issuer = (string) ($claims['iss'] ?? '');
        if ($issuer === '' || ! hash_equals((string) $documento['issuer'], $issuer)) {
            throw new OidcException('issuer');
        }

        $audiencia = $claims['aud'] ?? null;
        $cliente = (string) config('services.authentik.client_id');
        $audiencias = is_array($audiencia) ? $audiencia : [$audiencia];
        if ($cliente === '' || ! in_array($cliente, $audiencias, true)) {
            throw new OidcException('audience');
        }

        $expira = $claims['exp'] ?? null;
        if (! is_numeric($expira) || (int) $expira < time() - 120) {
            throw new OidcException('expiracion');
        }

        $emitido = $claims['nbf'] ?? $claims['iat'] ?? null;
        if (is_numeric($emitido) && (int) $emitido > time() + 120) {
            throw new OidcException('expiracion');
        }

        if (! is_string($nonce) || $nonce === '' || ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new OidcException('nonce');
        }

        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new OidcException('firma');
        }

        return $claims;
    }

    /**
     * @return array{issuer:string,jwks_uri:string}
     */
    private function descubrimiento(): array
    {
        $base = rtrim((string) config('authentik.base_url', config('services.authentik.base_url')), '/');
        $slug = (string) config('authentik.app_slug', 'pqrsd-comite');
        if (! str_starts_with($base, 'https://') || $slug === '') {
            throw new OidcException('issuer');
        }

        $url = $base.'/application/o/'.$slug.'/.well-known/openid-configuration';
        $documento = Cache::remember('oidc.descubrimiento.'.sha1($url), 300, function () use ($url, $base) {
            $respuesta = Http::acceptJson()->timeout(15)->get($url);
            if (! $respuesta->successful()) {
                throw new OidcException('issuer');
            }

            return [
                'issuer' => (string) $respuesta->json('issuer'),
                'jwks_uri' => (string) $respuesta->json('jwks_uri'),
                'base' => $base,
            ];
        });

        $issuer = (string) ($documento['issuer'] ?? '');
        $jwks = (string) ($documento['jwks_uri'] ?? '');
        if ($issuer === '' || $jwks === ''
            || ! str_starts_with($issuer, $base)
            || ! str_starts_with($jwks, $base)) {
            Cache::forget('oidc.descubrimiento.'.sha1($url));
            throw new OidcException('issuer');
        }

        return ['issuer' => $issuer, 'jwks_uri' => $jwks];
    }

    private function pemDesdeJwks(string $jwksUri, string $kid): string
    {
        $documento = Cache::remember('oidc.jwks.'.sha1($jwksUri), 300, function () use ($jwksUri) {
            $respuesta = Http::acceptJson()->timeout(15)->get($jwksUri);
            if (! $respuesta->successful()) {
                throw new OidcException('firma');
            }

            return $respuesta->json();
        });

        $claves = is_array($documento) ? ($documento['keys'] ?? []) : [];
        $jwk = null;
        foreach ($claves as $candidata) {
            if (! is_array($candidata) || ($candidata['kty'] ?? '') !== 'RSA') {
                continue;
            }
            if ($kid === '' || (string) ($candidata['kid'] ?? '') === $kid) {
                $jwk = $candidata;
                break;
            }
        }
        if ($jwk === null || ! isset($jwk['n'], $jwk['e'])) {
            throw new OidcException('firma');
        }

        return $this->pemRsa((string) $jwk['n'], (string) $jwk['e']);
    }

    private function pemRsa(string $modulo, string $exponente): string
    {
        $modulo = $this->entero($this->base64Url($modulo));
        $exponente = $this->entero($this->base64Url($exponente));
        $cuerpo = $this->der(0x02, $modulo).$this->der(0x02, $exponente);
        $secuencia = $this->der(0x30, $cuerpo);
        $bit = $this->der(0x03, "\x00".$secuencia);
        $algoritmo = hex2bin('300d06092a864886f70d0101010500');
        $spki = $this->der(0x30, $algoritmo.$bit);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function entero(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return $bytes;
    }

    private function der(int $etiqueta, string $contenido): string
    {
        $longitud = strlen($contenido);
        if ($longitud < 128) {
            return chr($etiqueta).chr($longitud).$contenido;
        }

        $largo = ltrim(pack('N', $longitud), "\x00");

        return chr($etiqueta).chr(0x80 | strlen($largo)).$largo.$contenido;
    }

    private function base64Url(string $valor): string
    {
        $resto = strlen($valor) % 4;
        if ($resto !== 0) {
            $valor .= str_repeat('=', 4 - $resto);
        }

        $binario = base64_decode(strtr($valor, '-_', '+/'), true);

        return is_string($binario) ? $binario : '';
    }
}
