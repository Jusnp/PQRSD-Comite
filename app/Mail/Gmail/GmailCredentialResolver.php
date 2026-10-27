<?php

namespace App\Mail\Gmail;

use App\Models\CuentaCorreo;
use App\Models\GmailOauthToken;
use App\Services\Gmail\GmailOAuthService;

/**
 * Resuelve access tokens sin persistir secretos en claro.
 * Prioridad: OAuth cifrado en BD → env credential_ref → service account (DWD, opt-in).
 */
class GmailCredentialResolver
{
    public function accessToken(CuentaCorreo $cuenta): string
    {
        $oauth = app(GmailOAuthService::class);
        try {
            $fromOauth = $oauth->refreshIfNeeded($cuenta);
            if (filled($fromOauth)) {
                return $fromOauth;
            }
        } catch (GmailApiException $e) {
            // Continuar con credential_ref si OAuth no aplica
            if (GmailOauthToken::where('cuenta_correo_id', $cuenta->id)->exists()) {
                throw $e;
            }
        }

        if (blank($cuenta->credential_ref)) {
            throw GmailApiException::authFailed('cuenta sin OAuth ni credential_ref');
        }

        $raw = env($cuenta->credential_ref);
        if (blank($raw)) {
            throw GmailApiException::authFailed(
                "variable {$cuenta->credential_ref} no definida en el entorno"
            );
        }

        $raw = trim((string) $raw);

        if (is_file($raw)) {
            return $this->tokenFromServiceAccountFile($raw, $cuenta);
        }

        if (str_starts_with($raw, '{')) {
            $json = json_decode($raw, true);
            if (! is_array($json)) {
                throw GmailApiException::authFailed('JSON de credencial inválido');
            }
            if (! empty($json['access_token'])) {
                return (string) $json['access_token'];
            }
            if (! empty($json['type']) && $json['type'] === 'service_account') {
                throw GmailApiException::authFailed(
                    'Service account embebido: use ruta a archivo JSON y Domain-Wide Delegation'
                );
            }
        }

        return $raw;
    }

    public function delegatedUser(CuentaCorreo $cuenta): string
    {
        $extra = $cuenta->config_extra ?? [];

        if (filled($cuenta->buzon_canonico)) {
            return (string) $cuenta->buzon_canonico;
        }

        return (string) ($extra['delegated_user'] ?? $cuenta->direccion);
    }

    private function tokenFromServiceAccountFile(string $path, CuentaCorreo $cuenta): string
    {
        $enabled = (bool) config('pqrsd_correo.gmail.service_account_enabled', false);
        if (! $enabled) {
            throw GmailApiException::authFailed(
                'Service account detectado pero GMAIL_SERVICE_ACCOUNT_ENABLED=false. '
                .'Preferir OAuth por buzón piloto; DWD solo con aprobación Workspace.'
            );
        }

        throw GmailApiException::authFailed(
            'Intercambio JWT de service account pendiente de validación institucional (Google Cloud).'
        );
    }
}
