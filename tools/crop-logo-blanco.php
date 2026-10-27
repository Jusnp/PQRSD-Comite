<?php

/**
 * Recorta el lienzo cuadrado del logo institucional al bounding box del
 * contenido tipográfico (sin alterar letras ni colores).
 * Si la proporción del contenido ≈ 172:96, genera ese tamaño canónico.
 */

$srcPath = $argv[1] ?? 'logo-comite-estudios-medicos-blanco.png';
$outRoot = $argv[2] ?? 'logo-comite-estudios-medicos-blanco.png';
$outPublic = $argv[3] ?? 'public/images/logo-comite-estudios-medicos.png';
$canonicalW = 172;
$canonicalH = 96;

if (! is_file($srcPath)) {
    fwrite(STDERR, "No existe: {$srcPath}\n");
    exit(1);
}

// Trabajar desde una copia en memoria; no leer el destino si se sobrescribe.
$bytes = file_get_contents($srcPath);
$src = imagecreatefromstring($bytes);
if ($src === false) {
    fwrite(STDERR, "No se pudo leer PNG\n");
    exit(1);
}

$w = imagesx($src);
$h = imagesy($src);

$minX = $w;
$minY = $h;
$maxX = 0;
$maxY = 0;
$threshold = 245;

for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $rgba = imagecolorat($src, $x, $y);
        $a = ($rgba & 0x7F000000) >> 24;
        $r = ($rgba >> 16) & 0xFF;
        $g = ($rgba >> 8) & 0xFF;
        $b = $rgba & 0xFF;

        if ($a >= 120) {
            continue;
        }
        if ($r >= $threshold && $g >= $threshold && $b >= $threshold) {
            continue;
        }

        $minX = min($minX, $x);
        $minY = min($minY, $y);
        $maxX = max($maxX, $x);
        $maxY = max($maxY, $y);
    }
}

if ($maxX <= $minX || $maxY <= $minY) {
    fwrite(STDERR, "No se detectó contenido no blanco\n");
    exit(1);
}

$pad = (int) max(6, round(($maxX - $minX) * 0.035));
$minX = max(0, $minX - $pad);
$minY = max(0, $minY - $pad);
$maxX = min($w - 1, $maxX + $pad);
$maxY = min($h - 1, $maxY + $pad);

$cropW = $maxX - $minX + 1;
$cropH = $maxY - $minY + 1;
$ratio = $cropW / $cropH;
$canonicalRatio = $canonicalW / $canonicalH;

echo "Fuente: {$w}x{$h}\n";
echo "Contenido: {$cropW}x{$cropH} (ratio ".round($ratio, 3)."; canónico ".round($canonicalRatio, 3).")\n";

$cropped = imagecreatetruecolor($cropW, $cropH);
imagealphablending($cropped, false);
imagesavealpha($cropped, true);
$white = imagecolorallocate($cropped, 255, 255, 255);
imagefilledrectangle($cropped, 0, 0, $cropW, $cropH, $white);
imagealphablending($cropped, true);
imagecopy($cropped, $src, 0, 0, $minX, $minY, $cropW, $cropH);

// Escalar a 172×96 solo si la proporción del contenido es compatible (±8%).
$ratioDelta = abs($ratio - $canonicalRatio) / $canonicalRatio;
if ($ratioDelta <= 0.08) {
    $final = imagecreatetruecolor($canonicalW, $canonicalH);
    imagealphablending($final, false);
    imagesavealpha($final, true);
    $bg = imagecolorallocate($final, 255, 255, 255);
    imagefilledrectangle($final, 0, 0, $canonicalW, $canonicalH, $bg);
    imagealphablending($final, true);
    imagecopyresampled($final, $cropped, 0, 0, 0, 0, $canonicalW, $canonicalH, $cropW, $cropH);
    echo "Escalado canónico: {$canonicalW}x{$canonicalH}\n";
} else {
    // Mantener proporción real; limitar ancho largo para peso razonable.
    $maxEdge = 688; // 4×172
    $scale = min(1.0, $maxEdge / max($cropW, $cropH));
    $outW = (int) max(1, round($cropW * $scale));
    $outH = (int) max(1, round($cropH * $scale));
    $final = imagecreatetruecolor($outW, $outH);
    imagealphablending($final, false);
    imagesavealpha($final, true);
    $bg = imagecolorallocate($final, 255, 255, 255);
    imagefilledrectangle($final, 0, 0, $outW, $outH, $bg);
    imagealphablending($final, true);
    imagecopyresampled($final, $cropped, 0, 0, 0, 0, $outW, $outH, $cropW, $cropH);
    echo "Escalado proporcional: {$outW}x{$outH} (ratio distinto al 172:96; no se forzó deformación)\n";
}

foreach ([$outRoot, $outPublic] as $dest) {
    imagepng($final, $dest, 6);
    clearstatcache(true, $dest);
    $info = getimagesize($dest);
    echo "Escrito: {$dest} ({$info[0]}x{$info[1]}, ".filesize($dest)." bytes)\n";
}

imagedestroy($src);
imagedestroy($cropped);
imagedestroy($final);
echo "OK\n";
