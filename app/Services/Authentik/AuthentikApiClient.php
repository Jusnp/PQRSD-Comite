<?php

namespace App\Services\Authentik;

/**
 * Cliente de la API de Authentik (alcance de servicio mínimo).
 */
interface AuthentikApiClient
{
    public function resolveFlowPk(string $slugOrPk): ?string;

    public function resolveGroupPk(string $nameOrPk): ?string;

    /**
     * @param  array{name:string,email:string,expires:\DateTimeInterface,flow_pk:string,single_use?:bool,fixed_data?:array<string,mixed>}  $payload
     * @return array{pk:string,name:string,expires:?string,flow:?string}
     */
    public function createInvitation(array $payload): array;

    public function deleteInvitation(string $invitationPk): void;

    /**
     * Ficha mínima para comprobar que la invitación pertenece al flujo y al funcionario.
     * No incluye el enlace ni el token.
     *
     * @return array{http:int,flow:string,username:string,email:string,name:string}
     */
    public function inspeccionarInvitacion(string $invitationPk): array;

    /**
     * true si existe, false si Authentik responde 404, null si no se pudo comprobar.
     */
    public function invitationExists(string $invitationPk): ?bool;

    /**
     * @return array{queued:bool,message?:string}
     */
    public function sendInvitationEmail(string $invitationPk, string $email): array;

    public function isEmailConfigured(): bool;

    /**
     * configured, unavailable o unknown. Unknown no demuestra que el correo falle.
     */
    public function emailTransportStatus(): string;

    public function findUserPkByEmail(string $email): ?string;

    public function findUserPkBySub(string $sub): ?string;

    /**
     * @return list<array{pk:string,uid:string,email:string,username:string,active:bool}>
     */
    public function findIdentitiesByEmail(string $email): array;

    /**
     * @return array{pk:string,uid:string,email:string,username:string,active:bool}|null
     */
    public function findIdentityByUid(string $uid): ?array;

    /**
     * @return array{pk:string,uid:string,email:string,username:string,active:bool}|null
     */
    public function findIdentityByUsername(string $username): ?array;

    /**
     * Miembros completos del grupo. El listado /core/users/ de esta versión puede ocultar
     * identidades que sí aparecen en users_obj del grupo.
     *
     * @return list<array{pk:string,uid:string,email:string,username:string,active:bool}>
     */
    public function groupMemberIdentities(string $groupName): array;

    /**
     * Búsqueda solo en /core/users/, sin usar la membresía del grupo.
     * completa=true solo si la lectura efectiva del directorio quedó demostrada.
     * Un HTTP 200 vacío no demuestra que la identidad no exista.
     *
     * @return array{completa:bool,username_http:int,email_http:int,por_username:list<array{pk:string,uid:string,email:string,username:string,active:bool}>,por_email:list<array{pk:string,uid:string,email:string,username:string,active:bool}>}
     */
    public function consultaDirectorio(string $username, string $email): array;

    /**
     * Capacidad efectiva de leer el directorio, independiente de system_permissions.
     */
    public function lecturaEfectiva(): bool;

    /**
     * @return array{pk:string,uid:string,email:string,username:string}
     */
    public function createManagedUser(string $username, string $name, string $email): array;

    public function requestRecovery(string $userPk, string $email): bool;

    public function requestRecoveryLink(string $userPk): string;

    public function setUserActive(string $userPk, bool $active): void;

    public function removeUserFromGroup(string $groupPk, string $userPk): void;

    public function addUserToGroup(string $groupPk, string $userPk): void;
}
