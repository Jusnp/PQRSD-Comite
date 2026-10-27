<?php

namespace App\Services\Backup;

use RuntimeException;

class BackupEncryption
{
    public function enabled(): bool
    {
        return (bool) config('pqrsd_backup.encryption_enabled')
            && filled(config('pqrsd_backup.encryption_key'));
    }

    public function encryptFile(string $sourcePath, string $destPath): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Cifrado de respaldos no habilitado o falta PQRSD_BACKUP_ENCRYPTION_KEY');
        }

        $key = $this->keyBytes();
        $iv = random_bytes(16);
        $plaintext = file_get_contents($sourcePath);
        if ($plaintext === false) {
            throw new RuntimeException('No se pudo leer archivo a cifrar');
        }

        $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Fallo openssl_encrypt');
        }

        // Formato: PQRSDBAK1 | iv | ciphertext
        $payload = 'PQRSDBAK1'.$iv.$ciphertext;
        if (file_put_contents($destPath, $payload) === false) {
            throw new RuntimeException('No se pudo escribir archivo cifrado');
        }

        return hash_file('sha256', $destPath) ?: '';
    }

    public function decryptFile(string $sourcePath, string $destPath): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Cifrado no habilitado');
        }

        $raw = file_get_contents($sourcePath);
        if ($raw === false || ! str_starts_with($raw, 'PQRSDBAK1')) {
            throw new RuntimeException('Formato de respaldo cifrado inválido o corrupto');
        }

        $iv = substr($raw, 9, 16);
        $ciphertext = substr($raw, 25);
        $plain = openssl_decrypt($ciphertext, 'AES-256-CBC', $this->keyBytes(), OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new RuntimeException('Descifrado fallido (clave incorrecta o archivo corrupto)');
        }

        file_put_contents($destPath, $plain);
    }

    private function keyBytes(): string
    {
        $material = (string) config('pqrsd_backup.encryption_key');

        return hash('sha256', $material, true);
    }
}
