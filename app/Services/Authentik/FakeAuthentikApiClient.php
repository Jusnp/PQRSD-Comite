<?php

namespace App\Services\Authentik;

use DateTimeInterface;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Doble de prueba: no contacta Authentik real.
 */
class FakeAuthentikApiClient implements AuthentikApiClient
{
    /** @var list<array<string,mixed>> */
    public array $invitationsCreated = [];

    /** @var list<array<string,mixed>> */
    public array $invitationFichas = [];

    /** @var list<array{pk:string,email:string}> */
    public array $emailsSent = [];

    /** @var list<string> */
    public array $deletedInvitations = [];

    /** @var list<string> */
    public array $recoveryLinks = [];

    /** @var list<array{0:string,1:bool}> */
    public array $activated = [];

    /** @var list<array{group:string,user:string}> */
    public array $groupRemovals = [];

    /** @var list<array{group:string,user:string}> */
    public array $groupAdditions = [];

    public bool $emailConfigured = true;

    public bool $failNextInvitation = false;

    public ?bool $invitationExists = null;

    public bool $failNextCreate = false;

    public bool $failRecovery = false;

    public bool $recoveryAvailable = false;

    public bool $failNextEmail = false;

    /** @var array<string,string> email => user pk */
    public array $usersByEmail = [];

    /** @var array<string,string> sub => user pk */
    public array $usersBySub = [];

    /** @var list<array{pk:string,uid:string,email:string,username:string}> */
    public array $identities = [];

    /** @var list<array{pk:string,uid:string,email:string,username:string,active?:bool}> */
    public array $groupMembers = [];

    public ?int $groupHttpStatus = null;

    /** Lectura global simulada. En producción solo es verdadera con Can view User. */
    public bool $lecturaGlobalUsuarios = true;

    public ?int $directorioHttp = null;

    public ?int $deleteHttpStatus = null;

    public bool $failDeleteConnection = false;

    public bool $failGroupRemoval = false;

    public bool $failGroupAddition = false;

    public function resolveFlowPk(string $slugOrPk): ?string
    {
        if (Str::isUuid($slugOrPk)) {
            return $slugOrPk;
        }

        return '11111111-1111-1111-1111-111111111111';
    }

    public function resolveGroupPk(string $nameOrPk): ?string
    {
        if (Str::isUuid($nameOrPk)) {
            return $nameOrPk;
        }

        return '22222222-2222-2222-2222-222222222222';
    }

    public function createInvitation(array $payload): array
    {
        if ($this->failNextInvitation) {
            $this->failNextInvitation = false;
            throw new RuntimeException('Authentik simuló fallo al crear invitación.');
        }

        $pk = (string) Str::uuid();
        $expires = $payload['expires'] instanceof DateTimeInterface
            ? $payload['expires']->format(DATE_ATOM)
            : (string) $payload['expires'];

        $row = [
            'pk' => $pk,
            'name' => $payload['name'],
            'expires' => $expires,
            'flow' => $payload['flow_pk'],
            'fixed_data' => $payload['fixed_data'] ?? [],
            'single_use' => $payload['single_use'] ?? true,
        ];
        $this->invitationsCreated[] = $row;

        return [
            'pk' => $pk,
            'name' => $payload['name'],
            'expires' => $expires,
            'flow' => $payload['flow_pk'],
        ];
    }

    public function deleteInvitation(string $invitationPk): void
    {
        if ($this->failDeleteConnection) {
            $this->failDeleteConnection = false;
            throw new RuntimeException('No hubo respuesta de Authentik al revocar la invitación.');
        }
        if ($this->deleteHttpStatus !== null && ! in_array($this->deleteHttpStatus, [200, 204], true)) {
            $estado = $this->deleteHttpStatus;
            $this->deleteHttpStatus = null;
            throw new RuntimeException('DELETE /api/v3/stages/invitation/invitations/{pk}/ falló (HTTP '.$estado.').');
        }

        $this->deletedInvitations[] = $invitationPk;
    }

