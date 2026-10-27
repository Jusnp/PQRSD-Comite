<?php

namespace App\Console\Commands;

use App\Models\RespaldoPqrsd;
use App\Services\Backup\BackupEncryption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Restaura un paquete en directorio aislado. NUNCA escribe sobre la BD de producción.
 */
class RestoreBackupIsolatedCommand extends Command
{
    protected $signature = 'pqrsd:backup-restore-isolated
        {id : ID de respaldos_pqrsd}
        {--target= : Directorio destino (default storage/app/private/restore-lab/{id})}
        {--decrypt : Descifrar bundle.zip.enc si aplica}';

    protected $description = 'Restaura archivos de un respaldo en laboratorio aislado (no toca MySQL prod)';

    public function handle(BackupEncryption $encryption): int
    {
        $row = RespaldoPqrsd::findOrFail($this->argument('id'));
        $target = $this->option('target') ?: storage_path('app/private/restore-lab/'.$row->id);
        File::ensureDirectoryExists($target);

        $base = storage_path('app/private/'.$row->ruta);
        if (! is_dir($base)) {
            $this->error('Directorio de respaldo ausente: '.$base);

            return self::FAILURE;
        }

        $enc = $base.'/bundle.zip.enc';
        $zip = $base.'/bundle.zip';

        if ($this->option('decrypt') || (is_file($enc) && ! is_file($zip))) {
            if (! is_file($enc)) {
                $this->error('No hay bundle.zip.enc');

                return self::FAILURE;
            }
            if (! $encryption->enabled()) {
                $this->error('Defina PQRSD_BACKUP_ENCRYPT=true y PQRSD_BACKUP_ENCRYPTION_KEY para descifrar');

                return self::FAILURE;
            }
            $tmp = $target.'/bundle.zip';
            try {
                $encryption->decryptFile($enc, $tmp);
            } catch (\Throwable $e) {
                $this->error('Descifrado falló (corrupción o clave): '.$e->getMessage());

                return self::FAILURE;
            }
            $zip = $tmp;
        }

        if (is_file($zip)) {
            $za = new ZipArchive;
            if ($za->open($zip) !== true) {
                $this->error('ZIP inválido');

                return self::FAILURE;
            }
            $za->extractTo($target);
            $za->close();
        } else {
            // Fallback: copiar partes sueltas
            foreach (File::files($base) as $file) {
                File::copy($file->getPathname(), $target.'/'.$file->getFilename());
            }
        }

        $manifest = $target.'/manifest.json';
        if (! is_file($manifest)) {
            $this->warn('Manifest no encontrado en destino; verificar integridad manualmente.');
        } else {
            $this->info('Manifest OK en laboratorio: '.$manifest);
        }

        $this->warn('IMPORTANTE: este comando NO importa SQL a MySQL. Use un servidor de laboratorio y mysql < database.sql de forma manual/autorizada.');
        $this->info('Archivos restaurados en: '.$target);

        return self::SUCCESS;
    }
}
