<?php

namespace Tests\Support;

use App\Services\Authentik\OidcException;
use App\Services\Authentik\OidcIdTokenVerifier;

/**
 * Sustituye la verificación criptográfica en pruebas de flujo.
 * Las pruebas del verificador real no usan esta clase.
 */
class FakeOidcIdTokenVerifier extends OidcIdTokenVerifier
{
    /** @var array<string, mixed> */
    public array $claims = [];

    public ?string $error = null;

    public function verificar(?string $idToken, ?string $nonce = null): array
    {
        if ($this->error !== null) {
            throw new OidcException($this->error);
        }

        return $this->claims;
    }
}
