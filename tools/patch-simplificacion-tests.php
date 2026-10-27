<?php

$files = glob(__DIR__.'/../tests/Feature/*.php') ?: [];

foreach ($files as $file) {
    $src = file_get_contents($file);
    $out = $src;

    $out = str_replace(
        "->assertSee('Solicitar información a otra área', false)",
        "->assertDontSee('Solicitar información a otra área', false)",
        $out
    );
    $out = str_replace(
        "->assertSee('Solicitar información a otra área')",
        "->assertDontSee('Solicitar información a otra área', false)",
        $out
    );
    $out = str_replace(
        "assertStringContainsString('Solicitar información a otra área'",
        "assertStringNotContainsString('Solicitar información a otra área'",
        $out
    );
    $out = str_replace(
        "assertStringContainsString('Gestiones y soportes'",
        "assertStringNotContainsString('Gestiones y soportes'",
        $out
    );
    $out = str_replace(
        "assertStringContainsString('Finalización de PQRSD'",
        "assertStringContainsString('Estado y finalización'",
        $out
    );
    $out = str_replace(
        "assertStringContainsString('todavía no puede finalizarse'",
        "assertStringContainsString('En trámite — pendiente de respuesta'",
        $out
    );
    $out = str_replace(
        "->assertSee('Gestiones y soportes')",
        "->assertDontSee('Gestiones y soportes', false)",
        $out
    );
    $out = str_replace(
        "->assertSee('Finalización de PQRSD')",
        "->assertSee('Estado y finalización', false)",
        $out
    );

    // Arrays de títulos de sección
    $out = str_replace("'Gestiones y soportes',", "'Respuesta de PQRS',", $out);
    $out = str_replace("'Solicitar información a otra área',", "'Historial automático',", $out);
    $out = str_replace("'Finalización de PQRSD',", "'Estado y finalización',", $out);

    if ($out !== $src) {
        file_put_contents($file, $out);
        echo 'PATCHED '.basename($file).PHP_EOL;
    }
}

echo "OK\n";
