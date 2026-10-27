<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Operadores de laboratorio: 1 administrador + 2 auxiliares PQRSD.
 *
 * Nunca se ejecuta en producción: no crea cuentas lab ni desactiva usuarios reales.
 * Solo puede desactivar correos demo hard-coded @menteplena.test (lista cerrada).
 */
class UsersSeeder extends Seeder
{
    /** @var list<string> */
    public const LAB_EMAILS = [
        'admin@menteplena.test',
        'auxiliar1@menteplena.test',
        'auxiliar2@menteplena.test',
    ];

    /** @var list<string> */
    public const LEGACY_DEMO_EMAILS = [
        'lider@menteplena.test',
        'auxiliar@menteplena.test',
        'responsable@menteplena.test',
        'auditor@menteplena.test',
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $users = [
            [
                'name' => 'Administrador PQRSD',
                'email' => 'admin@menteplena.test',
                'role' => 'admin',
            ],
            [
                'name' => 'Auxiliar PQRSD 1',
                'email' => 'auxiliar1@menteplena.test',
                'role' => 'auxiliar_pqrsd',
            ],
            [
                'name' => 'Auxiliar PQRSD 2',
                'email' => 'auxiliar2@menteplena.test',
                'role' => 'auxiliar_pqrsd',
            ],
        ];

        foreach ($users as $data) {
            $user = User::query()->firstOrNew(['email' => $data['email']]);
            $creating = ! $user->exists;

            $user->fill([
                'name' => $data['name'],
                'activo' => true,
                'theme_preference' => $user->theme_preference ?: 'light',
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            // Solo cuentas @menteplena.test. La clave llega por el entorno local
            // o, en pruebas, se genera en memoria. Nunca hay una clave fija versionada.
            $claveLaboratorio = (string) env('PQRSD_LAB_PASSWORD', '');
            if ($claveLaboratorio !== '' && ($creating || app()->environment(['local', 'testing']))) {
                $user->password = Hash::make($claveLaboratorio);
            }

            $user->save();
            $user->syncRoles([$data['role']]);
        }

        // Lista cerrada de demos legacy @menteplena.test — no toca otros correos.
        User::query()
            ->whereIn('email', self::LEGACY_DEMO_EMAILS)
            ->update(['activo' => false]);
    }
}