    public function inspeccionarInvitacion(string $invitationPk): array
    {
        foreach (array_merge($this->invitationsCreated, $this->invitationFichas) as $row) {
            if ((string) ($row['pk'] ?? '') !== $invitationPk) {
                continue;
            }
            $fixed = is_array($row['fixed_data'] ?? null) ? $row['fixed_data'] : [];

            return [
                'http' => 200,
                'flow' => (string) ($row['flow'] ?? ''),
                'username' => (string) ($fixed['username'] ?? ''),
                'email' => (string) ($fixed['email'] ?? ''),
                'name' => (string) ($fixed['name'] ?? ''),
            ];
        }

        return [
            'http' => 404,
            'flow' => '',
            'username' => '',
            'email' => '',
            'name' => '',
        ];
    }

    public function invitationExists(string $invitationPk): ?bool
    {
        return $this->invitationExists;
    }

    public function sendInvitationEmail(string $invitationPk, string $email): array
    {
        if (! $this->emailConfigured || $this->failNextEmail) {
            $this->failNextEmail = false;

            throw new RuntimeException('Correo saliente de Authentik no disponible.');
        }

        $this->emailsSent[] = ['pk' => $invitationPk, 'email' => $email];

        return ['queued' => true, 'message' => 'queued'];
    }

    public function isEmailConfigured(): bool
    {
        return $this->emailTransportStatus() === 'configured';
    }

    public function emailTransportStatus(): string
    {
        return $this->emailConfigured ? 'configured' : 'unavailable';
    }

    public function findUserPkByEmail(string $email): ?string
    {
        return $this->usersByEmail[strtolower($email)] ?? null;
    }

    public function findUserPkBySub(string $sub): ?string
    {
        if (isset($this->usersBySub[$sub])) {
            return $this->usersBySub[$sub];
        }

        return $this->findIdentityByUid($sub)['pk'] ?? null;
    }

    public function findIdentitiesByEmail(string $email): array
    {
        $coincidencias = array_values(array_filter(
            $this->identities,
            fn (array $identidad) => strcasecmp($identidad['email'], $email) === 0,
        ));
        if ($coincidencias !== []) {
            return $coincidencias;
        }

        return array_values(array_filter(
            $this->groupMemberIdentities((string) config('authentik.group_name')),
            fn (array $identidad) => strcasecmp($identidad['email'], $email) === 0,
        ));
    }

    public function findIdentityByUid(string $uid): ?array
    {
        foreach ($this->identities as $identidad) {
            if ($identidad['uid'] === $uid) {
                return $identidad;
            }
        }

        $enGrupo = array_values(array_filter(
            $this->groupMemberIdentities((string) config('authentik.group_name')),
            fn (array $identidad) => $identidad['uid'] === $uid,
        ));

        return count($enGrupo) === 1 ? $enGrupo[0] : null;
    }

    public function findIdentityByUsername(string $username): ?array
    {
        $coincidencias = array_values(array_filter(
            $this->identities,
            fn (array $identidad) => strcasecmp($identidad['username'], $username) === 0,
        ));
        if (count($coincidencias) === 1) {
            return $coincidencias[0];
        }
        if (count($coincidencias) > 1) {
            return null;
        }

        $enGrupo = array_values(array_filter(
            $this->groupMemberIdentities((string) config('authentik.group_name')),
            fn (array $identidad) => strcasecmp($identidad['username'], $username) === 0,
        ));

        return count($enGrupo) === 1 ? $enGrupo[0] : null;
    }

