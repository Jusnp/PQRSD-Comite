<?php

namespace App\Console\Commands;

use App\Models\RespaldoPqrsd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class VerifyBackupCommand extends Command
{
    protected $signature = 'pqrsd:backup-verify {id? : ID de respaldos_pqrsd}';

    protected $description = 'Verifica integridad (checksum) de un respaldo registrado';

    public function handle(): int
    {
        $row = $this->argument('id')
            ? RespaldoPqrsd::findOrFail($this->argument('id'))
            : RespaldoPqrsd::query()->where('estado', 'ok')->latest('id')->first();

        if (! $row) {
            $this->error('No hay respaldos OK.');

            return self::FAILURE;
        }

        $manifest = storage_path('app/private/'.$row->ruta.'/manifest.json');
        if (! is_file($manifest)) {
            $this->error('Manifest ausente: '.$manifest);

            return self::FAILURE;
        }

        $actual = hash_file('sha256', $manifest);
        if ($row->checksum_sha256 && ! hash_equals($row->checksum_sha256, $actual)) {
            $this->error('Checksum mismatch');

            return self::FAILURE;
        }

        $data = json_decode(File::get($manifest), true);
        foreach ($data['parts'] ?? [] as $part) {
            $path = storage_path('app/private/'.$row->ruta.'/'.$part['path']);
            if (! is_file($path)) {
                $this->error('Parte faltante: '.$part['path']);

                return self::FAILURE;
            }
            if (! empty($part['checksum']) && ! hash_equals($part['checksum'], hash_file('sha256', $path))) {
                $this->error('Checksum parte inválido: '.$part['path']);

                return self::FAILURE;
            }
        }

        $this->info("Respaldo #{$row->id} íntegro.");

        return self::SUCCESS;
    }
}
