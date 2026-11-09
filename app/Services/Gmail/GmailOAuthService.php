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
 * Alcance usado: gmail.modify. Cubre lectura, inserción en bandeja y envío.
 * No pide gmail.send aparte: el proyecto de Google solo autorizó gmail.modify.
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
        $esperado = (string) session('gmail_oauth_state');
        if ($code === '' || $state === '' || $esperado === '' || ! hash_equals($esperado, $state)) {
            throw GmailApiException::authFailed('state OAuth inválido');
        }

        $cuentaId = session('gmail_oauth_cuenta_id');
        session()->forget(['gmail_oauth_state', 'gmail_oauth_cuenta_id']);
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
        if (empty($json['refresh_token'])) {
            throw GmailApiException::authFailed('Google no entregó refresh_token. Hay que repetir el consentimiento.');
        }

        $this->assertScopes((string) ($json['scope'] ?? ''));
        $perfil = $this->perfilAutorizado($cuenta, (string) $json['access_token']);
        $alcances = filled($json['scope'] ?? null)
            ? (string) $json['scope']
            : implode(' ', config('pqrsd_correo.gmail.oauth_scopes'));

        GmailOauthToken::storeFor($cuenta, $json, $alcances);

        $cuenta->update([
            'tipo_integracion' => 'google_api',
            'tipo_buzon' => in_array($cuenta->tipo_buzon, [null, '', 'desconocido', 'otro'], true)
                ? 'individual'
                : $cuenta->tipo_buzon,
            'verificacion_buzon' => $perfil === strtolower($cuenta->direccion) ? 'usuario' : 'alias',
            'buzon_canonico' => $perfil === strtolower($cuenta->direccion)
                ? ($cuenta->buzon_canonico ?: $cuenta->direccion)
                : ($cuenta->buzon_canonico ?: $perfil),
            'estado_conexion' => 'ok',
            'estado_autorizacion' => 'autorizado',
            'sincronizar_desde' => $cuenta->sincronizar_desde ?? now('America/Bogota'),
            'ultimo_error' => null,
        ]);

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

        if ($allowed === [] || $allowed === null) {
            throw GmailApiException::authFailed('GMAIL_ALLOWED_MAILBOXES está vacío');
        }

        $ok = collect($allowed)->map(fn ($e) => strtolower(trim((string) $e)))
            ->contains(strtolower($cuenta->direccion));
        if (! $ok) {
            throw GmailApiException::authFailed('Buzón fuera de lista blanca');
        }

        if (in_array($cuenta->tipo_buzon, ['grupo'], true)) {
            throw GmailApiException::authFailed(
                'Los grupos de Google no se sincronizan por Gmail API directamente. Use buzón_canonico o reenvío.'
            );
        }
    }

    private function assertScopes(string $otorgado): void
    {
        $esperados = collect(config('pqrsd_correo.gmail.oauth_scopes', []))
            ->map(fn ($alcance) => strtolower(trim((string) $alcance)))
            ->filter()
            ->values();
        $recibidos = collect(preg_split('/\s+/', trim($otorgado)) ?: [])
            ->map(fn ($alcance) => strtolower(trim((string) $alcance)))
            ->filter()
            ->values();

        $modificar = 'https://www.googleapis.com/auth/gmail.modify';
        if ($recibidos->isEmpty()
            || ! $recibidos->contains($modificar)
            || $recibidos->diff($esperados)->isNotEmpty()) {
            throw GmailApiException::authFailed('Google entregó alcances distintos de gmail.modify. No se guardó el token.');
        }
    }

    private function perfilAutorizado(CuentaCorreo $cuenta, string $accessToken): string
    {
        $usuario = rawurlencode($cuenta->buzon_canonico ?: $cuenta->direccion);
        $response = Http::withToken($accessToken)
            ->get('https://gmail.googleapis.com/gmail/v1/users/'.$usuario.'/profile');

        if (! $response->successful()) {
            throw GmailApiException::authFailed('Gmail no devolvió el perfil. No se marcó como usuario ni como grupo.');
        }

        $email = strtolower((string) ($response->json('emailAddress') ?? ''));
        $esperadas = array_values(array_filter([
            strtolower($cuenta->direccion),
            strtolower((string) $cuenta->buzon_canonico),
        ]));

        if ($email === '' || ! in_array($email, $esperadas, true)) {
            throw GmailApiException::authFailed('El perfil de Gmail no coincide con la cuenta registrada.');
        }

        return $email;
    }
}
