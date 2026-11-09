<?php

namespace App\Console\Commands;

use App\Mail\Gmail\GmailApiClient;
use App\Mail\Gmail\GmailApiException;
use App\Mail\Gmail\GmailCredentialResolver;
use App\Mail\Gmail\HttpGmailApiClient;
use App\Models\CuentaCorreo;
use Illuminate\Console\Command;

class VerificarTipoBuzonCommand extends Command
{
    protected $signature = 'pqrsd:verificar-tipo-buzon {direccion}';

    protected $description = 'Compara el perfil de Gmail con la cuenta registrada, si hay credencial. No adivina si es un grupo.';

    public function handle(GmailCredentialResolver $credenciales): int
    {
        $direccion = strtolower(trim((string) $this->argument('direccion')));
        $cuenta = CuentaCorreo::query()->whereRaw('LOWER(direccion) = ?', [$direccion])->first();
        if (! $cuenta) {
            $this->error('Esa dirección no está registrada como cuenta de correo.');

            return 1;
        }

        if (! app()->bound(GmailApiClient::class) && blank($cuenta->credential_ref) && ! $cuenta->oauthToken()->exists()) {
            $cuenta->update(['verificacion_buzon' => 'pendiente']);
            $this->warn('Sin credencial. No se consultó Gmail. Confirme en Google Admin si es usuario, alias o grupo.');

            return 0;
        }

        try {
            $cliente = app()->bound(GmailApiClient::class)
                ? app(GmailApiClient::class)
                : new HttpGmailApiClient($credenciales->accessToken($cuenta));
            $perfil = $cliente->getProfile($credenciales->delegatedUser($cuenta));
        } catch (GmailApiException $e) {
            $cuenta->update(['verificacion_buzon' => 'pendiente']);
            $this->warn('Gmail no devolvió un perfil. No se marcó como grupo ni como usuario. '.$e->getMessage());

            return 1;
        }

        if (! ($perfil['ok'] ?? false) || blank($perfil['email'] ?? null)) {
            $cuenta->update(['verificacion_buzon' => 'pendiente']);
            $this->warn('El perfil no trae correo. Revise la dirección en Google Admin.');

            return 1;
        }

        $email = strtolower((string) $perfil['email']);
        if ($email === strtolower($cuenta->direccion)) {
            $this->guardar($cuenta, 'usuario', 'individual', null);
            $this->info('El perfil coincide con la dirección. Quedó como usuario.');

            return 0;
        }

        $this->guardar($cuenta, 'alias', 'alias', $email);
        $this->info('El perfil responde por otra dirección. Quedó como alias y el canónico es el perfil, si estaba vacío.');

        return 0;
    }

    private function guardar(CuentaCorreo $cuenta, string $verificacion, string $tipo, ?string $canonico): void
    {
        if (in_array($cuenta->tipo_buzon, [null, '', 'desconocido', 'otro'], true)) {
            $cuenta->tipo_buzon = $tipo;
        }
        if ($canonico && blank($cuenta->buzon_canonico)) {
            $cuenta->buzon_canonico = $canonico;
        }
        $cuenta->verificacion_buzon = $verificacion;
        $cuenta->save();
    }
}
