<?php

namespace App\Mail\Gmail;

use RuntimeException;

class GmailApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly bool $historyExpired = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function authFailed(string $detalle): self
    {
        return new self('Autenticación Gmail fallida: '.$detalle, 401);
    }

    public static function temporary(string $detalle, int $status = 503): self
    {
        return new self('Error temporal Gmail: '.$detalle, $status);
    }

    public static function historyExpired(string $detalle): self
    {
        return new self('historyId inválido o expirado: '.$detalle, 404, true);
    }
}
