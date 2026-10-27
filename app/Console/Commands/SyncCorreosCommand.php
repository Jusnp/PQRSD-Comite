<?php

namespace App\Console\Commands;

use App\Jobs\ReintentarMensajesCorreoJob;
use App\Jobs\SyncCuentaCorreoJob;
use App\Models\CuentaCorreo;
use App\Services\Correo\EmailSyncService;
use App\Services\Ops\ScheduleRunRecorder;
use Illuminate\Console\Command;

class SyncCorreosCommand extends Command
{
    protected $signature = 'pqrsd:sync-correos {--sync : Ejecutar en primer plano sin cola} {--cuenta= : ID de cuenta específica}';

    protected $description = 'Sincroniza buzones PQRSD (mock/IMAP/Gmail/Graph según configuración)';

    public function handle(EmailSyncService $sync, ScheduleRunRecorder $recorder): int
    {
        return $recorder->run('pqrsd:sync-correos', 'correo_sync', function () use ($sync) {
            $query = CuentaCorreo::query()->where('activo', true);
            if ($this->option('cuenta')) {
                $query->whereKey($this->option('cuenta'));
            }

            $cuentas = $query->get();
            if ($cuentas->isEmpty()) {
                $this->warn('No hay cuentas activas.');

                return ['procesados' => 0, 'detalle' => 'Sin cuentas activas'];
            }

            $nuevos = 0;
            $errores = 0;

            foreach ($cuentas as $cuenta) {
                if ($this->option('sync')) {
                    $r = $sync->syncCuenta($cuenta);
                    $nuevos += $r['nuevos'];
                    $errores += $r['errores'];
                    $this->info(sprintf(
                        '%s → nuevos:%d dup:%d err:%d (%s)',
                        $cuenta->direccion,
                        $r['nuevos'],
                        $r['duplicados'],
                        $r['errores'],
                        $r['detalle']
                    ));
                } else {
                    SyncCuentaCorreoJob::dispatch($cuenta->id);
                    $this->info('Encolado: '.$cuenta->direccion);
                }
            }

            ReintentarMensajesCorreoJob::dispatch();

            return [
                'procesados' => $cuentas->count(),
                'detalle' => "cuentas={$cuentas->count()} nuevos={$nuevos} errores={$errores}",
            ];
        }) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
