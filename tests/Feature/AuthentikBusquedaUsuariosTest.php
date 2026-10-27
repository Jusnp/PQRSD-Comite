<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Authentik\AuthentikApiClient;
use App\Services\Authentik\AuthentikProvisionService;
use App\Services\Authentik\HttpAuthentikApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthentikBusquedaUsuariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'authentik.base_url' => 'https://auth.example.com',
            'authentik.api_token' => 'token-prueba',
            'authentik.group_name' => 'pqrsd-usuarios',
            'authentik.enrollment_flow_pk' => '11111111-1111-1111-1111-111111111111',
        ]);
        $this->app->instance(AuthentikApiClient::class, new HttpAuthentikApiClient);
        Cache::forget('authentik.lectura-efectiva');
    }

    public function test_el_grupo_identifica_al_usuario_cuando_el_listado_responde_vacio_y_el_detalle_404(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/api/v3/core/users/45/')) {
                return Http::response(['detail' => 'Not found.'], 404);
            }
            if (str_contains($url, '/api/v3/core/groups/')) {
                return Http::response($this->grupo([$this->juan()]));
            }

            return Http::response($this->paginaUsuarios([]), 200);
        });

        $identidad = app(AuthentikProvisionService::class)->consultarIdentidadCoincidente($this->funcionario());

        $this->assertSame('45', $identidad['pk'] ?? null);
        $this->assertSame('uid-juan-real', $identidad['uid'] ?? null);
        $this->assertNotSame('45', $identidad['uid'] ?? null);
    }

    public function test_la_busqueda_recorre_las_paginas_siguientes(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/api/v3/core/groups/')) {
                return Http::response($this->grupo([$this->juan()]));
            }
            $consulta = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
            if ((int) ($consulta['page'] ?? 1) === 1) {
                return Http::response($this->paginaUsuarios([], 2));
            }

            return Http::response($this->paginaUsuarios([$this->juan()], 2, 2));
        });

        $identidad = app(AuthentikApiClient::class)->findIdentityByUsername('juan.perez.prueba');

        $this->assertSame('45', $identidad['pk'] ?? null);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'page=2'));
    }

    public function test_403_del_listado_no_se_interpreta_como_usuario_inexistente(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/api/v3/core/groups/')) {
                return Http::response($this->grupo([$this->juan()]));
            }

            return Http::response(['detail' => 'Forbidden'], 403);
        });

        $identidad = app(AuthentikProvisionService::class)->consultarIdentidadCoincidente($this->funcionario());

        $this->assertSame('45', $identidad['pk'] ?? null);
    }

    public function test_403_del_grupo_duplicado_o_cuenta_ajena_no_verifican(): void
    {
        $funcionario = $this->funcionario();

        Http::fake(fn () => Http::response(['detail' => 'Forbidden'], 403));
        $this->assertNull(app(AuthentikProvisionService::class)->consultarIdentidadCoincidente($funcionario));

        Http::fake(fn (Request $request) => str_contains($request->url(), '/api/v3/core/groups/')
            ? Http::response($this->grupo([$this->juan(), $this->juan(46, 'uid-otro')]))
            : Http::response($this->paginaUsuarios([])));
        $this->assertNull(app(AuthentikProvisionService::class)->consultarIdentidadCoincidente($funcionario));

        Http::fake(fn (Request $request) => str_contains($request->url(), '/api/v3/core/groups/')
            ? Http::response($this->grupo([$this->juan()]))
            : Http::response($this->paginaUsuarios([])));
        User::factory()->create([
            'email' => 'otro.funcionario@example.test',
            'authentik_user_pk' => '45',
        ]);
        $this->assertNull(app(AuthentikProvisionService::class)->consultarIdentidadCoincidente($funcionario));
    }

    public function test_directorio_sin_view_user_no_declara_ausencia_y_la_pagina_dos_si_cuenta(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/api/v3/core/users/me/')) {
                return Http::response(['user' => [
                    'pk' => 43,
                    'username' => 'pqrsd-servicio',
                    'is_superuser' => false,
                    'system_permissions' => [],
                ]]);
            }
            if (str_contains($url, '/api/v3/core/groups/')) {
                return Http::response($this->grupo([]));
            }
            $consulta = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
            if (($consulta['username'] ?? '') === 'persona.oculta') {
                if ((int) ($consulta['page'] ?? 1) === 1) {
                    return Http::response($this->paginaUsuarios([], 2));
                }

                return Http::response($this->paginaUsuarios([[
                    'pk' => 80,
                    'username' => 'persona.oculta',
                    'email' => 'persona.oculta@example.test',
                    'is_active' => true,
                    'uid' => 'uid-oculta',
                ]], 2, 2));
            }

            return Http::response($this->paginaUsuarios([]));
        });

        $servicio = app(AuthentikProvisionService::class);
        $vacio = User::factory()->create([
            'email' => 'nadie@example.test',
            'documento' => 'nadie',
            'activo' => false,
        ]);
        $this->assertSame('insuficiente', $servicio->evaluarAlta($vacio));

        $oculta = User::factory()->create([
            'email' => 'persona.oculta@example.test',
            'documento' => 'persona.oculta',
            'activo' => false,
        ]);
        $this->assertSame('existente', $servicio->evaluarAlta($oculta));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'page=2'));
    }

    public function test_system_permissions_vacio_no_impide_una_lectura_efectiva(): void
    {
        $dentro = $this->juan();
        $fuera = [
            'pk' => 42,
            'username' => 'cuenta.externa',
            'email' => 'cuenta.externa@example.test',
            'is_active' => true,
            'uid' => 'uid-externa',
        ];
        Http::fake(function (Request $request) use ($dentro, $fuera) {
            $url = $request->url();
            if (str_contains($url, '/api/v3/core/users/me/')) {
                return Http::response(['user' => [
                    'pk' => 43,
                    'username' => 'pqrsd-servicio',
                    'is_superuser' => false,
                    'system_permissions' => [],
                ]]);
            }
            if (str_contains($url, '/api/v3/core/groups/')) {
                return Http::response($this->grupo([$dentro]));
            }
            $consulta = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
            if (($consulta['username'] ?? '') === 'juan.perez.prueba' || ($consulta['email'] ?? '') === 'persona.prueba@example.test') {
                return Http::response($this->paginaUsuarios([$dentro]));
            }
            if (($consulta['username'] ?? '') === 'cuenta.externa' || ($consulta['email'] ?? '') === 'cuenta.externa@example.test') {
                return Http::response($this->paginaUsuarios([$fuera]));
            }
            if (($consulta['username'] ?? '') !== '' || ($consulta['email'] ?? '') !== '') {
                return Http::response($this->paginaUsuarios([]));
            }
            if ((int) ($consulta['page'] ?? 1) === 1) {
                return Http::response($this->paginaDirectorio([$dentro], 2, 1));
            }

            return Http::response($this->paginaDirectorio([$fuera], 2, 2));
        });

        $servicio = app(AuthentikProvisionService::class);
        $this->assertTrue(app(AuthentikApiClient::class)->lecturaEfectiva());
        $nueva = User::factory()->create([
            'email' => 'persona.nueva@example.test',
            'documento' => 'persona.nueva',
            'activo' => false,
        ]);
        $this->assertSame('nueva', $servicio->evaluarAlta($nueva));
        $externa = User::factory()->create([
            'email' => 'cuenta.externa@example.test',
            'documento' => 'cuenta.externa',
            'activo' => false,
        ]);
        $this->assertSame('existente', $servicio->evaluarAlta($externa));
    }

    public function test_listado_incompleto_o_sin_cuenta_fuera_del_grupo_no_habilita_el_alta(): void
    {
        $juan = $this->juan();
        Http::fake(function (Request $request) use ($juan) {
            if (str_contains($request->url(), '/api/v3/core/users/me/')) {
                return Http::response(['user' => [
                    'username' => 'pqrsd-servicio',
                    'is_superuser' => false,
                    'system_permissions' => [],
                ]]);
            }
            if (str_contains($request->url(), '/api/v3/core/groups/')) {
                return Http::response($this->grupo([$juan]));
            }

            return Http::response([
                'pagination' => ['count' => 2, 'current' => 1, 'total_pages' => 1, 'next' => 0],
                'results' => [$juan],
            ]);
        });

        $user = User::factory()->create([
            'email' => 'persona.nueva@example.test',
            'documento' => 'persona.nueva',
            'activo' => false,
        ]);
        $this->assertSame('insuficiente', app(AuthentikProvisionService::class)->evaluarAlta($user));

        Cache::forget('authentik.lectura-efectiva');
        Http::fake(function (Request $request) use ($juan) {
            if (str_contains($request->url(), '/api/v3/core/users/me/')) {
                return Http::response(['user' => ['username' => 'pqrsd-servicio', 'system_permissions' => []]]);
            }
            if (str_contains($request->url(), '/api/v3/core/groups/')) {
                return Http::response($this->grupo([$juan]));
            }

            return Http::response($this->paginaUsuarios([$juan]));
        });
        $this->assertFalse(app(AuthentikApiClient::class)->lecturaEfectiva());

        Cache::forget('authentik.lectura-efectiva');
        Http::fake(fn () => Http::response(['detail' => 'Forbidden'], 403));
        $this->assertFalse(app(AuthentikApiClient::class)->lecturaEfectiva());
    }

    private function funcionario(): User
    {
        return User::factory()->create([
            'name' => 'Juan Pérez Prueba',
            'email' => 'persona.prueba@example.test',
            'documento' => 'juan.perez.prueba',
            'activo' => false,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $miembros
     * @return array<string, mixed>
     */
    private function grupo(array $miembros): array
    {
        return [
            'pagination' => ['count' => 1, 'current' => 1, 'total_pages' => 1, 'next' => 0],
            'results' => [[
                'pk' => 'grupo-1',
                'name' => 'pqrsd-usuarios',
                'users' => array_map(fn (array $miembro): int => (int) $miembro['pk'], $miembros),
                'users_obj' => $miembros,
            ]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    private function paginaDirectorio(array $filas, int $paginas, int $actual): array
    {
        return [
            'pagination' => [
                'count' => 2,
                'current' => $actual,
                'total_pages' => $paginas,
                'next' => $actual < $paginas ? $actual + 1 : 0,
            ],
            'results' => $filas,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    private function paginaUsuarios(array $filas, int $paginas = 1, int $actual = 1): array
    {
        return [
            'pagination' => [
                'count' => count($filas),
                'current' => $actual,
                'total_pages' => $paginas,
                'next' => $actual < $paginas ? $actual + 1 : 0,
            ],
            'results' => $filas,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function juan(int $pk = 45, string $uid = 'uid-juan-real'): array
    {
        return [
            'pk' => $pk,
            'username' => 'juan.perez.prueba',
            'name' => 'Juan Pérez Prueba',
            'email' => 'persona.prueba@example.test',
            'is_active' => true,
            'uid' => $uid,
        ];
    }
}
