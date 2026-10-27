<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CatalogosSeeder::class,
        ]);

        // Cuentas de laboratorio, demos y buzones de prueba: nunca en producción.
        if (! app()->environment('production')) {
            $this->call([
                UsersSeeder::class,
                CuentasCorreoSeeder::class,
                DemoVencimientosSeeder::class,
            ]);
        }
    }
}
