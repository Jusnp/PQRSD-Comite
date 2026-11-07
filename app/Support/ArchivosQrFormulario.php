<?php

namespace App\Support;

use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Escribe el QR del formulario en un directorio ya destinado a esos archivos.
 *
 * En producción no crea ni sustituye archivos cuando la URL no es publicable.
 * Así una configuración local no pisa un QR que ya esté en el volumen.
 */
class ArchivosQrFormulario
{
    public static function sincronizar(string $url, string $directorio): bool
    {
        if (app()->environment('production') && ! PublicUrl::isDistributable($url)) {
            return false;
        }

        if (! is_dir($directorio) && ! mkdir($directorio, 0755, true) && ! is_dir($directorio)) {
            return false;
        }

        $marker = $directorio.DIRECTORY_SEPARATOR.'.url-hash';
        $target = $directorio.DIRECTORY_SEPARATOR.'formulario-pqrsd.url.txt';
        $svgPath = $directorio.DIRECTORY_SEPARATOR.'formulario-pqrsd.svg';
        $pngPath = $directorio.DIRECTORY_SEPARATOR.'formulario-pqrsd.png';
        $hash = sha1($url);
        $needsRegen = ! is_file($marker) || trim((string) file_get_contents($marker)) !== $hash
            || ! is_file($target) || trim((string) file_get_contents($target)) !== $url
            || ! is_file($svgPath)
            || ! is_file($pngPath);

        if (! $needsRegen) {
            return true;
        }

        foreach ([$svgPath, $pngPath, $marker, $target] as $old) {
            if (is_file($old)) {
                @unlink($old);
            }
        }

        file_put_contents(
            $svgPath,
            QrCode::format('svg')->size(320)->margin(1)->generate($url)
        );
        file_put_contents($pngPath, QrPngGenerator::generate($url, 320));
        file_put_contents($target, $url);
        file_put_contents($marker, $hash);

        return is_file($svgPath) && is_file($pngPath) && is_file($target);
    }
}
