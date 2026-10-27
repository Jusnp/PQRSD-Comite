<?php

namespace Tests\Feature;

use App\Models\RespaldoPqrsd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_genera_respaldo_con_integridad(): void
    {
        Storage::fake(config('pqrsd_correo.adjuntos_disk', 'local'));
        Storage::disk(config('pqrsd_correo.adjuntos_disk', 'local'))
            ->put('pqrsd/1/demo.txt', 'evidencia-ficticia');

        $code = Artisan::call('pqrsd:backup', ['--only' => 'all']);
        $this->assertSame(0, $code);

        $row = RespaldoPqrsd::query()->where('estado', 'ok')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertNotEmpty($row->checksum_sha256);

        $verify = Artisan::call('pqrsd:backup-verify', ['id' => $row->id]);
        $this->assertSame(0, $verify);

        $manifest = storage_path('app/private/'.$row->ruta.'/manifest.json');
        $this->assertFileExists($manifest);
        $data = json_decode(file_get_contents($manifest), true);
        $this->assertNotEmpty($data['parts']);
    }
}
