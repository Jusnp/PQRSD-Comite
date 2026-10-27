<?php

/**
 * Worker de un solo proceso para carreras de concurrencia MySQL.
 * Uso: php tools/concurrency_worker.php <accion> <json_payload>
 *
 * Acciones: classify | respond | close | actuacion | hold_then_classify
 * Escribe una línea JSON en stdout: {ok, error?, nivel_riesgo?, ...}
 */

declare(strict_types=1);

use App\Models\ActuacionPqrsd;
use App\Models\Pqrsd;
use App\Models\User;
use App\Services\IdempotenciaRadicacionPublica;
use App\Services\PqrsdService;
use App\Services\VencimientoService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'mysql_concurrency',
    'database.connections.mysql_concurrency.database' => env('DB_CONCURRENCY_DATABASE', 'pqrsd_concurrency_test'),
]);
DB::purge('mysql_concurrency');
DB::reconnect('mysql_concurrency');
DB::setDefaultConnection('mysql_concurrency');

$accion = $argv[1] ?? '';
$payloadRaw = $argv[2] ?? '';
if ($payloadRaw === '' || $payloadRaw === '-') {
    $payloadRaw = stream_get_contents(STDIN) ?: '{}';
}
try {
    $payload = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, 'JSON inválido: '.$e->getMessage().PHP_EOL);
    echo json_encode(['ok' => false, 'error' => 'json_invalido', 'raw' => $payloadRaw], JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(2);
}

function emit(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(($data['ok'] ?? false) ? 0 : 2);
}

