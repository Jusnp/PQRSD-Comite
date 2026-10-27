<?php

namespace App\Services\Authentik;

use App\Models\User;
use App\Models\UsuarioAuditoria;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AuthentikProvisionService
{
    public const CORREO_PRINCIPAL = 'practicantesistemas@comitedeestudiosmedicos.com';

    public const CORREO_HISTORICO = 'admin@menteplena.test';

    public const USUARIO_PRINCIPAL = 'practicantesistemas';

    public const USUARIO_AKADMIN = 'akadmin';

    public function __construct(
        private readonly AuthentikApiClient $api,
    ) {}

    public function configuracionCompleta(): bool
    {
        return str_starts_with((string) config('authentik.base_url'), 'https://')
            && filled(config('authentik.api_token'))
            && (filled(config('authentik.enrollment_flow_pk')) || filled(config('authentik.enrollment_flow_slug')));
    }

    public function provisioningReady(): bool
    {
        return (bool) config('authentik.provisioning_enabled') && $this->configuracionCompleta();
    }

    public function asignarUsername(User $user): string
    {
        return $this->usernameInstitucional($user, consultarAuthentik: $this->configuracionCompleta(), exigirConsulta: false);
    }

    public function invitacionRemotaAusente(User $user): bool
    {
        if (! $this->configuracionCompleta() || blank($user->authentik_invitation_pk)) {
            return false;
        }

        try {
            return $this->api->invitationExists((string) $user->authentik_invitation_pk) === false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{pk:string,uid:string,email:string,username:string,active:bool}|null
     */
    public function consultarIdentidadCoincidente(User $user): ?array
    {
        if (! $this->configuracionCompleta() || blank($user->documento)) {
            return null;
        }

        try {
            $porUsuario = $this->api->findIdentityByUsername((string) $user->documento);
            if ($porUsuario === null || strcasecmp($porUsuario['email'], $user->email) !== 0 || ! ($porUsuario['active'] ?? false)) {
                return null;
            }
            $porCorreo = $this->api->findIdentitiesByEmail($user->email);
            if (count($porCorreo) !== 1 || (string) $porCorreo[0]['pk'] !== (string) $porUsuario['pk']) {
                return null;
            }
            $enGrupo = array_values(array_filter(
                $this->api->groupMemberIdentities((string) config('authentik.group_name')),
                fn (array $identidad): bool => (string) $identidad['pk'] === (string) $porUsuario['pk']
                    && $identidad['active']
                    && strcasecmp($identidad['username'], (string) $user->documento) === 0
                    && strcasecmp($identidad['email'], $user->email) === 0,
            ));
            if (count($enGrupo) !== 1) {
                return null;
            }
            $ocupada = User::query()
                ->where('id', '!=', $user->id)
                ->where(function ($consulta) use ($porUsuario) {
                    $consulta->where('authentik_user_pk', $porUsuario['pk'])
                        ->orWhere('authentik_sub', $porUsuario['uid']);
                })
                ->exists();
            if ($ocupada) {
                return null;
            }

            return $porUsuario;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * nueva, existente, vinculada, ambigua o insuficiente.
     * Un listado vacío de /core/users/ no basta para declarar que la persona es nueva.
     */
    public function evaluarAlta(User $user): string
    {
        if (filled($user->authentik_sub)) {
            return 'vinculada';
        }

        try {
            $miembros = $this->api->groupMemberIdentities((string) config('authentik.group_name'));
        } catch (Throwable) {
            return 'insuficiente';
        }

        $username = strtolower(trim((string) ($user->documento ?: '')));
        $porUsuario = array_values(array_filter(
            $miembros,
            fn (array $identidad): bool => $username !== '' && strcasecmp($identidad['username'], $username) === 0,
        ));
        $porCorreo = array_values(array_filter(
            $miembros,
            fn (array $identidad): bool => strcasecmp($identidad['email'], $user->email) === 0,
        ));
        if (count($porUsuario) > 1 || count($porCorreo) > 1) {
            return 'ambigua';
        }
        $encontrada = $porUsuario[0] ?? $porCorreo[0] ?? null;
        if (is_array($encontrada) && count($porUsuario) === 1 && count($porCorreo) === 1 && (string) $porUsuario[0]['pk'] !== (string) $porCorreo[0]['pk']) {
            return 'ambigua';
        }

        if (is_array($encontrada)) {
            return $this->pkOcupado($user, (string) $encontrada['pk']) ? 'vinculada' : 'existente';
        }

        try {
            $directorio = $this->api->consultaDirectorio($username, $user->email);
        } catch (Throwable) {
            return 'insuficiente';
        }

        if (in_array($directorio['username_http'], [403, 404], true) || in_array($directorio['email_http'], [403, 404], true)) {
            return 'insuficiente';
        }
        if (count($directorio['por_username']) > 1 || count($directorio['por_email']) > 1) {
            return 'ambigua';
        }
        $porNombre = $directorio['por_username'][0] ?? null;
        $porCorreoDirectorio = $directorio['por_email'][0] ?? null;
        if (is_array($porNombre) && is_array($porCorreoDirectorio) && (string) $porNombre['pk'] !== (string) $porCorreoDirectorio['pk']) {
            return 'ambigua';
        }
        $enDirectorio = $porNombre ?? $porCorreoDirectorio;
        if (is_array($enDirectorio)) {
            return $this->pkOcupado($user, (string) $enDirectorio['pk']) ? 'vinculada' : 'existente';
        }
        if (! $directorio['completa']) {
            return 'insuficiente';
        }

        return 'nueva';
    }

    /**
     * @return array{pk:string,uid:string,email:string,username:string,active:bool}|null
     */
    public function identidadAutorizadaParaIngreso(User $user, string $sub): ?array
    {
        if ($sub === '' || blank($user->authentik_authorization_hash)) {
            return null;
        }

        try {
            $miembros = $this->api->groupMemberIdentities((string) config('authentik.group_name'));
        } catch (Throwable) {
            return null;
        }

        $coincidencias = array_values(array_filter(
            $miembros,
            fn (array $identidad): bool => $identidad['uid'] === $sub
                && ($identidad['active'] ?? false) === true
                && strcasecmp($identidad['username'], (string) $user->documento) === 0
                && strcasecmp($identidad['email'], $user->email) === 0,
        ));
        if (count($coincidencias) !== 1) {
            return null;
        }
        $identidad = $coincidencias[0];
        if (filled($user->authentik_user_pk) && (string) $identidad['pk'] !== (string) $user->authentik_user_pk) {
            return null;
        }
        if ($this->pkOcupado($user, (string) $identidad['pk'], $sub)) {
            return null;
        }

        return $identidad;
    }

    private function pkOcupado(User $user, string $pk, ?string $sub = null): bool
    {
        return User::query()
            ->where('id', '!=', $user->id)
            ->where(function ($consulta) use ($pk, $sub) {
                $consulta->where('authentik_user_pk', $pk);
                if (filled($sub)) {
                    $consulta->orWhere('authentik_sub', $sub);
                }
            })
            ->exists();
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function autorizarVinculacionExistente(User $user, User $actor, bool $identidadVerificada): array
    {
        if (! $identidadVerificada) {
            return [
                'ok' => false,
                'message' => 'Debe confirmar que verificó la identidad del funcionario por un medio independiente.',
            ];
        }
        if (filled($user->authentik_sub)) {
            return [
                'ok' => false,
                'message' => 'Este funcionario ya está vinculado.',
            ];
        }

        $identidad = $this->consultarIdentidadCoincidente($user);
        if ($identidad === null) {
            return [
                'ok' => false,
                'message' => 'No hay una única identidad con ese identificador y ese correo. No se creó otra cuenta ni otra invitación.',
            ];
        }

        $hash = filled($user->authentik_authorization_hash)
            ? $user->authentik_authorization_hash
            : hash('sha256', 'vinculacion|'.$user->id.'|'.$identidad['pk']);
        $user->update([
            'activo' => false,
            'authentik_status' => User::AUTHENTIK_LINK_PENDING,
            'authentik_user_pk' => $identidad['pk'],
            'authentik_invitation_pk' => null,
            'authentik_invitation_url' => null,
            'authentik_authorization_hash' => $hash,
            'authentik_invitation_delivery' => 'vinculacion_autorizada',
            'authentik_last_error' => null,
            'email_verified_at' => null,
        ]);
        $this->auditar($user, $actor, 'vinculacion_autorizada_por_administrador', [
            'username' => $identidad['username'],
            'authentik_user_pk' => $identidad['pk'],
        ]);

        return [
            'ok' => true,
            'message' => 'Vinculación autorizada. El funcionario entra con Authentik; PQRSD se activa en ese primer ingreso. No se emitió otra invitación y el correo sigue sin verificar.',
        ];
    }

    /**
     * Crea invitación individual. No marca al usuario como activo.
     *
     * @return array{status:string,delivery:?string,message:string}
     */
    public function invite(User $user, ?User $actor = null, bool $autorizada = false): array
    {
        if (! $autorizada && ! (bool) config('authentik.provisioning_enabled')) {
            $this->asignarUsername($user);
            $user->update([
                'activo' => false,
                'authentik_status' => User::AUTHENTIK_PENDING,
                'authentik_last_error' => null,
                'email_verified_at' => null,
            ]);
            $this->auditar($user, $actor, 'invitation_deferred', [
                'reason' => 'provisioning_disabled',
            ]);

            return [
                'status' => User::AUTHENTIK_PENDING,
                'delivery' => null,
                'message' => 'Funcionario registrado. Pendiente de generar invitación de Authentik.',
            ];
        }

        if (! $this->configuracionCompleta()) {
            $user->update([
                'activo' => false,
                'authentik_status' => User::AUTHENTIK_FAILED,
                'authentik_last_error' => 'Falta la configuración de Authentik: URL HTTPS, token o flujo de invitación.',
            ]);
            $this->auditar($user, $actor, 'invitation_skipped_config', [
                'reason' => 'config_incomplete',
            ]);

            return [
                'status' => User::AUTHENTIK_FAILED,
                'delivery' => null,
                'message' => 'No se generó la invitación: la configuración de Authentik está incompleta.',
            ];
        }

        $bloqueo = null;
        try {
            if (blank($user->authentik_sub) && filled($user->authentik_invitation_pk) && ! $user->invitationExpired()) {
                return $this->entregarInvitacionExistente($user, $actor);
            }

            $username = (string) $user->documento;
            $situacion = $this->evaluarAlta($user);
            if ($situacion === 'nueva') {
                $username = $this->usernameInstitucional($user, consultarAuthentik: true, exigirConsulta: true);
                $user->refresh();
                $situacion = $this->evaluarAlta($user);
            }
            if (in_array($situacion, ['insuficiente', 'ambigua'], true)) {
                $user->update([
                    'activo' => false,
                    'authentik_status' => User::AUTHENTIK_REVIEW,
                    'authentik_last_error' => null,
                    'email_verified_at' => null,
                ]);
                $this->auditar($user, $actor, 'incorporacion_revision', [
                    'situacion' => $situacion,
                ]);

                return [
                    'status' => User::AUTHENTIK_REVIEW,
                    'delivery' => null,
                    'message' => 'No se pudo comprobar de forma segura si esta persona ya existe en Authentik. Se requiere revisión administrativa antes de generar una invitación.',
                ];
            }
            if (in_array($situacion, ['existente', 'vinculada'], true)) {
                $this->auditar($user, $actor, 'invitation_rejected_existing_identity', [
                    'situacion' => $situacion,
                ]);

                return [
                    'status' => (string) $user->authentik_status,
                    'delivery' => $user->authentik_invitation_delivery,
                    'message' => 'Ya existe una identidad para ese correo o identificador. No se emite otra invitación ni se crea otra cuenta.',
                ];
            }

            $bloqueo = Cache::lock('pqrsd-invitacion-'.$user->id, 20);
            if (! $bloqueo->get()) {
                $bloqueo = null;
                $user->refresh();
                if (filled($user->authentik_invitation_url) && ! $user->invitationExpired()) {
                    return $this->entregarInvitacionExistente($user, $actor);
                }

                return [
                    'status' => (string) $user->authentik_status,
                    'delivery' => $user->authentik_invitation_delivery,
                    'message' => 'Ya hay una invitación en curso. No se creó otra.',
                ];
            }

            $flowKey = (string) (config('authentik.enrollment_flow_pk') ?: config('authentik.enrollment_flow_slug'));
            $flowPk = $this->api->resolveFlowPk($flowKey);
            if (! $flowPk) {
                throw new RuntimeException('No se resolvió el flow de inscripción en Authentik.');
            }

            $expires = now('UTC')->addHours((int) config('authentik.invitation_ttl_hours', 48));
            $name = 'pqrsd-'.$user->id.'-'.now()->format('YmdHis');

            $invitation = $this->api->createInvitation([
                'name' => $name,
                'expires' => $expires,
                'flow_pk' => $flowPk,
                'single_use' => true,
                'fixed_data' => [
                    'username' => $username,
                    'email' => $user->email,
                    'name' => $user->name,
                ],
            ]);

            $slug = (string) config('authentik.enrollment_flow_slug');
            $url = rtrim((string) config('authentik.base_url'), '/').'/if/flow/'.$slug.'/?itoken='.$invitation['pk'];

            $user->update([
                'activo' => false,
                'authentik_status' => User::AUTHENTIK_PREPARED,
                'authentik_invitation_pk' => $invitation['pk'],
                'authentik_invitation_url' => $url,
                'authentik_authorization_hash' => hash('sha256', (string) $invitation['pk']),
                'authentik_invitation_expires_at' => $expires,
                'authentik_invitation_delivery' => 'private',
                'authentik_last_error' => null,
                'email_verified_at' => null,
                'authentik_sub' => null,
                'authentik_user_pk' => null,
            ]);

            $this->auditar($user, $actor, 'invitation_created', [
                'delivery' => 'private',
                'expires' => $expires->toIso8601String(),
                'autorizada' => $autorizada,
            ]);

            return [
                'status' => User::AUTHENTIK_PREPARED,
                'delivery' => 'private',
                'message' => 'La invitación privada está lista para copiar. No se envía correo.',
            ];
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'comprobar duplicados')) {
                $user->update([
                    'activo' => false,
                    'authentik_status' => User::AUTHENTIK_REVIEW,
                    'authentik_last_error' => null,
                    'email_verified_at' => null,
                ]);
                $this->auditar($user, $actor, 'incorporacion_revision', [
                    'situacion' => 'insuficiente',
                ]);

                return [
                    'status' => User::AUTHENTIK_REVIEW,
                    'delivery' => null,
                    'message' => 'No se pudo comprobar de forma segura si esta persona ya existe en Authentik. Se requiere revisión administrativa antes de generar una invitación.',
                ];
            }
            report($e);
            $user->update([
                'activo' => false,
                'authentik_status' => 'invitation_failed',
                'authentik_last_error' => $this->errorSinSecreto($e),
            ]);
            $this->auditar($user, $actor, 'invitation_failed', [
                'error' => 'invitation_failed',
            ]);

            return [
                'status' => 'invitation_failed',
                'delivery' => null,
                'message' => 'Funcionario pendiente: falló la invitación en Authentik. Puede reintentar.',
            ];
        } finally {
            $bloqueo?->release();
        }
    }

    public function resendInvitation(User $user, ?User $actor = null): array
    {
        if (filled($user->authentik_sub)) {
            return [
                'status' => (string) $user->authentik_status,
                'delivery' => $user->authentik_invitation_delivery,
                'message' => 'Este funcionario ya tiene una identidad vinculada. No se emite otra invitación.',
            ];
        }
        if ($user->authentik_status === User::AUTHENTIK_REVOCATION_PENDING) {
            return [
                'status' => User::AUTHENTIK_REVOCATION_PENDING,
                'delivery' => $user->authentik_invitation_delivery,
                'message' => 'No se pudo confirmar el estado de la invitación. Se requiere revisión administrativa.',
            ];
        }

        if (! $user->invitationExpired() && filled($user->authentik_invitation_url)) {
            return $this->entregarInvitacionExistente($user, $actor);
        }

        if ($this->identidadYaExiste($user)) {
            return [
                'status' => (string) $user->authentik_status,
                'delivery' => $user->authentik_invitation_delivery,
                'message' => 'Ya existe una identidad Authentik para este funcionario. No se crea otra cuenta: use el enlace privado de recuperación.',
            ];
        }

        $user->update([
            'authentik_invitation_pk' => null,
            'authentik_invitation_url' => null,
        ]);

        return $this->invite($user, $actor, autorizada: true);
    }

    /**
     * @return array{status:string,delivery:?string,message:string}
     */
    public function revocarInvitacion(User $user, ?User $actor = null): array
    {
        if (filled($user->authentik_sub) || $user->authentik_status === User::AUTHENTIK_ACTIVE) {
            return [
                'status' => (string) $user->authentik_status,
                'delivery' => $user->authentik_invitation_delivery,
                'message' => 'No se revoca la invitación: el funcionario ya está vinculado.',
            ];
        }
        if ($user->authentik_status === User::AUTHENTIK_REVOKED) {
            return [
                'status' => User::AUTHENTIK_REVOKED,
                'delivery' => null,
                'message' => 'La invitación fue revocada correctamente.',
            ];
        }

        $bloqueo = Cache::lock('pqrsd-revocar-'.$user->id, 20);
        if (! $bloqueo->get()) {
            return [
                'status' => (string) $user->authentik_status,
                'delivery' => $user->authentik_invitation_delivery,
                'message' => 'No se pudo confirmar el estado de la invitación. Se requiere revisión administrativa.',
            ];
        }

        try {
            $user->refresh();
            if ($user->authentik_status === User::AUTHENTIK_REVOKED) {
                return [
                    'status' => User::AUTHENTIK_REVOKED,
                    'delivery' => null,
                    'message' => 'La invitación fue revocada correctamente.',
                ];
            }

            $pk = (string) $user->authentik_invitation_pk;
            if ($pk === '' || ! $this->configuracionCompleta()) {
                return $this->revocacionIncierta($user, $actor, null);
            }

            try {
                $ficha = $this->api->inspeccionarInvitacion($pk);
            } catch (Throwable $e) {
                $this->auditar($user, $actor, 'invitation_revoke_unconfirmed', ['http' => null]);

                return $this->revocacionIncierta($user, $actor, null, auditar: false);
            }

            $httpFicha = (int) ($ficha['http'] ?? 0);
            if ($httpFicha === 403) {
                $this->auditar($user, $actor, 'invitation_revoke_failed', ['http' => 403]);
                $user->update(['authentik_last_error' => null]);

                return [
                    'status' => (string) $user->authentik_status,
                    'delivery' => $user->authentik_invitation_delivery,
                    'message' => 'No se pudo revocar la invitación. Authentik rechazó la operación.',
                ];
            }
            if ($httpFicha !== 200 || ! $this->invitacionPertenece($user, $ficha)) {
                $this->auditar($user, $actor, $httpFicha === 200 ? 'invitation_revoke_rejected' : 'invitation_revoke_unconfirmed', [
                    'http' => $httpFicha,
                ]);
                if ($httpFicha === 200) {
                    return [
                        'status' => (string) $user->authentik_status,
                        'delivery' => $user->authentik_invitation_delivery,
                        'message' => 'No se revocó la invitación porque no corresponde a este funcionario.',
                    ];
                }

                return $this->revocacionIncierta($user, $actor, $httpFicha, auditar: false);
            }

            try {
                $this->api->deleteInvitation($pk);
            } catch (Throwable $e) {
                $codigo = $this->codigoHttp($e);
                $this->auditar($user, $actor, $codigo === 403 ? 'invitation_revoke_failed' : 'invitation_revoke_unconfirmed', [
                    'http' => $codigo,
                ]);
                if ($codigo === 403) {
                    $user->update(['authentik_last_error' => null]);

                    return [
                        'status' => (string) $user->authentik_status,
                        'delivery' => $user->authentik_invitation_delivery,
                        'message' => 'No se pudo revocar la invitación. Authentik rechazó la operación.',
                    ];
                }

                return $this->revocacionIncierta($user, $actor, $codigo, auditar: false);
            }

            $user->update([
                'activo' => false,
                'authentik_status' => User::AUTHENTIK_REVOKED,
                'authentik_invitation_pk' => null,
                'authentik_invitation_url' => null,
                'authentik_authorization_hash' => null,
                'authentik_invitation_delivery' => null,
                'authentik_last_error' => null,
            ]);
            $this->auditar($user, $actor, 'invitation_revoked', []);

            return [
                'status' => User::AUTHENTIK_REVOKED,
                'delivery' => null,
                'message' => 'La invitación fue revocada correctamente.',
            ];
        } finally {
            $bloqueo->release();
        }
    }

    /**
     * @return array{status:string,delivery:?string,message:string}
     */
    private function revocacionIncierta(User $user, ?User $actor, ?int $codigo, bool $auditar = true): array
    {
        $user->update([
            'activo' => false,
            'authentik_status' => User::AUTHENTIK_REVOCATION_PENDING,
            'authentik_last_error' => null,
        ]);
        if ($auditar) {
            $this->auditar($user, $actor, 'invitation_revoke_unconfirmed', [
                'http' => $codigo,
            ]);
        }

        return [
            'status' => User::AUTHENTIK_REVOCATION_PENDING,
            'delivery' => $user->authentik_invitation_delivery,
            'message' => 'No se pudo confirmar el estado de la invitación. Se requiere revisión administrativa.',
        ];
    }

    private function codigoHttp(Throwable $e): ?int
    {
        if (preg_match('/HTTP (\d+)/', $e->getMessage(), $codigo) === 1) {
            return (int) $codigo[1];
        }

        return null;
    }

    /**
     * Recuperación de una identidad ya creada. No abre una inscripción nueva.
     *
     * @return array{status:string,delivery:?string,message:string}
     */
    public function recuperarIdentidadExistente(User $user, User $actor, bool $identidadVerificada): array
    {
        if (! $identidadVerificada) {
            return [
                'ok' => false,
                'status' => (string) $user->authentik_status,
                'delivery' => $user->authentik_invitation_delivery,
                'message' => 'Debe confirmar que verificó la identidad del funcionario por un medio independiente.',
            ];
        }

        if (filled($user->authentik_sub)) {
            return [
                'ok' => false,
                'status' => (string) $user->authentik_status,
                'delivery' => $user->authentik_invitation_delivery,
                'message' => 'Este funcionario ya está vinculado. No se genera otra recuperación.',
            ];
        }

        try {
            $username = $this->usernameInstitucional($user);
            $identidad = $this->api->findIdentityByUsername($username);
            if ($identidad === null || strcasecmp($identidad['email'], $user->email) !== 0) {
                return [
                    'ok' => false,
                    'status' => (string) $user->authentik_status,
                    'delivery' => null,
                    'message' => 'No hay una identidad única con ese usuario y correo. No se creó otra cuenta.',
                ];
            }

            try {
                $link = $this->api->requestRecoveryLink($identidad['pk']);
            } catch (Throwable $e) {
                return $this->autorizarRecuperacionEnInterfaz($user, $actor, $identidad, $username, $e);
            }

            $errorActivacion = null;
            try {
                $this->api->setUserActive($identidad['pk'], true);
            } catch (Throwable $e) {
                $errorActivacion = $this->errorSinSecreto($e);
            }
            $expires = now('UTC')->addHours(48);
            $user->update([
                'activo' => false,
                'authentik_status' => User::AUTHENTIK_PREPARED,
                'authentik_user_pk' => $identidad['pk'],
                'authentik_invitation_pk' => null,
                'authentik_invitation_url' => $link,
                'authentik_authorization_hash' => hash('sha256', $link),
                'authentik_invitation_expires_at' => $expires,
                'authentik_invitation_delivery' => 'recovery',
                'authentik_last_error' => $errorActivacion,
                'email_verified_at' => null,
            ]);
            $this->auditar($user, $actor, 'identidad_comprobada_por_administrador', [
                'username' => $username,
                'activacion' => 'despues_del_enlace',
            ]);

            return [
                'ok' => true,
                'status' => User::AUTHENTIK_PREPARED,
                'delivery' => 'recovery',
                'message' => 'Enlace de recuperación listo. Cópielo y entréguelo en privado. El funcionario sigue inactivo en PQRSD hasta que inicie sesión. El correo no se marcó como verificado.',
            ];
        } catch (Throwable $e) {
            $user->update(['authentik_last_error' => $this->errorSinSecreto($e)]);
            $this->auditar($user, $actor, 'recovery_failed', ['error' => 'sin_enlace']);

            return [
                'ok' => false,
                'status' => (string) $user->fresh()->authentik_status,
                'delivery' => null,
                'message' => 'No se generó el enlace de recuperación y no se activó la identidad.',
            ];
        }
    }

    /**
     * @param  array{pk:string,uid:string,email:string,username:string}  $identidad
     * @return array{ok:bool,status:string,delivery:?string,message:string}
     */
    private function autorizarRecuperacionEnInterfaz(User $user, User $actor, array $identidad, string $username, Throwable $e): array
    {
        $hash = filled($user->authentik_authorization_hash)
            ? $user->authentik_authorization_hash
            : hash('sha256', 'recuperacion-ui|'.$user->id.'|'.$identidad['pk']);
        $user->update([
            'activo' => false,
            'authentik_status' => User::AUTHENTIK_PREPARED,
            'authentik_user_pk' => $identidad['pk'],
            'authentik_invitation_pk' => null,
            'authentik_invitation_url' => null,
            'authentik_authorization_hash' => $hash,
            'authentik_invitation_delivery' => 'recovery_admin',
            'authentik_last_error' => $this->errorSinSecreto($e),
            'email_verified_at' => null,
        ]);
        $this->auditar($user, $actor, 'recuperacion_autorizada_interfaz', [
            'username' => $username,
            'authentik_user_pk' => $identidad['pk'],
        ]);

        return [
            'ok' => false,
            'status' => User::AUTHENTIK_PREPARED,
            'delivery' => 'recovery_admin',
            'message' => 'Authentik negó el enlace por la API. La identidad existente quedó autorizada para este funcionario. Cree el enlace en la ficha de ese usuario dentro de Authentik y entréguelo en privado. No se creó otra cuenta ni se marcó el correo como verificado.',
        ];
    }

    /**
     * Bloquea PQRSD y retira solo la membresía de pqrsd-usuarios.
     * No desactiva la identidad global de Authentik.
     *
     * @return array{ok:bool,grupo:?string,message:string}
     */
    public function deactivate(User $user, ?User $actor = null): array
    {
        try {
            DB::transaction(function () use ($user, $actor) {
                $user->update([
                    'activo' => false,
                    'authentik_status' => User::AUTHENTIK_DEACTIVATED,
                ]);

                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }

                $this->auditar($user, $actor, 'deactivated', []);
            });
        } catch (Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'grupo' => null,
                'message' => 'No fue posible completar la desactivación. El estado del funcionario no se modificó.',
            ];
        }

        $grupo = $this->revokeGroupMembership($user, $actor);
        if ($grupo === 'fallo') {
            return [
                'ok' => false,
                'grupo' => 'fallo',
                'message' => 'El acceso en PQRSD quedó bloqueado, pero Authentik no confirmó el retiro del grupo pqrsd-usuarios.',
            ];
        }

        return [
            'ok' => true,
            'grupo' => $grupo,
            'message' => 'El funcionario fue desactivado correctamente.',
        ];
    }

    /**
     * @return array{ok?:bool,status:string,delivery:?string,message:string}
     */
    public function reactivate(User $user, ?User $actor = null): array
    {
        if (filled($user->authentik_sub) || filled($user->authentik_user_pk)) {
            try {
                $groupPk = $this->resolveGroupPk();
                $userPk = $user->authentik_user_pk
                    ?: (filled($user->authentik_sub) ? $this->api->findUserPkBySub((string) $user->authentik_sub) : null);
                if (! $groupPk || ! $userPk) {
                    throw new RuntimeException('No se identificó el grupo o la identidad de PQRSD.');
                }

                $this->api->addUserToGroup($groupPk, $userPk);
                $user->update([
                    'authentik_user_pk' => $userPk,
                    'activo' => true,
                    'authentik_status' => User::AUTHENTIK_ACTIVE,
                    'authentik_last_error' => null,
                ]);
                $this->auditar($user, $actor, 'reactivated_with_group', ['authentik_user_pk' => $userPk]);

                return [
                    'ok' => true,
                    'status' => User::AUTHENTIK_ACTIVE,
                    'delivery' => null,
                    'message' => 'El funcionario fue reactivado correctamente.',
                ];
            } catch (Throwable $e) {
                report($e);
                $user->update(['authentik_last_error' => Str::limit($this->errorSinSecreto($e), 1000, '')]);
                $this->auditar($user, $actor, 'group_add_failed', []);

                return [
                    'ok' => false,
                    'status' => (string) $user->fresh()->authentik_status,
                    'delivery' => null,
                    'message' => 'No fue posible reactivar el acceso. El estado del funcionario no se modificó.',
                ];
            }
        }

        $user->update([
            'activo' => false,
            'authentik_status' => User::AUTHENTIK_PENDING,
        ]);

        return $this->invite($user, $actor, autorizada: true);
    }

    public function markLinkedFromOidc(User $user, string $sub, ?string $authentikUserPk = null, bool $correoVerificado = false, ?string $issuer = null): void
    {
        $primeraVinculacion = blank($user->authentik_sub);
        $datos = [
            'authentik_sub' => $sub,
            'authentik_user_pk' => $authentikUserPk ?: $user->authentik_user_pk,
            'authentik_issuer' => filled($user->authentik_issuer) ? $user->authentik_issuer : ($issuer ?: $this->emisorOidc()),
            'authentik_status' => 'active',
            'activo' => true,
            'authentik_last_error' => null,
            'last_login_at' => now(),
        ];
        if ($primeraVinculacion) {
            $datos['authentik_invitation_pk'] = null;
            $datos['authentik_invitation_url'] = null;
            $datos['authentik_invitation_delivery'] = 'unverified';
            $datos['email_verified_at'] = null;
        }
        $user->update($datos);

        $this->auditar($user, null, 'oidc_linked_activated', [
            'sub' => $sub,
            'correo_verificado' => false,
            'reclamacion_authentik' => $correoVerificado,
            'primera_vinculacion' => $primeraVinculacion,
        ]);

        $this->incorporarAlGrupo($user);
    }

    public function registrar(User $user, ?User $actor, string $accion, array $detalle): void
    {
        $this->auditar($user, $actor, $accion, $detalle);
    }

    private function incorporarAlGrupo(User $user): void
    {
        if (! $this->provisioningReady() || (blank($user->authentik_sub) && blank($user->authentik_user_pk))) {
            return;
        }

        try {
            $groupPk = $this->resolveGroupPk();
            $userPk = $user->authentik_user_pk
                ?: (filled($user->authentik_sub) ? $this->api->findUserPkBySub((string) $user->authentik_sub) : null);
            if (! $groupPk || ! $userPk) {
                return;
            }

            $this->api->addUserToGroup($groupPk, $userPk);
            $user->update(['authentik_user_pk' => $userPk]);
            $this->auditar($user, null, 'group_added', [
                'authentik_user_pk' => $userPk,
            ]);
        } catch (Throwable $e) {
            report($e);
            $user->update(['authentik_last_error' => Str::limit($e->getMessage(), 1000, '')]);
            $this->auditar($user, null, 'group_add_failed', ['error' => $e->getMessage()]);
        }
    }

    private function revokeGroupMembership(User $user, ?User $actor): string
    {
        if (! $this->provisioningReady()) {
            return 'omitido';
        }

        try {
            $groupPk = $this->resolveGroupPk();
            $userPk = $user->authentik_user_pk
                ?: (filled($user->authentik_sub) ? $this->api->findUserPkBySub((string) $user->authentik_sub) : null);

            if (! $groupPk || ! $userPk) {
                $this->auditar($user, $actor, 'group_revoke_skipped', [
                    'reason' => 'missing_group_or_user_pk',
                ]);

                return 'omitido';
            }

            $this->api->removeUserFromGroup($groupPk, $userPk);
            $user->update(['authentik_user_pk' => $userPk]);
            $this->auditar($user, $actor, 'group_revoked', [
                'group_pk' => $groupPk,
                'authentik_user_pk' => $userPk,
            ]);

            return 'ok';
        } catch (Throwable $e) {
            report($e);
            $user->update(['authentik_last_error' => Str::limit($this->errorSinSecreto($e), 1000, '')]);
            $this->auditar($user, $actor, 'group_revoke_failed', ['http' => $this->codigoHttp($e)]);

            return 'fallo';
        }
    }

    /**
     * @param  array{http:int,flow:string,username:string,email:string,name:string}  $ficha
     */
    private function invitacionPertenece(User $user, array $ficha): bool
    {
        $flow = (string) ($ficha['flow'] ?? '');
        $esperado = $this->api->resolveFlowPk((string) (config('authentik.enrollment_flow_pk') ?: config('authentik.enrollment_flow_slug')));
        $slug = (string) config('authentik.enrollment_flow_slug');
        $flowValido = ($esperado !== null && $this->mismoTexto($esperado, $flow))
            || ($slug !== '' && $this->mismoTexto($slug, $flow));
        if (! $flowValido || trim((string) $user->documento) === '' || trim((string) $user->email) === '' || trim((string) $user->name) === '') {
            return false;
        }

        return $this->mismoTexto((string) $user->documento, (string) ($ficha['username'] ?? ''))
            && $this->mismoTexto((string) $user->email, (string) ($ficha['email'] ?? ''))
            && $this->mismoTexto((string) $user->name, (string) ($ficha['name'] ?? ''));
    }

    private function mismoTexto(string $esperado, string $recibido): bool
    {
        return hash_equals(hash('sha256', strtolower(trim($esperado))), hash('sha256', strtolower(trim($recibido))));
    }

    private function resolveGroupPk(): ?string
    {
        $key = (string) (config('authentik.group_pk') ?: config('authentik.group_name'));

        return $this->api->resolveGroupPk($key);
    }

    /**
     * Vincula solo al administrador histórico y a akadmin, cada uno por su identidad exacta.
     *
     * @return array{ok:bool,mensajes:list<string>}
     */
    public function autorizarAdministradores(): array
    {
        $mensajes = [];
        $ok = true;

        try {
            $mensajes[] = $this->autorizarPrincipal();
        } catch (Throwable $e) {
            report($e);
            $ok = false;
            $mensajes[] = 'Administrador principal: '.$e->getMessage();
        }

        try {
            $mensajes[] = $this->autorizarAkadmin();
        } catch (Throwable $e) {
            report($e);
            $ok = false;
            $mensajes[] = 'akadmin: '.$e->getMessage();
        }

        return ['ok' => $ok, 'mensajes' => $mensajes];
    }

    private function autorizarPrincipal(): string
    {
        $principal = User::query()->whereRaw('LOWER(email) = ?', [self::CORREO_PRINCIPAL])->first()
            ?? User::query()->whereRaw('LOWER(email) = ?', [self::CORREO_HISTORICO])->first();

        if (! $principal || ! $principal->hasRole('admin')) {
            throw new RuntimeException('no hay un administrador histórico único para actualizar.');
        }

        if (strcasecmp($principal->email, self::CORREO_PRINCIPAL) !== 0) {
            $ocupado = User::query()
                ->whereRaw('LOWER(email) = ?', [self::CORREO_PRINCIPAL])
                ->where('id', '!=', $principal->id)
                ->exists();
            if ($ocupado) {
                throw new RuntimeException('el correo institucional ya pertenece a otra cuenta. No se duplicó el administrador.');
            }

            $anterior = $principal->email;
            $principal->update(['email' => self::CORREO_PRINCIPAL]);
            $this->auditar($principal, null, 'admin_email_institucional', [
                'email_anterior' => $anterior,
                'email_nuevo' => self::CORREO_PRINCIPAL,
            ]);
        }

        $coincidencias = $this->api->findIdentitiesByEmail(self::CORREO_PRINCIPAL);
        if (count($coincidencias) > 1) {
            throw new RuntimeException('hay más de una identidad con el correo institucional. No se vinculó.');
        }

        $creada = false;
        if (count($coincidencias) === 1) {
            $identidad = $coincidencias[0];
            $this->rechazarIdentidadAjena($identidad);
            if (strcasecmp($identidad['email'], self::CORREO_PRINCIPAL) !== 0) {
                throw new RuntimeException('la identidad encontrada no tiene el correo institucional.');
            }
        } else {
            $porUsuario = $this->api->findIdentityByUsername(self::USUARIO_PRINCIPAL);
            if ($porUsuario !== null) {
                throw new RuntimeException('el usuario institucional ya existe y no corresponde al correo indicado.');
            }
            $identidad = $this->api->createManagedUser(
                self::USUARIO_PRINCIPAL,
                $principal->name,
                self::CORREO_PRINCIPAL,
            );
            $creada = true;
            $this->rechazarIdentidadAjena($identidad);
        }

        $this->vincularIdentidad($principal, $identidad, 'admin_oidc_linked');
        $principal = $principal->fresh() ?? $principal;
        $grupo = $this->intentarGrupo($principal);
        $activacion = 'sin recuperación';
        $recuperacion = false;
        if ($creada) {
            $recuperacion = $this->api->requestRecovery($identidad['pk'], self::CORREO_PRINCIPAL);
            $activacion = $recuperacion
                ? 'Authentik aceptó la recuperación; la entrega del correo no quedó confirmada'
                : 'Authentik no permitió la recuperación. No se generó contraseña';
        }

        $yaActivoPorOidc = UsuarioAuditoria::query()
            ->where('user_id', $principal->id)
            ->where('accion', 'oidc_linked_activated')
            ->exists();
        if (! $recuperacion && ! $yaActivoPorOidc) {
            $principal->update([
                'activo' => false,
                'authentik_status' => User::AUTHENTIK_PREPARED,
                'authentik_last_error' => 'Identidad creada sin contraseña. Falta permiso para pqrsd-usuarios y para la recuperación.',
            ]);
            $activacion = 'pendiente: sin contraseña y sin permiso de recuperación';
        }

        return 'Administrador principal vinculado por sub e issuer. Grupo: '.$grupo.'. Activación: '.$activacion.'.';
    }

    private function autorizarAkadmin(): string
    {
        $identidad = $this->api->findIdentityByUsername(self::USUARIO_AKADMIN);
        if ($identidad === null || strcasecmp($identidad['username'], self::USUARIO_AKADMIN) !== 0) {
            throw new RuntimeException('no hay una identidad única con username akadmin.');
        }
        $this->rechazarIdentidadAjena($identidad);
        if ($identidad['email'] === '') {
            throw new RuntimeException('akadmin no tiene correo y no se inventó uno.');
        }

        $local = User::query()->where('authentik_sub', $identidad['uid'])->first();
        if (! $local) {
            $porCorreo = User::query()->whereRaw('LOWER(email) = ?', [strtolower($identidad['email'])])->first();
            if ($porCorreo) {
                throw new RuntimeException('el correo de akadmin ya pertenece a otra cuenta PQRSD. No se vinculó por correo.');
            }

            $local = User::query()->create([
                'name' => 'Administrador Authentik',
                'email' => strtolower($identidad['email']),
                'password' => Str::password(64),
                'documento' => self::USUARIO_AKADMIN,
                'activo' => true,
                'authentik_status' => User::AUTHENTIK_ACTIVE,
            ]);
            $local->assignRole('admin');
        }

        if (! $local->hasRole('admin')) {
            $local->assignRole('admin');
        }

        $this->vincularIdentidad($local, $identidad, 'akadmin_autorizado_expresamente');

        return 'akadmin vinculado por username, sub e issuer. No se modificó AdminSispam ni sus permisos en Authentik.';
    }

    /** @param  array{pk:string,uid:string,email:string,username:string}  $identidad */
    private function rechazarIdentidadAjena(array $identidad): void
    {
        if (strcasecmp($identidad['username'], 'adminsispam') === 0) {
            throw new RuntimeException('la identidad es AdminSispam y queda fuera de PQRSD.');
        }
    }

    /** @param  array{pk:string,uid:string,email:string,username:string}  $identidad */
    private function vincularIdentidad(User $user, array $identidad, string $accion): void
    {
        $ocupada = User::query()
            ->where('authentik_sub', $identidad['uid'])
            ->where('id', '!=', $user->id)
            ->exists();
        if ($ocupada) {
            throw new RuntimeException('esa identidad ya está vinculada a otra cuenta.');
        }

        if ($user->authentik_sub === $identidad['uid'] && $user->authentik_issuer === $this->emisorOidc() && $user->hasRole('admin')) {
            return;
        }

        $user->update([
            'authentik_sub' => $identidad['uid'],
            'authentik_user_pk' => $identidad['pk'],
            'authentik_issuer' => $this->emisorOidc(),
            'authentik_status' => User::AUTHENTIK_ACTIVE,
            'activo' => true,
            'authentik_last_error' => null,
        ]);
        $this->auditar($user, null, $accion, [
            'username' => $identidad['username'],
            'issuer' => $this->emisorOidc(),
        ]);
    }

    private function intentarGrupo(User $user): string
    {
        try {
            $groupPk = $this->resolveGroupPk();
            if (! $groupPk || blank($user->authentik_user_pk)) {
                return 'no verificado';
            }
            $this->api->addUserToGroup($groupPk, (string) $user->authentik_user_pk);
            $this->auditar($user, null, 'group_added', []);

            return 'incorporado a pqrsd-usuarios';
        } catch (Throwable $e) {
            report($e);
            $user->update([
                'authentik_last_error' => 'No se pudo consultar o modificar el grupo pqrsd-usuarios.',
            ]);
            $this->auditar($user, null, 'group_add_failed', ['error' => 'forbidden_or_unavailable']);

            return 'sin permiso para pqrsd-usuarios';
        }
    }

    /**
     * @return array{status:string,delivery:string,error:?string,message:string}
     */
    /**
     * @return array{status:string,delivery:string,message:string}
     */
    private function entregarInvitacionExistente(User $user, ?User $actor): array
    {
        $hash = $user->authentik_authorization_hash;
        if (filled($user->authentik_invitation_pk) && blank($hash)) {
            $hash = hash('sha256', (string) $user->authentik_invitation_pk);
        }
        $user->update([
            'activo' => false,
            'authentik_status' => User::AUTHENTIK_PREPARED,
            'authentik_authorization_hash' => $hash,
            'authentik_invitation_delivery' => 'private',
            'authentik_last_error' => null,
            'email_verified_at' => null,
        ]);
        $this->auditar($user, $actor, 'invitation_reused', [
            'delivery' => 'private',
        ]);

        return [
            'status' => User::AUTHENTIK_PREPARED,
            'delivery' => 'private',
            'message' => 'La invitación vigente sigue disponible en la ficha. No se envió correo ni se creó otro enlace.',
        ];
    }

    private function identidadYaExiste(User $user): bool
    {
        try {
            if ($this->api->findUserPkByEmail($user->email) !== null) {
                return true;
            }

            return $this->api->findIdentityByUsername($this->usernameInstitucional($user)) !== null;
        } catch (Throwable) {
            return true;
        }
    }

    private function errorSinSecreto(Throwable $e): string
    {
        $mensaje = preg_replace('/itoken=[^\s&]+/i', 'itoken=oculto', $e->getMessage()) ?? $e->getMessage();

        return Str::limit($mensaje, 300, '');
    }

    private function usernameInstitucional(User $user, bool $consultarAuthentik = false, bool $exigirConsulta = false): string
    {
        $actual = strtolower(trim((string) $user->documento));
        if ($this->usernameValido($actual) && ! $this->usernameOcupado($actual, $user, $consultarAuthentik, $exigirConsulta)) {
            return $actual;
        }

        $base = $this->normalizarIdentificador((string) $user->name);
        if (! $this->usernameValido($base)) {
            $base = $this->normalizarIdentificador((string) strstr((string) $user->email, '@', true));
        }
        if (! $this->usernameValido($base)) {
            throw new RuntimeException('No se pudo derivar un identificador interno seguro.');
        }

        $base = substr($base, 0, 24);
        for ($indice = 1; $indice <= 30; $indice++) {
            $sufijo = $indice === 1 ? '' : '.'.$indice;
            $candidato = substr($base, 0, 30 - strlen($sufijo)).$sufijo;
            if ($this->usernameOcupado($candidato, $user, $consultarAuthentik, $exigirConsulta)) {
                continue;
            }
            $user->update(['documento' => $candidato]);

            return $candidato;
        }

        throw new RuntimeException('No hay un identificador interno disponible sin colisión.');
    }

    private function usernameValido(string $username): bool
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,28}[a-z0-9])?$/', $username)) {
            return false;
        }

        return ! in_array($username, ['akadmin', 'adminsispam', 'practicantesistemas', '000000', 'admin', 'usuario', 'user', 'root'], true)
            && ! preg_match('/^0+$/', $username);
    }

    private function normalizarIdentificador(string $valor): string
    {
        $texto = strtolower(Str::ascii($valor));
        $texto = (string) preg_replace('/[^a-z0-9]+/', '.', $texto);

        return trim($texto, '.');
    }

    private function usernameOcupado(string $username, User $user, bool $consultarAuthentik, bool $exigirConsulta): bool
    {
        $local = User::query()
            ->where('documento', $username)
            ->where('id', '!=', $user->id)
            ->exists();
        if ($local) {
            return true;
        }
        if (! $consultarAuthentik) {
            return false;
        }

        try {
            if ($this->api->findIdentityByUsername($username) !== null) {
                return true;
            }
            if (! $exigirConsulta) {
                return false;
            }
            $directorio = $this->api->consultaDirectorio($username, '');
            if (in_array($directorio['username_http'], [403, 404], true) || ! $directorio['completa']) {
                throw new RuntimeException('GET /api/v3/core/users/ no permitió comprobar duplicados.');
            }

            return count($directorio['por_username']) > 0;
        } catch (Throwable $e) {
            if ($exigirConsulta) {
                throw new RuntimeException('GET /api/v3/core/users/ no permitió comprobar duplicados ('.$this->errorSinSecreto($e).').', 0, $e);
            }

            return false;
        }
    }

    private function emisorOidc(): string
    {
        $base = rtrim((string) config('authentik.base_url'), '/');
        $slug = (string) config('authentik.app_slug', 'pqrsd-comite');

        return $base.'/application/o/'.$slug.'/';
    }

    /** @param  array<string,mixed>  $detalle */
    private function auditar(User $user, ?User $actor, string $accion, array $detalle): void
    {
        unset($detalle['invitation_pk'], $detalle['link'], $detalle['url'], $detalle['authentik_invitation_url']);

        UsuarioAuditoria::create([
            'user_id' => $user->id,
            'actor_id' => $actor?->id,
            'accion' => $accion,
            'detalle' => $detalle,
        ]);
    }
}
