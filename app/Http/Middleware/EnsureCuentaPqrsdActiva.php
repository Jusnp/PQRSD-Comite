<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una cuenta desactivada pierde el acceso en cada solicitud autenticada,
 * no solo en el siguiente inicio de sesión.
 */
class EnsureCuentaPqrsdActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && (! $user->activo || $user->authentik_status === User::AUTHENTIK_DEACTIVATED)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Esta cuenta está desactivada en PQRSD.',
            ]);
        }

        return $next($request);
    }
}