    public function consultaDirectorio(string $username, string $email): array
    {
        if ($this->directorioHttp !== null) {
            return [
                'completa' => false,
                'username_http' => $this->directorioHttp,
                'email_http' => $this->directorioHttp,
                'por_username' => [],
                'por_email' => [],
            ];
        }

        $normalizar = function (array $identidad): array {
            return [
                'pk' => (string) $identidad['pk'],
                'uid' => (string) ($identidad['uid'] ?? ''),
                'email' => (string) ($identidad['email'] ?? ''),
                'username' => (string) ($identidad['username'] ?? ''),
                'active' => (bool) ($identidad['active'] ?? true),
            ];
        };
        $porUsername = $username === '' ? [] : array_values(array_filter(
            $this->identities,
            fn (array $identidad): bool => strcasecmp((string) $identidad['username'], $username) === 0,
        ));
        $porEmail = $email === '' ? [] : array_values(array_filter(
            $this->identities,
            fn (array $identidad): bool => strcasecmp((string) $identidad['email'], $email) === 0,
        ));
        $pkCorreo = $email === '' ? null : ($this->usersByEmail[strtolower($email)] ?? null);
        if ($pkCorreo !== null && ! collect($porEmail)->contains(fn (array $identidad): bool => (string) $identidad['pk'] === (string) $pkCorreo)) {
            $porEmail[] = [
                'pk' => (string) $pkCorreo,
                'uid' => '',
                'email' => $email,
                'username' => '',
                'active' => true,
            ];
        }

        return [
            'completa' => $this->lecturaEfectiva(),
            'username_http' => 200,
            'email_http' => 200,
            'por_username' => array_map($normalizar, $porUsername),
            'por_email' => array_map($normalizar, $porEmail),
        ];
    }

    public function lecturaEfectiva(): bool
    {
        return $this->lecturaGlobalUsuarios && $this->directorioHttp === null;
    }

    public function groupMemberIdentities(string $groupName): array
    {
        if ($this->groupHttpStatus !== null) {
            throw new RuntimeException('GET /api/v3/core/groups/ falló (HTTP '.$this->groupHttpStatus.').');
        }

        return array_map(function (array $identidad): array {
            $identidad['active'] = $identidad['active'] ?? true;

            return $identidad;
        }, $this->groupMembers);
    }

    public function createManagedUser(string $username, string $name, string $email): array
    {
        if ($this->failNextCreate) {
            $this->failNextCreate = false;
            throw new RuntimeException('Authentik rechazó la creación del usuario (HTTP 403).');
        }

        $identidad = [
            'pk' => (string) Str::uuid(),
            'uid' => 'uid-'.Str::lower($username),
            'email' => strtolower($email),
            'username' => $username,
        ];
        $this->identities[] = $identidad;
        $this->usersByEmail[strtolower($email)] = $identidad['pk'];
        $this->usersBySub[$identidad['uid']] = $identidad['pk'];

        return $identidad;
    }

    public function requestRecovery(string $userPk, string $email): bool
    {
        return $this->recoveryAvailable;
    }

    public function requestRecoveryLink(string $userPk): string
    {
        if ($this->failRecovery) {
            throw new RuntimeException('Authentik no entregó un enlace privado de recuperación (HTTP 403).');
        }

        $this->recoveryLinks[] = $userPk;

        return 'https://auth.example.test/if/flow/default-recovery/?itoken=recovery-'.$userPk;
    }

    public function setUserActive(string $userPk, bool $active): void
    {
        $this->activated[] = [$userPk, $active];
    }

    public function removeUserFromGroup(string $groupPk, string $userPk): void
    {
        if ($this->failGroupRemoval) {
            $this->failGroupRemoval = false;
            throw new RuntimeException('Authentik rechazó el retiro del grupo (HTTP 403).');
        }

        $this->groupRemovals[] = ['group' => $groupPk, 'user' => $userPk];
    }

    public function addUserToGroup(string $groupPk, string $userPk): void
    {
        if ($this->failGroupAddition) {
            $this->failGroupAddition = false;
            throw new RuntimeException('Authentik rechazó incorporar al grupo (HTTP 403).');
        }

        $this->groupAdditions[] = ['group' => $groupPk, 'user' => $userPk];
    }
}
