<?php

namespace App\Services\Authentik;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class HttpAuthentikApiClient implements AuthentikApiClient
{

    public function resolveFlowPk(string $slugOrPk): ?string
    {
        if (Str::isUuid($slugOrPk)) {
            return $slugOrPk;
        }

        $response = $this->http()->get('/api/v3/flows/instances/'.$slugOrPk.'/');
        if ($response->successful()) {
            return $response->json('pk');
        }

        $list = $this->http()->get('/api/v3/flows/instances/', ['slug' => $slugOrPk]);
        if ($list->successful()) {
            return data_get($list->json('results.0'), 'pk');
        }

        return null;
    }

    public function resolveGroupPk(string $nameOrPk): ?string
    {
        if (Str::isUuid($nameOrPk)) {
            return $nameOrPk;
        }

        $list = $this->http()->get('/api/v3/core/groups/', ['name' => $nameOrPk]);
        if ($list->status() === 401 || $list->status() === 403) {
            throw new RuntimeException('Authentik no permitió consultar grupos (HTTP '.$list->status().').');
        }
        if ($list->successful()) {
            return data_get($list->json('results.0'), 'pk');
        }

        return null;
    }

    public function createInvitation(array $payload): array
    {
        $body = [
            'name' => $payload['name'],
            'expires' => $payload['expires']->format('Y-m-d\TH:i:s\Z'),
            'single_use' => $payload['single_use'] ?? true,
            'flow' => $payload['flow_pk'],
            'fixed_data' => $payload['fixed_data'] ?? [],
        ];

        $response = $this->http()->post('/api/v3/stages/invitation/invitations/', $body);
        if (! $response->successful()) {
            throw new RuntimeException('POST /api/v3/stages/invitation/invitations/ falló (HTTP '.$response->status().').');
        }

        return [
            'pk' => (string) $response->json('pk'),
            'name' => (string) $response->json('name'),
            'expires' => $response->json('expires'),
            'flow' => $response->json('flow'),
        ];
    }

    public function deleteInvitation(string $invitationPk): void
    {
        $response = $this->http()->delete('/api/v3/stages/invitation/invitations/'.$invitationPk.'/');
        if (! in_array($response->status(), [200, 204], true)) {
            throw new RuntimeException('DELETE /api/v3/stages/invitation/invitations/{pk}/ falló (HTTP '.$response->status().').');
        }
    }

    public function inspeccionarInvitacion(string $invitationPk): array
    {
        try {
            $response = $this->http()->get('/api/v3/stages/invitation/invitations/'.$invitationPk.'/');
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo consultar la invitación.');
        }

        $flow = $response->json('flow');
        if (is_array($flow)) {
            $flow = (string) ($flow['pk'] ?? $flow['slug'] ?? '');
        }
        $fixed = $response->json('fixed_data');
        if (! is_array($fixed)) {
            $fixed = [];
        }

        return [
            'http' => $response->status(),
            'flow' => (string) $flow,
            'username' => (string) ($fixed['username'] ?? ''),
            'email' => (string) ($fixed['email'] ?? ''),
            'name' => (string) ($fixed['name'] ?? ''),
        ];
    }

    public function invitationExists(string $invitationPk): ?bool
    {
        $response = $this->http()->get('/api/v3/stages/invitation/invitations/'.$invitationPk.'/');
        if ($response->status() === 404) {
            return false;
        }
        if ($response->successful()) {
            return true;
        }

        return null;
    }

