<?php

declare(strict_types=1);

$source = dirname(__DIR__).DIRECTORY_SEPARATOR.'COMITE DE ESTUDIOS MEDICOS.png';
$destDir = dirname(__DIR__).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'images';
$originalDest = $destDir.DIRECTORY_SEPARATOR.'comite-estudios-medicos.png';
$headerDest = $destDir.DIRECTORY_SEPARATOR.'comite-estudios-medicos-header.png';

if (! is_file($source)) {
    fwrite(STDERR, "Fuente no encontrada: {$source}\n");
    exit(1);
}

if (! extension_loaded('gd')) {
    fwrite(STDERR, "Extensión GD no disponible\n");
    exit(1);
}

if (! is_dir($destDir) && ! mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
    fwrite(STDERR, "No se pudo crear {$destDir}\n");
    exit(1);
}

if (! copy($source, $originalDest)) {
    fwrite(STDERR, "No se pudo copiar el original\n");
    exit(1);
}

$img = imagecreatefrompng($originalDest);
if ($img === false) {
    fwrite(STDERR, "No se pudo leer PNG\n");
    exit(1);
}

imagesavealpha($img, true);
$width = imagesx($img);
$height = imagesy($img);

/**
 * Detecta color de fondo del borde (gris oscuro) y recorta márgenes vacíos
 * hasta el contenido tipográfico, dejando un padding uniforme.
 */
$sample = imagecolorat($img, 2, 2);
$bgR = ($sample >> 16) & 0xFF;
$bgG = ($sample >> 8) & 0xFF;
$bgB = $sample & 0xFF;
$tolerance = 18;

$isBg = static function (int $rgb) use ($bgR, $bgG, $bgB, $tolerance): bool {
    $r = ($rgb >> 16) & 0xFF;
    $g = ($rgb >> 8) & 0xFF;
    $b = $rgb & 0xFF;

    return abs($r - $bgR) <= $tolerance
        && abs($g - $bgG) <= $tolerance
        && abs($b - $bgB) <= $tolerance;
};

$minX = $width;
$minY = $height;
$maxX = 0;
$maxY = 0;

for ($y = 0; $y < $height; $y++) {
    for ($x = 0; $x < $width; $x++) {
        if (! $isBg(imagecolorat($img, $x, $y))) {
            if ($x < $minX) {
                $minX = $x;
            }
            if ($y < $minY) {
                $minY = $y;
            }
            if ($x > $maxX) {
                $maxX = $x;
            }
            if ($y > $maxY) {
                $maxY = $y;
            }
        }
    }
}

if ($maxX <= $minX || $maxY <= $minY) {
    fwrite(STDERR, "No se detectó contenido tipográfico\n");
    exit(1);
}

$contentW = $maxX - $minX + 1;
$contentH = $maxY - $minY + 1;
$pad = (int) max(12, round(min($contentW, $contentH) * 0.08));

$cropX = max(0, $minX - $pad);
$cropY = max(0, $minY - $pad);
$cropW = min($width - $cropX, $contentW + (2 * $pad));
$cropH = min($height - $cropY, $contentH + (2 * $pad));

// Padding uniforme: si se recortó contra un borde, igualar el lado opuesto disponible.
$padL = $minX - $cropX;
$padT = $minY - $cropY;
$padR = ($cropX + $cropW - 1) - $maxX;
$padB = ($cropY + $cropH - 1) - $maxY;
$uniform = min($padL, $padT, $padR, $padB, $pad);
$cropX = $minX - $uniform;
$cropY = $minY - $uniform;
$cropW = $contentW + (2 * $uniform);
$cropH = $contentH + (2 * $uniform);

$cropped = imagecreatetruecolor($cropW, $cropH);
imagealphablending($cropped, false);
imagesavealpha($cropped, true);
$bg = imagecolorallocate($cropped, $bgR, $bgG, $bgB);
imagefilledrectangle($cropped, 0, 0, $cropW, $cropH, $bg);
imagecopy($cropped, $img, 0, 0, $cropX, $cropY, $cropW, $cropH);

imagepng($cropped, $headerDest, 6);
imagedestroy($cropped);
imagedestroy($img);

echo "OK original={$width}x{$height} header={$cropW}x{$cropH} pad={$uniform}\n";
echo "Wrote {$originalDest}\n";
echo "Wrote {$headerDest}\n";
