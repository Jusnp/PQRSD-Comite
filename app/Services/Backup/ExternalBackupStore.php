<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Adaptador de almacenamiento externo. No inventa destinos:
 * - null: no transfiere (documentar dependencia)
 * - local_staging: copia a disco staging institucional en el mismo host (no offsite)
 * - s3: usa disk Laravel configurado (requiere credenciales reales)
 */
class ExternalBackupStore
{
    /**
     * @return array{ok:bool,destino:?string,checksum:?string,error:?string}
     */
    public function push(string $localPath, string $remoteName): array
    {
        $driver = config('pqrsd_backup.external_driver', 'null');

        if ($driver === 'null' || $driver === '' || $driver === null) {
            return [
                'ok' => false,
                'destino' => null,
                'checksum' => null,
                'error' => 'Destino externo no configurado (PQRSD_BACKUP_EXTERNAL_DRIVER=null). Pendiente SSI.',
            ];
        }

        try {
            if ($driver === 'local_staging') {
                $staging = storage_path('app/private/backup-staging');
                if (! is_dir($staging)) {
                    mkdir($staging, 0750, true);
                }
                $dest = $staging.'/'.$remoteName;
                if (! copy($localPath, $dest)) {
                    throw new RuntimeException('No se pudo copiar a staging');
                }
                $checksum = hash_file('sha256', $dest);

                return ['ok' => true, 'destino' => 'local_staging:'.$dest, 'checksum' => $checksum, 'error' => null];
            }

            if ($driver === 's3') {
                $disk = Storage::disk(config('pqrsd_backup.external_disk', 's3'));
                $key = rtrim((string) config('pqrsd_backup.external_prefix'), '/').'/'.$remoteName;
                $disk->put($key, fopen($localPath, 'r'));
                // Verificación: re-leer hash no siempre disponible en S3; registramos hash local como referencia.
                $checksum = hash_file('sha256', $localPath);

                Log::channel('respaldos')->info('Backup transferido a S3', [
                    'key' => $key,
                    'checksum_local' => $checksum,
                ]);

                return ['ok' => true, 'destino' => 's3:'.$key, 'checksum' => $checksum, 'error' => null];
            }

            return [
                'ok' => false,
                'destino' => null,
                'checksum' => null,
                'error' => 'Driver externo desconocido: '.$driver,
            ];
        } catch (\Throwable $e) {
            Log::channel('respaldos')->error('Transferencia externa falló', [
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'destino' => null,
                'checksum' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
