<?php

namespace Tests;

use App\Models\Pqrsd;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Crea una clave de laboratorio solo en memoria, antes de arrancar Laravel.
     * No se escribe en phpunit.xml, en .env ni en el repositorio.
     */
    public function createApplication()
    {
        $actual = getenv('PQRSD_LAB_PASSWORD');
        if ($actual === false || $actual === '') {
            $clave = 'Lab-'.bin2hex(random_bytes(18));
            putenv('PQRSD_LAB_PASSWORD='.$clave);
            $_ENV['PQRSD_LAB_PASSWORD'] = $clave;
            $_SERVER['PQRSD_LAB_PASSWORD'] = $clave;
        }

        return parent::createApplication();
    }

    /**
     * Incluye el token de versión optimista exigido por clasificar/responder/cerrar HTTP.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function conVersionExpediente(Pqrsd $caso, array $payload = []): array
    {
        return array_merge($payload, [
            'expediente_version' => $caso->fresh()->versionToken(),
        ]);
    }
}
