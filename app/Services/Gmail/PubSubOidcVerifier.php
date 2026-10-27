<?php

namespace App\Services\Gmail;

use Illuminate\Support\Facades\Http;

/**
 * Verifica tokens OIDC de push Pub/Sub (Google).
 * En tests se puede sustituir por FakePubSubOidcVerifier.
 */
class PubSubOidcVerifier
{
    public function verify(?string $bearerToken, string $expectedAudience): bool
    {
        if (! $bearerToken || $expectedAudience === '') {
            return false;
        }

        // Modo desarrollo: aceptar solo si se habilita explícitamente (nunca en production check).
        if (config('pqrsd_correo.gmail.pubsub_skip_oidc') && config('app.env') !== 'production') {
            return $bearerToken === config('pqrsd_correo.gmail.pubsub_dev_token');
        }

        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $bearerToken,
        ]);

        if (! $response->successful()) {
            return false;
        }

        $json = $response->json() ?? [];
        $aud = $json['aud'] ?? $json['azp'] ?? null;
        $email = $json['email'] ?? null;
        $iss = $json['iss'] ?? '';
        $exp = isset($json['exp']) ? (int) $json['exp'] : 0;

        $issuerOk = in_array($iss, [
            'https://accounts.google.com',
            'accounts.google.com',
        ], true);

        $audienceOk = $aud === $expectedAudience;
        $emailOk = blank(config('pqrsd_correo.gmail.pubsub_service_account'))
            || strtolower((string) $email) === strtolower((string) config('pqrsd_correo.gmail.pubsub_service_account'));
        $expOk = $exp === 0 || $exp >= time();

        return $issuerOk && $audienceOk && $emailOk && $expOk;
    }
}
