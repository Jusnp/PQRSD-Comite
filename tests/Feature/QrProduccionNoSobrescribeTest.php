<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrProduccionNoSobrescribeTest extends TestCase
{
    use RefreshDatabase;
    public function test_la_pagina_de_produccion_no_reemplaza_el_qr_versionado_con_una_url_local(): void
    {
        $dir = public_path('qrs');
        $nombres = [
            'formulario-pqrsd.png',
            'formulario-pqrsd.svg',
            'formulario-pqrsd.url.txt',
            '.url-hash',
        ];
        $antes = [];
        foreach ($nombres as $nombre) {
            $ruta = $dir.DIRECTORY_SEPARATOR.$nombre;
            $antes[$nombre] = is_file($ruta) ? hash_file('sha256', $ruta) : null;
        }

        $this->app['env'] = 'production';
        config([
            'pqrsd_publico.base_url' => null,
            'pqrsd_publico.dev_url' => null,
            'app.url' => 'http://127.0.0.1:8010',
        ]);

        $this->get(route('publico.qr'))
            ->assertOk()
            ->assertSee('No apto para distribuir', false)
            ->assertSee('no se modificaron', false)
            ->assertDontSee('Descargar QR en PNG', false);

        $this->get(route('publico.qr.download', ['format' => 'png']))->assertNotFound();

        foreach ($antes as $nombre => $hash) {
            $ruta = $dir.DIRECTORY_SEPARATOR.$nombre;
            $actual = is_file($ruta) ? hash_file('sha256', $ruta) : null;
            $this->assertSame($hash, $actual, $nombre);
        }
    }

    public function test_el_formulario_publico_sigue_disponible(): void
    {
        $this->get(route('publico.formulario'))
            ->assertOk()
            ->assertSee('Documentos de soporte', false);
    }
}
