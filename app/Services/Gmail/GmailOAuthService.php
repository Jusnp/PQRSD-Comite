<?php

namespace App\Services\Gmail;

use App\Mail\Gmail\GmailApiException;
use App\Models\CuentaCorreo;
use App\Models\GmailOauthToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth 2.0 para Gmail (preferido frente a Domain-Wide Delegation).
 * Scopes mínimos: gmail.readonly + gmail.send (ajustar según política SSI).
 */
class GmailOAuthService
{
    public function isConfigured(): bool
    {
        return filled(config('pqrsd_correo.gmail.oauth_client_id'))
            && filled(config('pqrsd_correo.gmail.oauth_client_secret'))
            && filled(config('pqrsd_correo.gmail.oauth_redirect_uri'));
    }

    /**
     * @return array{url:string,state:string}
     */
    public function authorizationUrl(CuentaCorreo $cuenta): array
    {
        if (! $this->isConfigured()) {
            throw GmailApiException::authFailed('OAuth no configurado (GMAIL_OAUTH_CLIENT_ID/SECRET/REDIRECT)');
        }

        $this->assertMailboxAllowed($cuenta);

        $state = Str::random(40);
        session([
            'gmail_oauth_state' => $state,
            'gmail_oauth_cuenta_id' => $cuenta->id,
        ]);

        $query = http_build_query([
            'client_id' => config('pqrsd_correo.gmail.oauth_client_id'),
            'redirect_uri' => config('pqrsd_correo.gmail.oauth_redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('pqrsd_correo.gmail.oauth_scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'login_hint' => $cuenta->direccion,
            'state' => $state,
        ]);

        return [
            'url' => 'https://accounts.google.com/o/oauth2/v2/auth?'.$query,
            'state' => $state,
        ];
    }

    public function handleCallback(string $code, string $state): CuentaCorreo
    {
        if ($state === '' || $state !== session('gmail_oauth_state')) {
            throw GmailApiException::authFailed('state OAuth inválido');
        }

        $cuentaId = session('gmail_oauth_cuenta_id');
        $cuenta = CuentaCorreo::findOrFail($cuentaId);
        $this->assertMailboxAllowed($cuenta);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('pqrsd_correo.gmail.oauth_client_id'),
            'client_secret' => config('pqrsd_correo.gmail.oauth_client_secret'),
            'redirect_uri' => config('pqrsd_correo.gmail.oauth_redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            Log::channel('correo_sync')->warning('OAuth token exchange falló', [
                'status' => $response->status(),
                'cuenta' => $cuenta->direccion,
            ]);
            throw GmailApiException::authFailed('Intercambio de código OAuth falló HTTP '.$response->status());
        }

        $json = $response->json() ?? [];
        if (empty($json['access_token'])) {
            throw GmailApiException::authFailed('Respuesta OAuth sin access_token');
        }

        if (isset($json['expires_in']) && ! isset($json['expires_at'])) {
            $json['expires_at'] = now('America/Bogota')->addSeconds((int) $json['expires_in'])->getTimestamp();
        }

        GmailOauthToken::storeFor(
            $cuenta,
            $json,
            implode(' ', config('pqrsd_correo.gmail.oauth_scopes'))
        );

        $cuenta->update([
            'tipo_integracion' => 'google_api',
            'estado_conexion' => 'ok',
            'ultimo_error' => null,
        ]);

        session()->forget(['gmail_oauth_state', 'gmail_oauth_cuenta_id']);

        Log::channel('correo_sync')->info('OAuth vinculado', [
            'cuenta' => $cuenta->direccion,
            // sin tokens
        ]);

        return $cuenta->fresh();
    }

    public function revoke(CuentaCorreo $cuenta): void
    {
        $row = GmailOauthToken::where('cuenta_correo_id', $cuenta->id)->first();
        if ($row) {
            try {
                $token = $row->payload()['access_token'] ?? $row->payload()['refresh_token'] ?? null;
                if ($token) {
                    Http::asForm()->post('https://oauth2.googleapis.com/revoke', [
                        'token' => $token,
                    ]);
                }
            } catch (\Throwable) {
                // Revocación remota best-effort; siempre limpiar local
            }
            $row->delete();
        }

        if ($cuenta->tipo_integracion === 'google_api') {
            $cuenta->update([
                'estado_conexion' => 'pendiente_credenciales',
                'ultimo_error' => 'OAuth revocado',
            ]);
        }

        Log::channel('correo_sync')->info('OAuth revocado', ['cuenta' => $cuenta->direccion]);
    }

    public function refreshIfNeeded(CuentaCorreo $cuenta): ?string
    {
        $row = GmailOauthToken::where('cuenta_correo_id', $cuenta->id)->first();
        if (! $row) {
            return null;
        }

        $payload = $row->payload();
        $expiresAt = $row->expires_at;
        if ($expiresAt && $expiresAt->greaterThan(now('America/Bogota')->addMinutes(2))) {
            return $payload['access_token'] ?? null;
        }

        if (empty($payload['refresh_token'])) {
            throw GmailApiException::authFailed('Token expirado sin refresh_token; reautorizar OAuth');
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('pqrsd_correo.gmail.oauth_client_id'),
            'client_secret' => config('pqrsd_correo.gmail.oauth_client_secret'),
            'refresh_token' => $payload['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            throw GmailApiException::authFailed('Refresh OAuth falló HTTP '.$response->status());
        }

        $json = $response->json() ?? [];
        $merged = array_merge($payload, $json);
        if (! isset($merged['refresh_token'])) {
            $merged['refresh_token'] = $payload['refresh_token'];
        }
        if (isset($merged['expires_in'])) {
            $merged['expires_at'] = now('America/Bogota')->addSeconds((int) $merged['expires_in'])->getTimestamp();
        }

        GmailOauthToken::storeFor($cuenta, $merged, $row->scopes);

        return $merged['access_token'] ?? null;
    }

    private function assertMailboxAllowed(CuentaCorreo $cuenta): void
    {
        $pilot = config('pqrsd_correo.gmail.pilot_mailbox');
        $allowed = config('pqrsd_correo.gmail.allowed_mailboxes', []);

        if (filled($pilot) && strtolower($cuenta->direccion) !== strtolower((string) $pilot)) {
            throw GmailApiException::authFailed('Solo la cuenta piloto está autorizada: '.$pilot);
        }

        if ($allowed !== [] && $allowed !== null) {
            $ok = collect($allowed)->map(fn ($e) => strtolower(trim((string) $e)))
                ->contains(strtolower($cuenta->direccion));
            if (! $ok) {
                throw GmailApiException::authFailed('Buzón fuera de lista blanca');
            }
        }

        if (in_array($cuenta->tipo_buzon, ['grupo'], true)) {
            throw GmailApiException::authFailed(
                'Los grupos de Google no se sincronizan por Gmail API directamente. Use buzón_canonico o reenvío.'
            );
        }
    }
}
