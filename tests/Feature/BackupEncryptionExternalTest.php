<?php

namespace Tests\Feature;

use App\Models\RespaldoPqrsd;
use App\Services\Backup\BackupEncryption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupEncryptionExternalTest extends TestCase
{
    use RefreshDatabase;

    public function test_cifrado_descifrado_y_deteccion_corrupcion(): void
    {
        config([
            'pqrsd_backup.encryption_enabled' => true,
            'pqrsd_backup.encryption_key' => 'clave-institucional-de-prueba-no-prod',
            'pqrsd_backup.external_driver' => 'local_staging',
        ]);

        Storage::fake(config('pqrsd_correo.adjuntos_disk', 'local'));
        Storage::disk(config('pqrsd_correo.adjuntos_disk', 'local'))->put('pqrsd/lab/a.txt', 'evidencia');

        $this->assertSame(0, Artisan::call('pqrsd:backup', ['--only' => 'adjuntos']));
        $row = RespaldoPqrsd::where('estado', 'ok')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->cifrado);
        $this->assertSame('ok', $row->estado_transferencia);

        $enc = storage_path('app/private/'.$row->ruta.'/bundle.zip.enc');
        $this->assertFileExists($enc);

        $lab = storage_path('app/private/restore-lab-test');
        File::deleteDirectory($lab);
        $this->assertSame(0, Artisan::call('pqrsd:backup-restore-isolated', [
            'id' => $row->id,
            '--target' => $lab,
            '--decrypt' => true,
        ]));
        $this->assertFileExists($lab.'/manifest.json');

        // Corrupción
        file_put_contents($enc, 'PQRSDBAK1'.str_repeat('x', 64));
        $broken = storage_path('app/private/restore-lab-broken');
        File::deleteDirectory($broken);
        $code = Artisan::call('pqrsd:backup-restore-isolated', [
            'id' => $row->id,
            '--target' => $broken,
            '--decrypt' => true,
        ]);
        $this->assertSame(1, $code);
    }

    public function test_destino_externo_null_queda_pendiente_sin_fallar_backup_local(): void
    {
        config([
            'pqrsd_backup.encryption_enabled' => false,
            'pqrsd_backup.encryption_key' => null,
            'pqrsd_backup.external_driver' => 'null',
        ]);
        Storage::fake(config('pqrsd_correo.adjuntos_disk', 'local'));
        Storage::disk(config('pqrsd_correo.adjuntos_disk', 'local'))->put('pqrsd/lab/b.txt', 'x');

        $code = Artisan::call('pqrsd:backup', ['--only' => 'adjuntos']);
        if ($code !== 0) {
            $this->fail('backup exit '.$code.': '.Artisan::output());
        }
        $row = RespaldoPqrsd::latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('pendiente_destino', $row->estado_transferencia);
    }

    public function test_encrypt_sin_clave_lanza_error_controlado(): void
    {
        config([
            'pqrsd_backup.encryption_enabled' => true,
            'pqrsd_backup.encryption_key' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        app(BackupEncryption::class)->encryptFile(__FILE__, storage_path('app/private/x.enc'));
    }
}
