<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Authentik\AuthentikApiClient;
use App\Services\Authentik\AuthentikProvisionService;
use App\Services\Authentik\OidcException;
use App\Services\Authentik\OidcIdTokenVerifier;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Inicio de sesión por Authentik (OIDC).
 * Solo usuarios previamente autorizados en Laravel. Roles Spatie no se sobrescriben con grupos.
 */
class AuthentikController extends Controller
{
    public function __construct(
        private readonly AuthentikProvisionService $provision,
        private readonly AuthentikApiClient $api,
        private readonly OidcIdTokenVerifier $tokens,
    ) {}

    public function redirect(): SymfonyRedirectResponse|RedirectResponse
    {
        if (! $this->oidcConfigurado()) {
            return redirect()->route('login')
                ->withErrors(['email' => 'El inicio de sesión con Authentik no está configurado.']);
        }

        $nonce = Str::random(48);
        session(['oidc_nonce' => $nonce]);

        return Socialite::driver('authentik')
            ->setScopes(['openid', 'profile', 'email'])
            ->with(['nonce' => $nonce])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        if (! $this->oidcConfigurado()) {
            return redirect()->route('login')
                ->withErrors(['email' => 'El inicio de sesión con Authentik no está configurado.']);
        }

        try {
            $social = Socialite::driver('authentik')->user();
            $userinfo = $social->getRaw();
            $idToken = $social->accessTokenResponseBody['id_token'] ?? null;
            $datos = $this->tokens->verificar(is_string($idToken) ? $idToken : null, session()->pull('oidc_nonce'));
        } catch (OidcException $exception) {
            $mensaje = $exception->codigo === 'issuer'
                ? 'El emisor de la identidad no corresponde a Authentik institucional.'
                : 'No fue posible validar el inicio de sesión con Authentik. Intente de nuevo.';

            return redirect()->route('login')->withErrors(['email' => $mensaje]);
        } catch (Exception $exception) {
            report($exception);

            return redirect()->route('login')
                ->withErrors(['email' => 'No fue posible validar el inicio de sesión con Authentik. Intente de nuevo.']);
        }

        $emisor = (string) ($datos['iss'] ?? '');

        $sub = filled($datos['sub'] ?? null) ? (string) $datos['sub'] : '';
        $username = strtolower(trim((string) ($datos['preferred_username'] ?? $userinfo['preferred_username'] ?? $datos['nickname'] ?? '')));
        if ($username === 'adminsispam') {
            return $this->denegar('La identidad AdminSispam no está autorizada en PQRSD.');
        }

        $email = filled($datos['email'] ?? null)
            ? strtolower(trim((string) $datos['email']))
            : (filled($userinfo['email'] ?? null)
                ? strtolower(trim((string) $userinfo['email']))
                : (filled($social->getEmail()) ? strtolower(trim($social->getEmail())) : null));

        $user = null;
        if ($sub !== '') {
            $user = User::query()->where('authentik_sub', $sub)->first();
        }

        // Primera vinculación: autorización administrativa previa. El correo no basta.
        $correoVerificado = ($datos['email_verified'] ?? $userinfo['email_verified'] ?? false) === true;
        if (! $user && $email !== null) {
            $candidato = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereIn('authentik_status', [
                    User::AUTHENTIK_PREPARED,
                    User::AUTHENTIK_SENT,
                    User::AUTHENTIK_LINK_PENDING,
                ])
                ->whereNotNull('authentik_authorization_hash')
                ->where(function ($query) {
                    $query->whereNull('authentik_sub')->orWhere('authentik_sub', '');
                })
                ->first();

            $invitacionVigente = $candidato !== null
                && filled($candidato->authentik_invitation_pk)
                && ! $candidato->invitationExpired();
            $identidadAutorizada = $candidato !== null && filled($candidato->authentik_user_pk);
            if ($candidato && $username !== ''
                && ($invitacionVigente || $identidadAutorizada)
                && hash_equals(strtolower((string) $candidato->documento), $username)) {
                $user = $candidato;
            }
        }

        if (! $user) {
            $quien = $email ?: 'desconocida';

            return $this->denegar("La identidad «{$quien}» no está autorizada en PQRSD. Solicite acceso a un administrador.");
        }

        if ($user->authentik_status === User::AUTHENTIK_DEACTIVATED || $user->authentik_status === 'deactivated') {
            return $this->denegar('Su usuario está desactivado. Comuníquese con un administrador.');
        }

        if (! $user->activo && filled($user->authentik_sub) && $user->authentik_status === User::AUTHENTIK_ACTIVE) {
            return $this->denegar('Su usuario está inactivo. Comuníquese con un administrador.');
        }

        if (in_array($user->authentik_status, [User::AUTHENTIK_PREPARED, User::AUTHENTIK_SENT], true)
            && $user->invitationExpired() && blank($user->authentik_user_pk)) {
            return $this->denegar('La invitación venció. Solicite al administrador que reenvíe la invitación.');
        }

        // No vincular solo por correo a una cuenta ya ligada a otro sub.
        if (filled($user->authentik_sub) && $user->authentik_sub !== $sub) {
            return $this->denegar('Esta cuenta PQRSD ya está vinculada a otra identidad institucional.');
        }

        if (filled($user->authentik_issuer) && ! hash_equals((string) $user->authentik_issuer, $emisor)) {
            return $this->denegar('El emisor de la identidad no corresponde a esta cuenta PQRSD.');
        }

        if (! $user->activo && ! in_array($user->authentik_status, [User::AUTHENTIK_PREPARED, User::AUTHENTIK_SENT, User::AUTHENTIK_LINK_PENDING], true)) {
            return $this->denegar('Su usuario está inactivo. Comuníquese con un administrador.');
        }

        $authentikUserPk = $user->authentik_user_pk;
        if (blank($user->authentik_sub)) {
            $comprobada = $this->provision->identidadAutorizadaParaIngreso($user, $sub);
            if ($comprobada === null) {
                return $this->denegar('No se pudo comprobar que esta identidad corresponda a la incorporación autorizada.');
            }
            $authentikUserPk = $comprobada['pk'];
        }

        $this->provision->markLinkedFromOidc($user, $sub, $authentikUserPk, $correoVerificado, $emisor);
        $user->refresh();
        if (! $user->activo) {
            return $this->denegar('Su usuario está inactivo. Comuníquese con un administrador.');
        }

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        $cookie = cookie('pqrsd_theme', $user->resolvedTheme(), 60 * 24 * 365, '/', null, false, false, false, 'Lax');

        return redirect()
            ->intended(route('pqrsd.index'))
            ->withCookie($cookie);
    }

    private function denegar(string $mensaje): RedirectResponse
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $mensaje]);
    }

    private function oidcConfigurado(): bool
    {
        if (! (bool) config('authentik.enabled') && ! (bool) config('services.authentik.enabled')) {
            return false;
        }

        $cfg = config('services.authentik');

        return filled($cfg['base_url'] ?? null)
            && filled($cfg['client_id'] ?? null)
            && filled($cfg['client_secret'] ?? null);
    }
}
