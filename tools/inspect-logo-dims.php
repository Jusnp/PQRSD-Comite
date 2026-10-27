<?php

$candidates = [
    'logo-comite-estudios-medicos-blanco.png',
    'public/images/logo-comite-estudios-medicos.png',
    'public/images/comite-estudios-medicos.png',
    'public/images/comite-estudios-medicos-header.png',
    'COMITE DE ESTUDIOS MEDICOS.png',
];

$downloads = glob('C:/Users/Juan/Downloads/*.{png,PNG,jpg,JPG}', GLOB_BRACE) ?: [];
foreach ($downloads as $d) {
    if (stripos(basename($d), 'comite') !== false || stripos(basename($d), 'comité') !== false || stripos(basename($d), 'estudios') !== false) {
        $candidates[] = $d;
    }
}

foreach ($candidates as $f) {
    if (! is_file($f)) {
        echo "MISSING\t{$f}\n";
        continue;
    }
    $i = @getimagesize($f);
    $hash = substr(hash_file('sha256', $f), 0, 16);
    echo ($i ? "{$i[0]}x{$i[1]}" : 'bad')."\t".filesize($f)."\t{$hash}\t{$f}\n";
}
