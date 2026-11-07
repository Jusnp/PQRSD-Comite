<?php

namespace Tests\Unit;

use App\Support\ArchivosQrFormulario;
use App\Support\QrPngGenerator;
use Illuminate\Support\Facades\File;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Tests\TestCase;

class ArchivosQrFormularioTest extends TestCase
{
    private string $directorio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directorio = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pqrsd-qr-'.bin2hex(random_bytes(4));
        mkdir($this->directorio, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directorio);
        parent::tearDown();
    }

    public function test_genera_archivos_coherentes_para_desarrollo_y_para_la_url_publica(): void
    {
        $local = 'http://127.0.0.1:8010/publico/pqrsd?qr=1';
        $publica = 'https://pqrsd.comitedeestudiosmedicos.com.co/publico/pqrsd?qr=1';

        $this->assertTrue(ArchivosQrFormulario::sincronizar($local, $this->directorio));
        $this->assertArchivosCoherentes($local);

        $pngLocal = file_get_contents($this->archivo('formulario-pqrsd.png'));
        $this->assertTrue(ArchivosQrFormulario::sincronizar($publica, $this->directorio));
        $this->assertArchivosCoherentes($publica);
        $this->assertNotSame($pngLocal, file_get_contents($this->archivo('formulario-pqrsd.png')));
    }

    public function test_en_produccion_una_url_local_no_modifica_archivos_existentes(): void
    {
        $conservado = 'qr-publicado-no-tocar';
        foreach (['formulario-pqrsd.png', 'formulario-pqrsd.svg', 'formulario-pqrsd.url.txt', '.url-hash'] as $nombre) {
            file_put_contents($this->archivo($nombre), $conservado);
        }

        $this->app['env'] = 'production';

        $this->assertFalse(ArchivosQrFormulario::sincronizar(
            'http://127.0.0.1:8010/publico/pqrsd?qr=1',
            $this->directorio
        ));

        foreach (['formulario-pqrsd.png', 'formulario-pqrsd.svg', 'formulario-pqrsd.url.txt', '.url-hash'] as $nombre) {
            $this->assertSame($conservado, file_get_contents($this->archivo($nombre)));
        }
    }

    public function test_en_produccion_la_url_publica_si_puede_escribir_en_un_directorio_vacio(): void
    {
        $publica = 'https://pqrsd.comitedeestudiosmedicos.com.co/publico/pqrsd?qr=1';
        $this->app['env'] = 'production';

        $this->assertTrue(ArchivosQrFormulario::sincronizar($publica, $this->directorio));
        $this->assertArchivosCoherentes($publica);
    }

    public function test_la_imagen_de_produccion_no_conserva_el_qr_versionado_ni_borra_un_volumen(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile.prod'));
        $entrada = file_get_contents(base_path('docker/entrypoint-prod.sh'));
        $compose = file_get_contents(base_path('compose.prod.yaml'));
        $ignore = file_get_contents(base_path('.dockerignore'));

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('public/qrs/formulario-pqrsd.png', $dockerfile);
        $this->assertStringContainsString('public/qrs/formulario-pqrsd.svg', $dockerfile);
        $this->assertStringContainsString('public/qrs/formulario-pqrsd.url.txt', $dockerfile);
        $this->assertStringContainsString('public/qrs/.url-hash', $dockerfile);
        $this->assertStringContainsString('mkdir -p public/qrs', $dockerfile);
        $this->assertDoesNotMatchRegularExpression('/rm\s+-rf\s+public\/qrs/', $dockerfile);

        $this->assertIsString($entrada);
        $this->assertStringContainsString('chown -R www-data:www-data public/qrs', $entrada);
        $this->assertStringNotContainsString('formulario-pqrsd', $entrada);

        $this->assertIsString($compose);
        $this->assertStringContainsString('pqrsd_prod_qrs:/var/www/html/public/qrs', $compose);
        $this->assertStringContainsString('pqrsd_prod_storage:/var/www/html/storage', $compose);
        $this->assertStringContainsString('pqrsd_prod_qrs:/var/www/html/public/qrs:ro', $compose);

        $this->assertIsString($ignore);
        $this->assertStringContainsString('public/qrs/*', $ignore);
    }

    private function assertArchivosCoherentes(string $url): void
    {
        $this->assertSame($url, file_get_contents($this->archivo('formulario-pqrsd.url.txt')));
        $this->assertSame(sha1($url), trim((string) file_get_contents($this->archivo('.url-hash'))));
        $this->assertSame(
            QrPngGenerator::generate($url, 320),
            file_get_contents($this->archivo('formulario-pqrsd.png'))
        );
        $this->assertSame(
            (string) QrCode::format('svg')->size(320)->margin(1)->generate($url),
            file_get_contents($this->archivo('formulario-pqrsd.svg'))
        );
    }

    private function archivo(string $nombre): string
    {
        return $this->directorio.DIRECTORY_SEPARATOR.$nombre;
    }
}
