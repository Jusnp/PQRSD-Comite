<?php

namespace App\Console\Commands;

use App\Models\CuentaCorreo;
use App\Models\GmailOauthToken;
use App\Services\Gmail\GmailOAuthService;
use Illuminate\Console\Command;

/**
 * Reporta si el piloto Gmail real puede ejecutarse. No inventa éxito.
 */
class GmailPilotStatusCommand extends Command
{
    protected $signature = 'pqrsd:gmail-pilot-status';

    protected $description = 'Estado del piloto Gmail (OAuth/credenciales reales)';

    public function handle(GmailOAuthService $oauth): int
    {
        $pilot = config('pqrsd_correo.gmail.pilot_mailbox');
        $rows = [
            ['OAuth client configurado', $oauth->isConfigured() ? 'SÍ' : 'NO'],
            ['GMAIL_PILOT_MAILBOX', $pilot ?: 'NO DEFINIDO'],
            ['API REST driver', 'GoogleGmailMailProvider (gmail.googleapis.com)'],
            ['IMAP en google_api', 'NO — solo ImapMailProvider para tipo imap'],
        ];

        if ($pilot) {
            $cuenta = CuentaCorreo::whereRaw('LOWER(direccion) = ?', [strtolower($pilot)])->first();
            $rows[] = ['Cuenta en BD', $cuenta ? 'SÍ #'.$cuenta->id : 'NO'];
            $rows[] = ['tipo_buzon', $cuenta?->tipo_buzon ?? '—'];
            $rows[] = ['tipo_integracion', $cuenta?->tipo_integracion ?? '—'];
            $rows[] = ['OAuth token cifrado', $cuenta && GmailOauthToken::where('cuenta_correo_id', $cuenta->id)->exists() ? 'SÍ' : 'NO'];
            $rows[] = ['credential_ref env', $cuenta && filled($cuenta->credential_ref) && filled(env($cuenta->credential_ref)) ? 'SÍ' : 'NO'];
        }

        $this->table(['Ítem', 'Valor'], $rows);

        $ready = $oauth->isConfigured()
            && filled($pilot)
            && CuentaCorreo::whereRaw('LOWER(direccion) = ?', [strtolower((string) $pilot)])->exists()
            && (
                GmailOauthToken::whereHas('cuenta', fn ($q) => $q->whereRaw('LOWER(direccion) = ?', [strtolower((string) $pilot)]))->exists()
                || (function () use ($pilot) {
                    $c = CuentaCorreo::whereRaw('LOWER(direccion) = ?', [strtolower((string) $pilot)])->first();

                    return $c && filled($c->credential_ref) && filled(env($c->credential_ref));
                })()
            );

        if ($ready) {
            $this->info('Estado piloto: PREPARADO PARA PRUEBA REAL (ejecutar sync autorizado).');

            return self::SUCCESS;
        }

        $this->warn('Estado piloto: BLOQUEADO — faltan OAuth/credenciales/cuenta piloto autorizada.');
        $this->line('Acción humana: Admin Workspace + Google Cloud deben entregar Client ID/Secret y autorizar un buzón de prueba.');

        return self::FAILURE;
    }
}