try {
    $casoId = (int) ($payload['caso_id'] ?? 0);
    $userId = (int) ($payload['user_id'] ?? 0);
    $version = $payload['expediente_version'] ?? null;
    $delayMs = (int) ($payload['delay_ms'] ?? 0);

    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }

    $usuario = $userId > 0 ? User::query()->findOrFail($userId) : null;
    $servicio = app(VencimientoService::class);

    match ($accion) {
        'classify' => (function () use ($servicio, $casoId, $usuario, $version, $payload) {
            $caso = Pqrsd::query()->findOrFail($casoId);
            $servicio->aplicarClasificacion(
                $caso,
                (string) $payload['nivel_riesgo'],
                $usuario,
                (string) ($payload['justificacion'] ?? 'Clasificación worker concurrencia MySQL'),
                false,
                $version,
                versionObligatoria: true
            );
            $fresh = $caso->fresh();
            emit([
                'ok' => true,
                'accion' => 'classify',
                'nivel_riesgo' => $fresh->nivel_riesgo,
                'estado_operativo' => $fresh->estado_operativo,
                'version' => $fresh->versionToken(),
            ]);
        })(),
        'respond' => (function () use ($servicio, $casoId, $usuario, $version, $payload) {
            DB::connection('mysql_concurrency')->transaction(function () use ($servicio, $casoId, $usuario, $version, $payload) {
                $caso = Pqrsd::bloquearParaEscritura($casoId, $version, true);
                $fecha = Carbon::parse($payload['fecha_respuesta'] ?? '2026-10-11 11:00:00', 'America/Bogota');
                $servicio->registrarRespuesta($caso, $usuario, $fecha, null, yaBloqueadoEnTransaccion: true);
                $caso->refresh();
                $caso->update([
                    'respuesta_estado' => $payload['respuesta_estado'] ?? 'elaborada',
                    'respuesta_consulta_texto' => (string) $payload['detalle_respuesta'],
                ]);
            });
            $fresh = Pqrsd::query()->findOrFail($casoId);
            emit([
                'ok' => true,
                'accion' => 'respond',
                'respuesta_estado' => $fresh->respuesta_estado,
                'texto' => $fresh->respuesta_consulta_texto,
                'version' => $fresh->versionToken(),
            ]);
        })(),
        'close' => (function () use ($servicio, $casoId, $usuario, $version, $payload) {
            $caso = Pqrsd::query()->findOrFail($casoId);
            $servicio->cerrar(
                $caso,
                $usuario,
                (string) ($payload['motivo'] ?? 'Cierre worker concurrencia MySQL con evidencia.'),
                $version,
                versionObligatoria: true
            );
            $fresh = $caso->fresh();
            emit([
                'ok' => true,
                'accion' => 'close',
                'estado_operativo' => $fresh->estado_operativo,
                'cierres' => ActuacionPqrsd::query()->where('pqrsd_id', $casoId)->where('tipo', 'cierre')->count(),
            ]);
        })(),
        'actuacion' => (function () use ($casoId, $usuario, $payload) {
            DB::connection('mysql_concurrency')->transaction(function () use ($casoId, $usuario, $payload) {
                Pqrsd::bloquearParaEscritura($casoId, null, false);
                ActuacionPqrsd::create([
                    'pqrsd_id' => $casoId,
                    'usuario_id' => $usuario->id,
                    'tipo' => 'actuacion_operativa',
                    'titulo' => (string) $payload['titulo'],
                    'detalle' => (string) $payload['detalle'],
                    'es_interna' => true,
                ]);
            });
            emit([
                'ok' => true,
                'accion' => 'actuacion',
                'titulo' => $payload['titulo'],
                'count' => ActuacionPqrsd::query()->where('pqrsd_id', $casoId)->where('titulo', $payload['titulo'])->count(),
            ]);
        })(),
        'rollback_probe' => (function () use ($casoId) {
            $antes = ActuacionPqrsd::query()->where('pqrsd_id', $casoId)->count();
            try {
                DB::connection('mysql_concurrency')->transaction(function () use ($casoId) {
                    Pqrsd::bloquearParaEscritura($casoId, null, false);
                    ActuacionPqrsd::create([
                        'pqrsd_id' => $casoId,
                        'usuario_id' => User::query()->value('id'),
                        'tipo' => 'actuacion_operativa',
                        'titulo' => 'NO_DEBE_PERSISTIR_ROLLBACK',
                        'detalle' => 'Esta actuación debe revertirse por excepción forzada.',
                        'es_interna' => true,
                    ]);
                    throw new RuntimeException('rollback_forzado_concurrencia');
                });
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== 'rollback_forzado_concurrencia') {
                    throw $e;
                }
            }
            $despues = ActuacionPqrsd::query()->where('pqrsd_id', $casoId)->count();
            $fantasma = ActuacionPqrsd::query()
                ->where('pqrsd_id', $casoId)
                ->where('titulo', 'NO_DEBE_PERSISTIR_ROLLBACK')
                ->exists();
            emit([
                'ok' => ! $fantasma && $antes === $despues,
                'accion' => 'rollback_probe',
                'antes' => $antes,
                'despues' => $despues,
                'fantasma' => $fantasma,
            ]);
        })(),
        'radicar_publico' => (function () use ($payload, $usuario) {
            $resultado = app(IdempotenciaRadicacionPublica::class)->resolver(
                (string) $payload['clave'],
                (string) $payload['huella'],
                (string) $payload['token'],
                function () use ($payload, $usuario) {
                    return app(PqrsdService::class)->radicarManual([
                        'canal_ingreso_id' => (int) $payload['canal_id'],
                        'tipo_pqrsd_id' => (int) $payload['tipo_id'],
                        'nivel_riesgo' => 'pendiente',
                        'es_anonimo' => false,
                        'solicitante_nombre' => 'Ciudadano Prueba Concurrencia',
                        'solicitante_documento' => '900000001',
                        'descripcion' => 'PRUEBA FICTICIA de concurrencia. No corresponde a un paciente real.',
                    ], $usuario);
                }
            );

            emit([
                'ok' => true,
                'accion' => 'radicar_publico',
                'repetida' => $resultado['repetida'],
                'radicado' => $resultado['caso']->radicado,
                'pqrsd_id' => $resultado['caso']->id,
                'token_hash' => hash('sha256', $resultado['token']),
            ]);
        })(),
        'cache_lock' => (function () use ($payload) {
            $bloqueo = Cache::lock((string) $payload['nombre'], (int) ($payload['segundos'] ?? 15));
            $obtuvo = $bloqueo->get();
            if ($obtuvo && (int) ($payload['mantener_ms'] ?? 0) > 0) {
                usleep(((int) $payload['mantener_ms']) * 1000);
                $bloqueo->release();
            } elseif ($obtuvo) {
                $bloqueo->release();
            }

            emit([
                'ok' => true,
                'accion' => 'cache_lock',
                'obtuvo' => $obtuvo,
                'almacen' => config('cache.default'),
            ]);
        })(),
        default => emit(['ok' => false, 'error' => 'accion_desconocida: '.$accion]),
    };
} catch (Throwable $e) {
    emit([
        'ok' => false,
        'accion' => $accion,
        'error' => $e->getMessage(),
        'class' => $e::class,
    ]);
}
