<?php

namespace Tests\Unit;

use App\Services\Migracion\CopiaAdjuntoReversible;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CopiaAdjuntoReversibleTest extends TestCase
{
    public function test_si_la_transaccion_falla_no_queda_el_archivo(): void
    {
        $directorio = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pqrsd-copia-'.bin2hex(random_bytes(4));
        mkdir($directorio);
        $origen = $directorio.DIRECTORY_SEPARATOR.'origen.bin';
        $destino = $directorio.DIRECTORY_SEPARATOR.'destino.bin';
        file_put_contents($origen, 'documento-privado');
        $hash = hash_file('sha256', $origen);

        try {
            (new CopiaAdjuntoReversible)->copiarSiTransaccionExitosa(
                $origen,
                $destino,
                $hash,
                function (): void {
                    throw new RuntimeException('transaccion');
                }
            );
            $this->fail('La transacción fallida debía abortar la copia.');
        } catch (RuntimeException $e) {
            $this->assertSame('transaccion', $e->getMessage());
        }

        $this->assertFileDoesNotExist($destino);
        $this->assertSame([], glob($directorio.DIRECTORY_SEPARATOR.'.staging-*') ?: []);
        $this->unlinkDir($directorio);
    }

    public function test_si_la_transaccion_termina_el_destino_conserva_el_hash(): void
    {
        $directorio = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pqrsd-copia-'.bin2hex(random_bytes(4));
        mkdir($directorio);
        $origen = $directorio.DIRECTORY_SEPARATOR.'origen.bin';
        $destino = $directorio.DIRECTORY_SEPARATOR.'privado'.DIRECTORY_SEPARATOR.'destino.bin';
        file_put_contents($origen, 'documento-privado');
        $hash = hash_file('sha256', $origen);
        $ejecutada = false;

        (new CopiaAdjuntoReversible)->copiarSiTransaccionExitosa(
            $origen,
            $destino,
            $hash,
            function () use (&$ejecutada): void {
                $ejecutada = true;
            }
        );

        $this->assertTrue($ejecutada);
        $this->assertFileExists($destino);
        $this->assertSame($hash, hash_file('sha256', $destino));
        $this->unlinkDir($directorio);
    }

    private function unlinkDir(string $directorio): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directorio, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directorio);
    }
}
