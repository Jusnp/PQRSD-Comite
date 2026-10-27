<?php

namespace Tests\Feature;

use App\Models\ActuacionPqrsd;
use App\Models\Aseguradora;
use App\Models\CanalIngreso;
use App\Models\Pqrsd;
use App\Models\Sede;
use App\Models\TipoPqrsd;
use App\Models\User;
use App\Services\IdempotenciaRadicacionPublica;
use App\Services\PqrsdService;
use App\Services\VencimientoService;
use Carbon\Carbon;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Concurrencia REAL contra MySQL/MariaDB aislado (pqrsd_concurrency_test).
 *
 * Ejecutar solo con:
 *   php tools/setup_mysql_concurrency.php
 *   php artisan test --configuration=phpunit.mysql-concurrency.xml
 *
 * Usa dos procesos PHP independientes (tools/concurrency_worker.php).
 * No usa RefreshDatabase/transacciones: los workers hijos deben ver datos COMMIT.
 */
class MysqlConcurrenciaRealTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_MYSQL_CONCURRENCY')) {
            $this->markTestSkipped('Activar con phpunit.mysql-concurrency.xml (MySQL aislado).');
        }

        if (config('database.default') !== 'mysql_concurrency') {
            $this->markTestSkipped('La conexión por defecto debe ser mysql_concurrency.');
        }

        try {
            DB::connection('mysql_concurrency')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL/MariaDB no disponible: '.$e->getMessage());
        }

        // migrate:fresh COMMIT en la base aislada (nunca pqrsd_menteplena).
        Artisan::call('migrate:fresh', [
            '--database' => 'mysql_concurrency',
            '--force' => true,
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogosSeeder::class);
        $this->seed(UsersSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'America/Bogota'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_mysql_bloqueo_de_cache_es_compartido_entre_procesos(): void
    {
        $nombre = 'publico-idem-lock:prueba-'.Str::uuid();

        [$primero, $segundo] = $this->correrEnParalelo([
            ['cache_lock', [
                'nombre' => $nombre,
                'segundos' => 15,
                'mantener_ms' => 2500,
                'user_id' => 0,
            ]],
            ['cache_lock', [
                'nombre' => $nombre,
                'segundos' => 15,
                'mantener_ms' => 0,
                'delay_ms' => 200,
                'user_id' => 0,
            ]],
        ]);

        $this->assertTrue($primero['ok'] ?? false, json_encode($primero));
        $this->assertTrue($segundo['ok'] ?? false, json_encode($segundo));
        $this->assertSame('database', $primero['almacen'] ?? null);
        $this->assertTrue(($primero['obtuvo'] ?? false) xor ($segundo['obtuvo'] ?? false), json_encode([$primero, $segundo]));
    }

    public function test_mysql_dos_radicaciones_con_la_misma_clave_dejan_un_expediente(): void
    {
        [$admin] = $this->operadores();
        $clave = (string) Str::uuid();
        $huella = app(IdempotenciaRadicacionPublica::class)->huella([
            'solicitante' => 'Ciudadano Prueba Concurrencia',
            'descripcion' => 'misma solicitud',
        ]);
        $payload = [
            'user_id' => $admin->id,
            'clave' => $clave,
            'huella' => $huella,
            'canal_id' => CanalIngreso::where('codigo', 'WEB')->value('id'),
            'tipo_id' => TipoPqrsd::where('codigo', 'PETICION')->value('id'),
        ];

        [$r1, $r2] = $this->correrEnParalelo([
            ['radicar_publico', $payload + ['token' => Str::random(40), 'delay_ms' => 0]],
            ['radicar_publico', $payload + ['token' => Str::random(40), 'delay_ms' => 30]],
        ]);

        $this->assertTrue($r1['ok'] ?? false, json_encode($r1));
        $this->assertTrue($r2['ok'] ?? false, json_encode($r2));
        $this->assertSame($r1['radicado'], $r2['radicado']);
        $this->assertSame($r1['pqrsd_id'], $r2['pqrsd_id']);
        $this->assertSame($r1['token_hash'], $r2['token_hash']);
        $this->assertSame(1, Pqrsd::query()->count());
        $this->assertSame(1, DB::table('pqrsd_idempotencia_publica')->count());
        $this->assertCount(1, array_filter([$r1, $r2], fn ($r) => ($r['repetida'] ?? null) === false));
    }

    public function test_mysql_la_misma_clave_con_otro_contenido_no_crea_otro_expediente(): void
    {
        [$admin] = $this->operadores();
        $clave = (string) Str::uuid();
        $servicio = app(IdempotenciaRadicacionPublica::class);
        $base = [
            'user_id' => $admin->id,
            'clave' => $clave,
            'canal_id' => CanalIngreso::where('codigo', 'WEB')->value('id'),
            'tipo_id' => TipoPqrsd::where('codigo', 'PETICION')->value('id'),
        ];

        $primero = $this->correrWorker('radicar_publico', $base + [
            'huella' => $servicio->huella(['solicitante' => 'Persona A']),
            'token' => Str::random(40),
        ]);
        $segundo = $this->correrWorker('radicar_publico', $base + [
            'huella' => $servicio->huella(['solicitante' => 'Persona B']),
            'token' => Str::random(40),
        ]);

        $this->assertTrue($primero['ok'] ?? false, json_encode($primero));
        $this->assertFalse($segundo['ok'] ?? true, json_encode($segundo));
        $this->assertStringNotContainsString('Persona A', (string) ($segundo['error'] ?? ''));
        $this->assertSame(1, Pqrsd::query()->count());
    }

    public function test_mysql_dos_procesos_clasificacion_obsoleta_rechazada(): void
    {
        [$admin, $aux1, $aux2] = $this->operadores();
        $caso = $this->crearCasoPendiente($admin);
        $token = $caso->fresh()->versionToken();

        [$r1, $r2] = $this->correrEnParalelo([
            ['classify', [
                'caso_id' => $caso->id,
                'user_id' => $aux1->id,
                'expediente_version' => $token,
                'nivel_riesgo' => 'ordinario',
                'justificacion' => 'Auxiliar 1 clasifica primero en carrera MySQL real.',
                'delay_ms' => 0,
            ]],
            ['classify', [
                'caso_id' => $caso->id,
                'user_id' => $aux2->id,
                'expediente_version' => $token,
                'nivel_riesgo' => 'simple',
                'justificacion' => 'Auxiliar 2 intenta versión obsoleta en carrera MySQL real.',
                'delay_ms' => 50,
            ]],
        ]);

        $ok = array_values(array_filter([$r1, $r2], fn ($r) => ($r['ok'] ?? false) === true));
        $fail = array_values(array_filter([$r1, $r2], fn ($r) => ($r['ok'] ?? false) === false));

        $this->assertCount(1, $ok, 'Solo una clasificación concurrente debe ganar. '.json_encode([$r1, $r2]));
        $this->assertCount(1, $fail, 'La segunda debe fallar por versión/lock. '.json_encode([$r1, $r2]));
        $this->assertStringContainsString('actualizó', (string) ($fail[0]['error'] ?? ''));

        $caso->refresh();
        $this->assertSame('ordinario', $caso->nivel_riesgo);
        $this->assertNotSame('simple', $caso->nivel_riesgo);
    }

    public function test_mysql_dos_cierres_simultaneos_no_duplican(): void
    {
        [$admin, $aux1, $aux2] = $this->operadores();
        $caso = $this->crearCasoSimple($admin);
        app(VencimientoService::class)->registrarRespuesta(
            $caso->fresh(),
            $aux1,
            Carbon::parse('2026-10-11 10:00:00', 'America/Bogota')
        );
        $caso->refresh();
        $token = $caso->versionToken();
        $limite = $caso->fecha_limite?->toDateTimeString();

        [$r1, $r2] = $this->correrEnParalelo([
            ['close', [
                'caso_id' => $caso->id,
                'user_id' => $aux1->id,
                'expediente_version' => $token,
                'motivo' => 'Cierre concurrente auxiliar 1 con evidencia de respuesta.',
                'delay_ms' => 0,
            ]],
            ['close', [
                'caso_id' => $caso->id,
                'user_id' => $aux2->id,
                'expediente_version' => $token,
                'motivo' => 'Cierre concurrente auxiliar 2 con evidencia de respuesta.',
                'delay_ms' => 30,
            ]],
        ]);

        $ok = array_values(array_filter([$r1, $r2], fn ($r) => ($r['ok'] ?? false) === true));
        $fail = array_values(array_filter([$r1, $r2], fn ($r) => ($r['ok'] ?? false) === false));

        $this->assertCount(1, $ok, json_encode([$r1, $r2]));
        $this->assertCount(1, $fail, json_encode([$r1, $r2]));

        $caso->refresh();
        $this->assertSame('cerrado', $caso->estado_operativo);
        $this->assertSame(1, ActuacionPqrsd::where('pqrsd_id', $caso->id)->where('tipo', 'cierre')->count());
        $this->assertSame($limite, $caso->fecha_limite?->toDateTimeString());
    }

    public function test_mysql_respuesta_obsoleta_no_sobrescribe_en_paralelo(): void
    {
        [$admin, $aux1, $aux2] = $this->operadores();
        $caso = $this->crearCasoSimple($admin);
        $token = $caso->fresh()->versionToken();

        [$r1, $r2] = $this->correrEnParalelo([
            ['respond', [
                'caso_id' => $caso->id,
                'user_id' => $aux1->id,
                'expediente_version' => $token,
                'detalle_respuesta' => 'Texto oficial vigente del auxiliar 1 en MySQL real.',
                'fecha_respuesta' => '2026-10-11 11:00:00',
                'delay_ms' => 0,
            ]],
            ['respond', [
                'caso_id' => $caso->id,
                'user_id' => $aux2->id,
                'expediente_version' => $token,
                'detalle_respuesta' => 'Texto obsoleto del auxiliar 2 que no debe quedar.',
                'fecha_respuesta' => '2026-10-11 12:00:00',
                'delay_ms' => 40,
            ]],
        ]);

        $ok = array_values(array_filter([$r1, $r2], fn ($r) => ($r['ok'] ?? false) === true));
        $fail = array_values(array_filter([$r1, $r2], fn ($r) => ($r['ok'] ?? false) === false));
        $this->assertCount(1, $ok, json_encode([$r1, $r2]));
        $this->assertCount(1, $fail, json_encode([$r1, $r2]));

        $caso->refresh();
        $this->assertStringContainsString('auxiliar 1', (string) $caso->respuesta_consulta_texto);
        $this->assertStringNotContainsString('auxiliar 2', (string) $caso->respuesta_consulta_texto);
    }

    public function test_mysql_actuaciones_paralelas_se_conservan(): void
    {
        [$admin, $aux1, $aux2] = $this->operadores();
        $caso = $this->crearCasoSimple($admin);

        [$r1, $r2] = $this->correrEnParalelo([
            ['actuacion', [
                'caso_id' => $caso->id,
                'user_id' => $aux1->id,
                'titulo' => 'Actuacion MySQL aux1',
                'detalle' => 'Nota independiente auxiliar 1 en carrera real.',
            ]],
            ['actuacion', [
                'caso_id' => $caso->id,
                'user_id' => $aux2->id,
                'titulo' => 'Actuacion MySQL aux2',
                'detalle' => 'Nota independiente auxiliar 2 en carrera real.',
            ]],
        ]);

        $this->assertTrue($r1['ok'] ?? false, json_encode($r1));
        $this->assertTrue($r2['ok'] ?? false, json_encode($r2));
        $this->assertDatabaseHas('actuaciones_pqrsd', [
            'pqrsd_id' => $caso->id,
            'usuario_id' => $aux1->id,
            'titulo' => 'Actuacion MySQL aux1',
        ]);
        $this->assertDatabaseHas('actuaciones_pqrsd', [
            'pqrsd_id' => $caso->id,
            'usuario_id' => $aux2->id,
            'titulo' => 'Actuacion MySQL aux2',
        ]);
    }

    public function test_mysql_cerrado_rechaza_operaciones_y_rollback_no_deja_parciales(): void
    {
        [$admin, $aux1, $aux2] = $this->operadores();
        $caso = $this->crearCasoSimple($admin);
        app(VencimientoService::class)->registrarRespuesta(
            $caso->fresh(),
            $aux1,
            Carbon::parse('2026-10-11 10:00:00', 'America/Bogota')
        );
        app(VencimientoService::class)->cerrar(
            $caso->fresh(),
            $admin,
            'Cierre para probar rechazo MySQL tras cierre.'
        );
        $cerrado = $caso->fresh();
        $this->assertSame('cerrado', $cerrado->estado_operativo);

        $classify = $this->correrWorker('classify', [
            'caso_id' => $cerrado->id,
            'user_id' => $aux2->id,
            'expediente_version' => $cerrado->versionToken(),
            'nivel_riesgo' => 'ordinario',
            'justificacion' => 'No debe clasificar un expediente cerrado en MySQL.',
        ]);
        $this->assertFalse($classify['ok'] ?? true, json_encode($classify));
        $this->assertStringContainsString('cerrado', strtolower((string) ($classify['error'] ?? '')));

        $rollback = $this->correrWorker('rollback_probe', [
            'caso_id' => $this->crearCasoSimple($admin)->id,
            'user_id' => $aux1->id,
        ]);
        $this->assertTrue($rollback['ok'] ?? false, json_encode($rollback));
        $this->assertFalse($rollback['fantasma'] ?? true);
    }

    public function test_mysql_http_token_omitido_manipulado_y_vigente(): void
    {
        [$admin, $aux1] = $this->operadores();
        $caso = $this->crearCasoPendiente($admin);

        $this->actingAs($aux1)->from(route('pqrsd.show', $caso))->post(route('pqrsd.clasificar', $caso), [
            'nivel_riesgo' => 'ordinario',
            'justificacion' => 'Sin token en MySQL real.',
        ])->assertSessionHasErrors('expediente_version');

        $this->actingAs($aux1)->from(route('pqrsd.show', $caso))->post(route('pqrsd.clasificar', $caso), [
            'nivel_riesgo' => 'ordinario',
            'justificacion' => 'Token manipulado en MySQL real.',
            'expediente_version' => str_repeat('b', 64),
        ])->assertSessionHasErrors('expediente');

        $this->actingAs($aux1)->post(route('pqrsd.clasificar', $caso), $this->conVersionExpediente($caso, [
            'nivel_riesgo' => 'ordinario',
            'justificacion' => 'Token vigente correcto en MySQL real.',
        ]))->assertRedirect();

        $this->assertSame('ordinario', $caso->fresh()->nivel_riesgo);
        $this->assertSame(95.0, (float) config('pqrsd_sla.meta_oportunidad_pct'));
    }

    /**
     * @param  list<array{0:string,1:array<string,mixed>}>  $jobs
     * @return list<array<string,mixed>>
     */
    private function correrEnParalelo(array $jobs): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $processes = [];
        $php = PHP_BINARY;
        $worker = base_path('tools/concurrency_worker.php');

        foreach ($jobs as [$accion, $payload]) {
            // Payload por STDIN para evitar rotura de comillas en Windows/cmd.
            $cmd = escapeshellarg($php).' '.escapeshellarg($worker).' '.escapeshellarg($accion).' -';
            // env=null: hereda variables del proceso PHPUnit (incl. DB_* de phpunit.mysql-concurrency.xml).
            // Un array parcial en Windows rompe PATH/SystemRoot y provoca SQLSTATE 2002.
            $proc = proc_open($cmd, $descriptors, $pipes, base_path(), null);
            $this->assertIsResource($proc, 'No se pudo iniciar worker: '.$cmd);
            fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $processes[] = ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2]];
        }

        $results = [];
        foreach ($processes as $p) {
            $stdout = stream_get_contents($p['out']);
            $stderr = stream_get_contents($p['err']);
            fclose($p['out']);
            fclose($p['err']);
            $code = proc_close($p['proc']);
            $line = trim((string) $stdout);
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                $decoded = [
                    'ok' => false,
                    'error' => 'salida_invalida',
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'code' => $code,
                ];
            }
            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function correrWorker(string $accion, array $payload): array
    {
        return $this->correrEnParalelo([[$accion, $payload]])[0];
    }

    /** @return array{0:User,1:User,2:User} */
    private function operadores(): array
    {
        return [
            User::where('email', 'admin@menteplena.test')->firstOrFail(),
            User::where('email', 'auxiliar1@menteplena.test')->firstOrFail(),
            User::where('email', 'auxiliar2@menteplena.test')->firstOrFail(),
        ];
    }

    private function crearCasoSimple(User $actor): Pqrsd
    {
        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-10 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'simple',
            'descripcion' => 'Caso ficticio concurrencia MySQL real — no producción',
            'solicitante_nombre' => 'Ciudadano MySQL Concurrencia',
            'sede_id' => Sede::where('activo', true)->value('id'),
            'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
        ], $actor)->fresh();
    }

    private function crearCasoPendiente(User $actor): Pqrsd
    {
        return app(PqrsdService::class)->radicarManual([
            'fecha_recepcion' => Carbon::parse('2026-10-10 09:00:00', 'America/Bogota'),
            'canal_ingreso_id' => CanalIngreso::where('codigo', 'WEB')->value('id')
                ?? CanalIngreso::where('codigo', 'MANUAL')->value('id'),
            'tipo_pqrsd_id' => TipoPqrsd::where('codigo', 'QUEJA')->value('id'),
            'nivel_riesgo' => 'pendiente',
            'descripcion' => 'Caso pendiente ficticio para carrera MySQL de clasificación',
            'solicitante_nombre' => 'Ciudadano Pendiente MySQL',
            'sede_id' => Sede::where('activo', true)->value('id'),
            'aseguradora_id' => Aseguradora::where('activo', true)->value('id'),
        ], $actor)->fresh();
    }
}
