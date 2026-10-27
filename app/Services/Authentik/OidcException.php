<?php

namespace App\Services\Authentik;

use RuntimeException;

class OidcException extends RuntimeException
{
    public function __construct(public readonly string $codigo)
    {
        parent::__construct($codigo);
    }
}
