<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Authentik\AuthentikApiClient;
use App\Services\Authentik\AuthentikProvisionService;
use Illuminate\Console\Command;
use Throwable;

class VincularAdministradorAuthentik extends Command
{
    protected $signature = 'pqrsd:vincular-administrador
        {email : Correo de un administrador que ya existe en PQRSD}
        {--sub= : Identificador OIDC (uid) si debe coincidir con una identidad concreta}';

    protected $description = 'Vincula un administrador PQRSD existente con una identidad Authentik verificada por la API';

    public function handle(AuthentikApiClient $api, AuthentikProvisionService $provision): int
    {
        $base = rtrim((string) config('authentik.base_url'), '/');
        $slug = (string) config('authentik.app_slug', 'pqrsd-comite');
        if ($base === '' || ! str_starts_with($base, 'https://')) {
            $this->error('El emisor Authentik no está configurado en HTTPS. No se vinculó ninguna cuenta.');

            return self::FAILURE;
        }
        $emisor = $base.'/application/o/'.$slug.'/';

        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user || ! $user->hasRole('admin') || ! $user->activo || $user->authentik_status === User::AUTHENTIK_DEACTIVATED) {
            $this->error('Se requiere una cuenta PQRSD activa, no desactivada, con rol administrador.');

            return self::FAILURE;
        }

        try {
            $identidad = $this->identidadVerificada($api, $email, (string) $this->option('sub'));
        } catch (Throwable $e) {
            report($e);
            $this->error('No se pudo verificar la identidad en Authentik. No se vinculó ninguna cuenta.');

            return self::FAILURE;
        }

        if ($identidad === null) {
            $this->error('No hay una identidad Authentik inequívoca para ese administrador. No se creó ni se apropió ninguna cuenta.');

            return self::FAILURE;
        }

        if (in_array(strtolower($identidad['username']), ['akadmin', 'adminsispam'], true)) {
            $this->error('No se vincula la cuenta '.$identidad['username'].'.');

            return self::FAILURE;
        }

        $ocupada = User::query()
            ->where('authentik_sub', $identidad['uid'])
            ->where('id', '!=', $user->id)
            ->exists();
        if ($ocupada) {
            $this->error('Esa identidad ya está vinculada a otra cuenta PQRSD.');

            return self::FAILURE;
        }

        $user->update([
            'authentik_sub' => $identidad['uid'],
            'authentik_user_pk' => $identidad['pk'],
            'authentik_issuer' => $emisor,
            'authentik_status' => User::AUTHENTIK_ACTIVE,
            'activo' => true,
            'authentik_last_error' => null,
        ]);
        $provision->registrar($user, null, 'admin_oidc_linked', [
            'authentik_user_pk' => $identidad['pk'],
            'issuer' => $emisor,
        ]);

        $this->info('Administrador vinculado. El acceso web local queda cerrado; el ingreso es solo por Authentik.');

        return self::SUCCESS;
    }

    /**
     * @return array{pk:string,uid:string,email:string,username:string}|null
     */
    private function identidadVerificada(AuthentikApiClient $api, string $email, string $sub): ?array
    {
        if ($sub !== '') {
            $identidad = $api->findIdentityByUid($sub);
            if ($identidad === null || strcasecmp($identidad['email'], $email) !== 0) {
                return null;
            }

            return $identidad;
        }

        $coincidencias = $api->findIdentitiesByEmail($email);
        if (count($coincidencias) !== 1) {
            return null;
        }

        return $coincidencias[0];
    }
}
