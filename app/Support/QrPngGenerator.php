<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use RuntimeException;

/**
 * Genera PNG de QR con GD (sin depender de Imagick).
 */
class QrPngGenerator
{
    public static function generate(string $payload, int $pixelSize = 320): string
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('La extensión GD es necesaria para generar PNG del código QR.');
        }

        $qrCode = Encoder::encode($payload, ErrorCorrectionLevel::M());
        $matrix = $qrCode->getMatrix();
        $modules = $matrix->getWidth();
        $marginModules = 2;
        $total = $modules + (2 * $marginModules);
        $scale = max(1, intdiv($pixelSize, $total));
        $dim = $total * $scale;

        $image = imagecreatetruecolor($dim, $dim);
        if ($image === false) {
            throw new RuntimeException('No fue posible crear la imagen PNG del QR.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $dim - 1, $dim - 1, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $x1 = ($x + $marginModules) * $scale;
                    $y1 = ($y + $marginModules) * $scale;
                    imagefilledrectangle($image, $x1, $y1, $x1 + $scale - 1, $y1 + $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($image);
        imagedestroy($image);
        $png = ob_get_clean();

        if ($png === false || $png === '') {
            throw new RuntimeException('No fue posible codificar el PNG del QR.');
        }

        return $png;
    }
}
