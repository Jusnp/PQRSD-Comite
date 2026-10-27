<?php

namespace App\Services\Correo;

use App\Mail\MailProviderManager;
use App\Models\CuentaCorreo;
use App\Models\SincronizacionCorreo;
use Illuminate\Support\Facades\Log;

class EmailSyncService
{
    public function __construct(
        private readonly MailProviderManager $providers,
        private readonly EmailIngestionService $ingestion,
    ) {}

    /**
     * @return array{nuevos:int,duplicados:int,errores:int,detalle:string}
     */
    public function syncCuenta(CuentaCorreo $cuenta): array
    {
        $inicio = now('America/Bogota');
        $nuevos = 0;
        $duplicados = 0;
        $errores = 0;
        $detalle = [];

        $sync = SincronizacionCorreo::create([
            'cuenta_correo_id' => $cuenta->id,
            'resultado' => 'ok',
            'iniciada_en' => $inicio,
        ]);

        try {
            $provider = $this->providers->for($cuenta);
            $mensajes = $provider->fetchNew($cuenta, $cuenta->cursor_sincronizacion);

            $maxUid = $cuenta->cursor_sincronizacion;

            foreach ($mensajes as $mensaje) {
                $resultado = $this->ingestion->ingest($cuenta, $mensaje);

                if ($resultado['error']) {
                    $errores++;
                    $detalle[] = $resultado['error'];
                    continue;
                }

                if ($resultado['duplicado']) {
                    $duplicados++;
                } else {
                    $nuevos++;
                }

                $uid = $mensaje->metadatos['uid'] ?? null;
                if ($uid !== null && ($maxUid === null || (string) $uid > (string) $maxUid)) {
                    $maxUid = (string) $uid;
                }
            }

            // No avanzar cursor si quedan mensajes con error (sincronización incompleta).
            $incomplete = $errores > 0
                || (method_exists($provider, 'syncIncomplete') && $provider->syncIncomplete());

            $nextCursor = $cuenta->cursor_sincronizacion;
            if (! $incomplete) {
                if (method_exists($provider, 'nextSyncCursor') && $provider->nextSyncCursor()) {
                    $nextCursor = $provider->nextSyncCursor();
                } elseif ($maxUid !== null) {
                    $nextCursor = $maxUid;
                }
            }

            $cuenta->update([
                'estado_conexion' => $incomplete && $nuevos === 0 ? 'error' : ($incomplete ? 'parcial' : 'ok'),
                'ultima_sincronizacion' => now('America/Bogota'),
                'ultimo_error' => $errores > 0 ? implode('; ', array_slice($detalle, 0, 3)) : null,
                'intentos_fallidos' => $errores > 0 ? $cuenta->intentos_fallidos + 1 : 0,
                'cursor_sincronizacion' => $nextCursor,
            ]);

            $resultadoFinal = $incomplete ? ($nuevos > 0 ? 'parcial' : 'error') : 'ok';

            $sync->update([
                'resultado' => $resultadoFinal,
                'mensajes_nuevos' => $nuevos,
                'mensajes_duplicados' => $duplicados,
                'mensajes_error' => $errores,
                'detalle' => $detalle
                    ? implode("\n", $detalle)
                    : ($incomplete ? 'Sincronización incompleta' : 'Sincronización completada'),
                'finalizada_en' => now('America/Bogota'),
            ]);

            Log::channel('correo_sync')->info('Sync cuenta', [
                'cuenta' => $cuenta->direccion,
                'nuevos' => $nuevos,
                'duplicados' => $duplicados,
                'errores' => $errores,
                'resultado' => $resultadoFinal,
                'cursor_avanzado' => ! $incomplete,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Error sincronizando cuenta correo', [
                'cuenta' => $cuenta->direccion,
                'error' => $e->getMessage(),
            ]);

            $cuenta->update([
                'estado_conexion' => 'error',
                'ultima_sincronizacion' => now('America/Bogota'),
                'ultimo_error' => $e->getMessage(),
                'intentos_fallidos' => $cuenta->intentos_fallidos + 1,
            ]);

            $sync->update([
                'resultado' => 'error',
                'mensajes_error' => 1,
                'detalle' => $e->getMessage(),
                'finalizada_en' => now('America/Bogota'),
            ]);

            $errores++;
            $detalle[] = $e->getMessage();
        }

        return [
            'nuevos' => $nuevos,
            'duplicados' => $duplicados,
            'errores' => $errores,
            'detalle' => implode('; ', $detalle) ?: 'OK',
        ];
    }

    /**
     * @return array<int, array{cuenta:string,resultado:array}>
     */
    public function syncActivas(): array
    {
        $resultados = [];

        CuentaCorreo::query()
            ->where('activo', true)
            ->orderByDesc('es_buzon_central')
            ->each(function (CuentaCorreo $cuenta) use (&$resultados) {
                $resultados[] = [
                    'cuenta' => $cuenta->direccion,
                    'resultado' => $this->syncCuenta($cuenta),
                ];
            });

        return $resultados;
    }
}
