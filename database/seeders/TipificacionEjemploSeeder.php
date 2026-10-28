<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Siembra solo categorías, subcategorías y sugerencias de motivo.
 * No actualiza sedes, aseguradoras, canales ni reglas SLA.
 */
class TipificacionEjemploSeeder extends Seeder
{
    public function run(): void
    {
        (new CatalogosSeeder)->sembrarTipificacionEditable();
    }
}