    public function sendInvitationEmail(string $invitationPk, string $email): array
    {
        $response = $this->http()->post(
            '/api/v3/stages/invitation/invitations/'.$invitationPk.'/send_email/',
            [
                'email_addresses' => [$email],
                'template' => 'invitation',
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException('No se pudo encolar el correo de invitación (HTTP '.$response->status().').');
        }

        return ['queued' => true, 'message' => (string) ($response->json('detail') ?? 'queued')];
    }

    public function isEmailConfigured(): bool
    {
        return $this->emailTransportStatus() === 'configured';
    }

    public function emailTransportStatus(): string
    {
        $forced = config('authentik.email_configured');
        if ($forced !== null && $forced !== '') {
            return filter_var($forced, FILTER_VALIDATE_BOOLEAN) ? 'configured' : 'unavailable';
        }

        $response = $this->http()->get('/api/v3/admin/settings/');
        if (! $response->successful()) {
            return 'unknown';
        }

        $host = data_get($response->json(), 'email__host')
            ?? data_get($response->json(), 'email.host');

        return filled($host) ? 'configured' : 'unavailable';
    }

    public function findUserPkByEmail(string $email): ?string
    {
        $response = $this->http()->get('/api/v3/core/users/', [
            'email' => $email,
            'page_size' => 5,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('Authentik no permitió consultar usuarios (HTTP '.$response->status().').');
        }

        $coincidencias = [];
        foreach ((array) $response->json('results', []) as $row) {
            if (strcasecmp((string) data_get($row, 'email'), $email) === 0 && filled(data_get($row, 'pk'))) {
                $coincidencias[] = (string) data_get($row, 'pk');
            }
        }

        if (count($coincidencias) > 1) {
            throw new RuntimeException('Hay más de una identidad Authentik con ese correo. No se creó otra.');
        }

        return $coincidencias[0] ?? null;
    }

    public function findUserPkBySub(string $sub): ?string
    {
        return $this->findIdentityByUid($sub)['pk'] ?? null;
    }

    public function findIdentitiesByEmail(string $email): array
    {
        $coincidencias = $this->identidadesExactas(
            ['email' => $email],
            fn (array $fila): bool => strcasecmp((string) data_get($fila, 'email'), $email) === 0,
        );
        if ($coincidencias !== []) {
            return $coincidencias;
        }

        return array_values(array_filter(
            $this->groupMemberIdentities((string) config('authentik.group_name')),
            fn (array $identidad): bool => strcasecmp($identidad['email'], $email) === 0,
        ));
    }

    public function findIdentityByUid(string $uid): ?array
    {
        $coincidencias = $this->identidadesExactas(
            ['uid' => $uid],
            fn (array $fila): bool => (string) data_get($fila, 'uid') === $uid,
        );
        if (count($coincidencias) === 1) {
            return $coincidencias[0];
        }
        if (count($coincidencias) > 1) {
            return null;
        }

        $enGrupo = array_values(array_filter(
            $this->groupMemberIdentities((string) config('authentik.group_name')),
            fn (array $identidad): bool => $identidad['uid'] === $uid,
        ));

        return count($enGrupo) === 1 ? $enGrupo[0] : null;
    }

    public function findIdentityByUsername(string $username): ?array
    {
        $coincidencias = $this->identidadesExactas(
            ['username' => $username],
            fn (array $fila): bool => strcasecmp((string) data_get($fila, 'username'), $username) === 0,
        );
        if (count($coincidencias) === 1) {
            return $coincidencias[0];
        }
        if (count($coincidencias) > 1) {
            return null;
        }

        $enGrupo = array_values(array_filter(
            $this->groupMemberIdentities((string) config('authentik.group_name')),
            fn (array $identidad): bool => strcasecmp($identidad['username'], $username) === 0,
        ));

        return count($enGrupo) === 1 ? $enGrupo[0] : null;
    }

    public function groupMemberIdentities(string $groupName): array
    {
        $grupos = [];
        $pagina = 1;
        $paginas = 1;
        do {
            $respuesta = $this->http()->get('/api/v3/core/groups/', [
                'name' => $groupName,
                'page' => $pagina,
                'page_size' => 50,
            ]);
            if (! $respuesta->successful()) {
                throw new RuntimeException('GET /api/v3/core/groups/ falló (HTTP '.$respuesta->status().').');
            }
            foreach ((array) $respuesta->json('results', []) as $fila) {
                if (strcasecmp((string) data_get($fila, 'name'), $groupName) === 0) {
                    $grupos[] = $fila;
                }
            }
            $paginas = max(1, (int) $respuesta->json('pagination.total_pages', 1));
            $pagina++;
        } while ($pagina <= $paginas && $pagina <= 20);

        if (count($grupos) !== 1) {
            throw new RuntimeException('Authentik no devolvió un único grupo.');
        }

        $ids = array_values((array) data_get($grupos[0], 'users', []));
        $objetos = array_values((array) data_get($grupos[0], 'users_obj', []));
        if (count($ids) !== count($objetos)) {
            throw new RuntimeException('Authentik no entregó los miembros completos del grupo.');
        }

        $identidades = [];
        foreach ($objetos as $fila) {
            if (! is_array($fila)) {
                continue;
            }
            $identidad = $this->mapearIdentidad($fila);
            if ($identidad !== null) {
                $identidades[] = $identidad;
            }
        }

        return $identidades;
    }

    public function consultaDirectorio(string $username, string $email): array
    {
        $porUsername = $this->buscarEnDirectorio('username', $username);
        $porEmail = $this->buscarEnDirectorio('email', $email);

        return [
            'completa' => $this->lecturaEfectiva(),
            'username_http' => $porUsername['http'],
            'email_http' => $porEmail['http'],
            'por_username' => $porUsername['coincidencias'],
            'por_email' => $porEmail['coincidencias'],
        ];
    }

    public function lecturaEfectiva(): bool
    {
        if (Cache::get('authentik.lectura-efectiva') === true) {
            return true;
        }

        $efectiva = $this->comprobarLecturaEfectiva();
        if ($efectiva) {
            Cache::put('authentik.lectura-efectiva', true, 60);
        }

        return $efectiva;
    }

    /**
     * @return array{http:int,coincidencias:list<array{pk:string,uid:string,email:string,username:string,active:bool}>}
     */
    private function buscarEnDirectorio(string $campo, string $valor): array
    {
        if ($valor === '') {
            return ['http' => 200, 'coincidencias' => []];
        }

        try {
            $filas = $this->recolectarUsuarios([$campo => $valor]);
        } catch (RuntimeException $excepcion) {
            if (preg_match('/HTTP (\d+)/', $excepcion->getMessage(), $codigo)) {
                return ['http' => (int) $codigo[1], 'coincidencias' => []];
            }

            throw $excepcion;
        }

        $coincidencias = [];
        foreach ($filas as $fila) {
            if (strcasecmp((string) data_get($fila, $campo), $valor) !== 0) {
                continue;
            }
            $identidad = $this->mapearIdentidad($fila);
            if ($identidad !== null) {
                $coincidencias[] = $identidad;
            }
        }

        return ['http' => 200, 'coincidencias' => $coincidencias];
    }

    /**
     * Can view User global fue asignado por un administrador. En Authentik 2024.8.3
     * system_permissions puede seguir vacío; la capacidad se demuestra con consultas.
     * No usa IDs ni cantidades fijas como regla de negocio.
     */
    private function comprobarLecturaEfectiva(): bool
    {
        try {
            $respuesta = $this->http()->get('/api/v3/core/users/me/');
            if (! $respuesta->successful()) {
                return false;
            }
            $usuario = $respuesta->json('user');
            $usuario = is_array($usuario) ? $usuario : (array) $respuesta->json();
            $esperada = strtolower((string) config('authentik.service_username', 'pqrsd-servicio'));
            if ($esperada === '' || strcasecmp((string) ($usuario['username'] ?? ''), $esperada) !== 0) {
                return false;
            }

            $directorio = $this->recolectarDirectorioCoherente();
            if ($directorio === null) {
                return false;
            }

            $miembros = $this->groupMemberIdentities((string) config('authentik.group_name'));
            $enGrupo = [];
            foreach ($miembros as $miembro) {
                if (! isset($directorio[$miembro['pk']])) {
                    return false;
                }
                $enGrupo[$miembro['pk']] = true;
            }
            if ($enGrupo === []) {
                return false;
            }

            $dentro = false;
            $fuera = false;
            foreach ($directorio as $pk => $fila) {
                if (! $this->filaConsultable($fila) || ! $this->busquedaExactaCoincide($fila)) {
                    continue;
                }
                if (isset($enGrupo[$pk])) {
                    $dentro = true;
                } else {
                    $fuera = true;
                }
                if ($dentro && $fuera) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array{pk:string,username:string,email:string}  $fila
     */
    private function filaConsultable(array $fila): bool
    {
        return $fila['username'] !== '' && $fila['email'] !== '';
    }

    /**
     * @param  array{pk:string,username:string,email:string}  $fila
     */
    private function busquedaExactaCoincide(array $fila): bool
    {
        foreach (['username' => $fila['username'], 'email' => $fila['email']] as $campo => $valor) {
            $busqueda = $this->buscarEnDirectorio($campo, $valor);
            if ($busqueda['http'] !== 200 || count($busqueda['coincidencias']) !== 1) {
                return false;
            }
            if ((string) $busqueda['coincidencias'][0]['pk'] !== $fila['pk']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, array{pk:string,username:string,email:string}>|null
     */
    private function recolectarDirectorioCoherente(): ?array
    {
        $filas = [];
        $pagina = 1;
        $paginas = null;
        $total = null;
        do {
            $respuesta = $this->http()->get('/api/v3/core/users/', [
                'page' => $pagina,
                'page_size' => 100,
            ]);
            if (! $respuesta->successful()) {
                return null;
            }
            $count = $respuesta->json('pagination.count');
            $totalPages = $respuesta->json('pagination.total_pages');
            if (! is_int($count) && ! (is_string($count) && ctype_digit($count))) {
                return null;
            }
            $count = (int) $count;
            $totalPages = max(1, (int) $totalPages);
            if ($paginas === null) {
                $paginas = $totalPages;
                $total = $count;
            } elseif ($totalPages !== $paginas || $count !== $total) {
                return null;
            }
            $resultados = (array) $respuesta->json('results', []);
            if (count($resultados) > 100 || ($pagina < $paginas && $resultados === [])) {
                return null;
            }
            foreach ($resultados as $fila) {
                if (! is_array($fila) || (string) ($fila['pk'] ?? '') === '') {
                    return null;
                }
                $pk = (string) $fila['pk'];
                if (isset($filas[$pk])) {
                    return null;
                }
                $filas[$pk] = [
                    'pk' => $pk,
                    'username' => (string) ($fila['username'] ?? ''),
                    'email' => strtolower(trim((string) ($fila['email'] ?? ''))),
                ];
            }
            $pagina++;
        } while ($pagina <= $paginas && $pagina <= 20);

        if ($paginas === null || $total === null || $pagina <= $paginas || count($filas) !== $total || $total < 1) {
            return null;
        }

        return $filas;
    }

    /**
     * @param  array<string, mixed>  $filtro
     * @param  callable(array<string, mixed>): bool  $coincide
     * @return list<array{pk:string,uid:string,email:string,username:string,active:bool}>
     */
    private function identidadesExactas(array $filtro, callable $coincide): array
    {
        try {
            $filas = $this->recolectarUsuarios($filtro);
        } catch (RuntimeException $excepcion) {
            if (! preg_match('/HTTP (403|404)/', $excepcion->getMessage())) {
                throw $excepcion;
            }

            return [];
        }

        $identidades = [];
        foreach ($filas as $fila) {
            if (! $coincide($fila)) {
                continue;
            }
            $identidad = $this->mapearIdentidad($fila);
            if ($identidad !== null) {
                $identidades[] = $identidad;
            }
        }

        return $identidades;
    }

    /**
     * @param  array<string, mixed>  $filtro
     * @return list<array<string, mixed>>
     */
    private function recolectarUsuarios(array $filtro): array
    {
        $filas = [];
        $pagina = 1;
        $paginas = 1;
        do {
            $respuesta = $this->http()->get('/api/v3/core/users/', array_merge($filtro, [
                'page' => $pagina,
                'page_size' => 100,
            ]));
            if (! $respuesta->successful()) {
                throw new RuntimeException('GET /api/v3/core/users/ falló (HTTP '.$respuesta->status().').');
            }
            foreach ((array) $respuesta->json('results', []) as $fila) {
                if (is_array($fila)) {
                    $filas[] = $fila;
                }
            }
            $paginas = max(1, (int) $respuesta->json('pagination.total_pages', 1));
            $pagina++;
        } while ($pagina <= $paginas && $pagina <= 20);

        return $filas;
    }

    public function createManagedUser(string $username, string $name, string $email): array
    {
        $response = $this->http()->post('/api/v3/core/users/', [
            'username' => $username,
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'path' => 'users',
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('Authentik rechazó la creación del usuario (HTTP '.$response->status().').');
        }

        $identidad = $this->mapearIdentidad((array) $response->json());
        if ($identidad === null || strcasecmp($identidad['username'], $username) !== 0) {
            throw new RuntimeException('Authentik no devolvió la identidad creada.');
        }

        return $identidad;
    }

    public function requestRecovery(string $userPk, string $email): bool
    {
        $response = $this->http()->post('/api/v3/core/users/'.$userPk.'/recovery/', [
            'email' => $email,
        ]);

        return $response->successful();
    }

    public function requestRecoveryLink(string $userPk): string
    {
        $response = $this->http()->post('/api/v3/core/users/'.$userPk.'/recovery/', [
            'token_duration' => 'hours=48',
        ]);
        $link = $response->successful() ? $response->json('link') : null;
        if (! is_string($link) || $link === '') {
            throw new RuntimeException('Authentik no entregó un enlace privado de recuperación (HTTP '.$response->status().').');
        }

        return $link;
    }

    public function setUserActive(string $userPk, bool $active): void
    {
        $response = $this->http()->patch('/api/v3/core/users/'.$userPk.'/', [
            'is_active' => $active,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('No se pudo actualizar el estado de la identidad (HTTP '.$response->status().').');
        }
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{pk:string,uid:string,email:string,username:string,active:bool}|null
     */
    private function mapearIdentidad(array $row): ?array
    {
        $pk = (string) data_get($row, 'pk');
        $uid = (string) data_get($row, 'uid');
        if ($pk === '' || $uid === '') {
            return null;
        }

        return [
            'pk' => $pk,
            'uid' => $uid,
            'email' => strtolower(trim((string) data_get($row, 'email'))),
            'username' => (string) data_get($row, 'username'),
            'active' => array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true,
        ];
    }

    public function removeUserFromGroup(string $groupPk, string $userPk): void
    {
        $response = $this->http()->post('/api/v3/core/groups/'.$groupPk.'/remove_user/', [
            'pk' => $userPk,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('No se pudo retirar al usuario del grupo Authentik: '.$response->body());
        }
    }

    public function addUserToGroup(string $groupPk, string $userPk): void
    {
        $response = $this->http()->post('/api/v3/core/groups/'.$groupPk.'/add_user/', [
            'pk' => $userPk,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('No se pudo agregar al usuario al grupo Authentik: '.$response->body());
        }
    }

    private function http(): PendingRequest
    {
        $base = (string) config('authentik.base_url');
        $token = (string) config('authentik.api_token');

        if ($base === '' || ! str_starts_with($base, 'https://')) {
            throw new RuntimeException('AUTHENTIK_BASE_URL debe ser HTTPS.');
        }
        if ($token === '') {
            throw new RuntimeException('AUTHENTIK_API_TOKEN no configurado.');
        }

        return Http::baseUrl($base)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(25);
    }
}
