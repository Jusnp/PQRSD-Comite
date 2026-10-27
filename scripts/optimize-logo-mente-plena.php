<?php

/**
 * Genera variantes del logo institucional sin alterar colores ni tipografía:
 * - mente-plena-compact.png: recorte de márgenes internos excesivos
 * - mente-plena-horizontal.png: lienzo horizontal con el mismo contenido
 */

$srcPath = dirname(__DIR__).'/public/images/mente-plena.png';
$src = imagecreatefrompng($srcPath);
if ($src === false) {
    fwrite(STDERR, "No se pudo leer {$srcPath}\n");
    exit(1);
}

$w = imagesx($src);
$h = imagesy($src);
$rgb = imagecolorat($src, 10, 10);
$bgR = ($rgb >> 16) & 0xFF;
$bgG = ($rgb >> 8) & 0xFF;
$bgB = $rgb & 0xFF;
$tol = 18;

$minX = $w;
$minY = $h;
$maxX = 0;
$maxY = 0;

for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $c = imagecolorat($src, $x, $y);
        $r = ($c >> 16) & 0xFF;
        $g = ($c >> 8) & 0xFF;
        $b = $c & 0xFF;
        if (abs($r - $bgR) > $tol || abs($g - $bgG) > $tol || abs($b - $bgB) > $tol) {
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }
    }
}

$pad = (int) round(($maxX - $minX) * 0.08);
$minX = max(0, $minX - $pad);
$minY = max(0, $minY - $pad);
$maxX = min($w - 1, $maxX + $pad);
$maxY = min($h - 1, $maxY + $pad);
$cw = $maxX - $minX + 1;
$ch = $maxY - $minY + 1;

$crop = imagecreatetruecolor($cw, $ch);
imagealphablending($crop, false);
imagesavealpha($crop, true);
imagecopy($crop, $src, 0, 0, $minX, $minY, $cw, $ch);
imagepng($crop, dirname(__DIR__).'/public/images/mente-plena-compact.png');

$outH = 320;
$scale = $outH / $ch;
$outWContent = (int) round($cw * $scale);
$sidePad = (int) round($outH * 0.35);
$outW = $outWContent + ($sidePad * 2);

$out = imagecreatetruecolor($outW, $outH);
$bg = imagecolorallocate($out, $bgR, $bgG, $bgB);
imagefilledrectangle($out, 0, 0, $outW, $outH, $bg);
imagecopyresampled($out, $crop, $sidePad, 0, 0, 0, $outWContent, $outH, $cw, $ch);
imagepng($out, dirname(__DIR__).'/public/images/mente-plena-horizontal.png');

echo "crop={$minX},{$minY},{$cw}x{$ch} bg={$bgR},{$bgG},{$bgB}\n";
echo "horizontal={$outW}x{$outH}\n";

imagedestroy($src);
imagedestroy($crop);
imagedestroy($out);
