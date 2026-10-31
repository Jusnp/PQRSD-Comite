<?php

namespace App\Services\Migracion;

use RuntimeException;
use Throwable;

/**
 * Copia un adjunto solo después de que la transacción de base de datos termina bien.
 * Si esa transacción falla, borra el archivo temporal y no deja el destino.
 */
class CopiaAdjuntoReversible
{
    public function copiarSiTransaccionExitosa(string $origen, string $destino, string $sha256, callable $transaccionBd): void
    {
        if (! is_file($origen)) {
            throw new RuntimeException('origen-ausente');
        }
        if (is_file($destino)) {
            throw new RuntimeException('destino-existe');
        }
        if (! hash_equals(strtolower($sha256), hash_file('sha256', $origen) ?: '')) {
            throw new RuntimeException('hash-origen');
        }

        $directorio = dirname($destino);
        if (! is_dir($directorio) && ! mkdir($directorio, 0755, true) && ! is_dir($directorio)) {
            throw new RuntimeException('directorio');
        }

        $staging = $directorio.DIRECTORY_SEPARATOR.'.staging-'.bin2hex(random_bytes(8));
        if (! copy($origen, $staging)) {
            throw new RuntimeException('copia');
        }

        $promovido = false;
        try {
            if (! hash_equals(strtolower($sha256), hash_file('sha256', $staging) ?: '')) {
                throw new RuntimeException('hash-staging');
            }

            $transaccionBd();

            if (! rename($staging, $destino)) {
                throw new RuntimeException('promover');
            }
            $promovido = true;

            if (! hash_equals(strtolower($sha256), hash_file('sha256', $destino) ?: '')) {
                throw new RuntimeException('hash-destino');
            }
        } catch (Throwable $e) {
            if (is_file($staging)) {
                unlink($staging);
            }
            if ($promovido && is_file($destino)) {
                unlink($destino);
            }

            throw $e;
        }
    }
}
