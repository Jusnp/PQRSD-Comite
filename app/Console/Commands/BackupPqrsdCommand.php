<?php

namespace App\Console\Commands;

use App\Models\RespaldoPqrsd;
use App\Services\Backup\BackupEncryption;
use App\Services\Backup\ExternalBackupStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BackupPqrsdCommand extends Command
{
    protected $signature = 'pqrsd:backup {--only=all : all|database|adjuntos}';

    protected $description = 'Genera respaldo verificable de BD y/o adjuntos (sin subir a Git)';

    public function handle(): int
    {
        $only = $this->option('only') ?: 'all';
        $stamp = now('America/Bogota')->format('Ymd-His');
        $dir = storage_path('app/private/backups/'.$stamp);
        File::ensureDirectoryExists($dir);

        $row = RespaldoPqrsd::create([
            'tipo' => $only,
            'ruta' => 'backups/'.$stamp,
            'estado' => 'running',
            'iniciada_en' => now('America/Bogota'),
        ]);

        try {
            $parts = [];
            if (in_array($only, ['all', 'database'], true)) {
                $parts[] = $this->backupDatabase($dir);
            }
            if (in_array($only, ['all', 'adjuntos'], true)) {
                $parts[] = $this->backupAdjuntos($dir);
            }

            $manifest = [
                'created_at' => now('America/Bogota')->toIso8601String(),
                'timezone' => 'America/Bogota',
                'parts' => $parts,
                'app' => config('app.name'),
                'env' => config('app.env'),
            ];
            $manifestPath = $dir.'/manifest.json';
            File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $checksum = hash_file('sha256', $manifestPath);
            $totalSize = collect(File::allFiles($dir))->sum(fn ($f) => $f->getSize());

            $cifrado = false;
            $destino = null;
            $checksumRemoto = null;
            $estadoTransfer = 'omitido';

            $bundle = $dir.'/bundle.zip';
            $this->zipDirectory($dir, $bundle, ['bundle.zip', 'bundle.zip.enc']);

            $encryptor = app(BackupEncryption::class);
            $toTransfer = $bundle;
            if ($encryptor->enabled()) {
                $encPath = $bundle.'.enc';
                $encryptor->encryptFile($bundle, $encPath);
                $toTransfer = $encPath;
                $cifrado = true;
                @unlink($bundle);
            }

            $transfer = app(ExternalBackupStore::class)->push(
                $toTransfer,
                $stamp.($cifrado ? '-bundle.zip.enc' : '-bundle.zip')
            );
            if ($transfer['ok']) {
                $destino = $transfer['destino'];
                $checksumRemoto = $transfer['checksum'];
                $estadoTransfer = 'ok';
            } elseif ($transfer['error'] && str_contains((string) $transfer['error'], 'no configurado')) {
                $estadoTransfer = 'pendiente_destino';
                $this->warn($transfer['error']);
            } else {
                $estadoTransfer = 'error';
                $this->error('Transferencia externa: '.($transfer['error'] ?? 'fallo'));
                if (config('pqrsd_backup.notify_on_failure')) {
                    Log::channel('respaldos')->error('Alerta: falla transferencia externa', [
                        'error' => $transfer['error'],
                    ]);
                }
            }

            $row->update([
                'estado' => 'ok',
                'checksum_sha256' => $checksum,
                'tamano' => $totalSize,
                'detalle' => 'partes: '.count($parts).'; transfer='.$estadoTransfer,
                'cifrado' => $cifrado,
                'destino_externo' => $destino,
                'checksum_remoto' => $checksumRemoto,
                'estado_transferencia' => $estadoTransfer,
                'finalizada_en' => now('America/Bogota'),
            ]);

            Log::channel('respaldos')->info('Backup OK', [
                'ruta' => $row->ruta,
                'checksum' => $checksum,
                'tamano' => $totalSize,
                'cifrado' => $cifrado,
                'transfer' => $estadoTransfer,
            ]);

            $this->info("Respaldo OK: {$dir}");
            $this->info("Checksum manifest: {$checksum}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $row->update([
                'estado' => 'error',
                'detalle' => $e->getMessage(),
                'finalizada_en' => now('America/Bogota'),
            ]);
            Log::channel('respaldos')->error('Backup FAIL', ['error' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array{type:string,path:string,checksum:string,bytes:int}
     */
    private function backupDatabase(string $dir): array
    {
        $path = $dir.'/database.sql';
        $connection = config('database.default');

        $sql = '-- PQRSD logical dump '.now('America/Bogota')->toDateTimeString()."\n";
        $tables = Schema::getTableListing();
        foreach ($tables as $table) {
            if (in_array($table, ['jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions'], true)) {
                continue;
            }
            $rows = DB::table($table)->get();
            $sql .= "\n-- TABLE {$table} ({$rows->count()} rows)\n";
            foreach ($rows as $row) {
                $data = (array) $row;
                $cols = array_map(fn ($c) => '`'.str_replace('`', '``', (string) $c).'`', array_keys($data));
                $vals = array_map(function ($v) {
                    if ($v === null) {
                        return 'NULL';
                    }

                    return "'".str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $v)."'";
                }, array_values($data));
                $sql .= 'INSERT INTO `'.$table.'` ('.implode(',', $cols).') VALUES ('.implode(',', $vals).");\n";
            }
        }
        File::put($path, $sql);

        if ($connection === 'sqlite') {
            $db = config('database.connections.sqlite.database');
            if (is_string($db) && is_file($db)) {
                File::copy($db, $dir.'/database.sqlite');
            }
        }

        return [
            'type' => 'database',
            'path' => basename($path),
            'checksum' => hash_file('sha256', $path),
            'bytes' => filesize($path) ?: 0,
        ];
    }

    /**
     * @return array{type:string,path:string,checksum:string,bytes:int,files:int}
     */
    private function backupAdjuntos(string $dir): array
    {
        $zipPath = $dir.'/adjuntos.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear adjuntos.zip');
        }

        $disk = Storage::disk(config('pqrsd_correo.adjuntos_disk', 'local'));
        $files = 0;
        foreach ($disk->allFiles() as $file) {
            // Excluir backups anidados
            if (str_starts_with($file, 'backups/')) {
                continue;
            }
            $zip->addFromString($file, $disk->get($file));
            $files++;
        }
        $zip->close();

        return [
            'type' => 'adjuntos',
            'path' => 'adjuntos.zip',
            'checksum' => hash_file('sha256', $zipPath),
            'bytes' => filesize($zipPath) ?: 0,
            'files' => $files,
        ];
    }

    /**
     * @param  array<int, string>  $exclude
     */
    private function zipDirectory(string $dir, string $zipPath, array $exclude = []): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear bundle.zip');
        }
        foreach (File::files($dir) as $file) {
            $name = $file->getFilename();
            if (in_array($name, $exclude, true)) {
                continue;
            }
            $zip->addFile($file->getPathname(), $name);
        }
        $zip->close();
    }
}
