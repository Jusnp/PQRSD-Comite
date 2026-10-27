<?php

namespace App\Services;

use RuntimeException;

class ConflictoIdempotenciaPublica extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Este formulario ya se utilizó con otros datos.');
    }
}
