<?php

namespace App\Http\Controllers\Gmail;

use App\Http\Controllers\Controller;
use App\Mail\Gmail\GmailApiException;
use App\Models\CuentaCorreo;
use App\Services\Gmail\GmailOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GmailOAuthController extends Controller
{
    public function redirect(CuentaCorreo $cuentaCorreo, GmailOAuthService $oauth): RedirectResponse
    {
        $this->authorizeManage();

        try {
            $auth = $oauth->authorizationUrl($cuentaCorreo);
        } catch (GmailApiException $e) {
            return back()->withErrors(['oauth' => $e->getMessage()]);
        }

        return redirect()->away($auth['url']);
    }

    public function callback(Request $request, GmailOAuthService $oauth): RedirectResponse
    {
        $this->authorizeManage();

        if ($request->filled('error')) {
            return redirect()
                ->route('cuentas-correo.index')
                ->withErrors(['oauth' => 'Google denegó OAuth: '.$request->string('error')]);
        }

        try {
            $cuenta = $oauth->handleCallback(
                (string) $request->string('code'),
                (string) $request->string('state')
            );
        } catch (GmailApiException $e) {
            return redirect()
                ->route('cuentas-correo.index')
                ->withErrors(['oauth' => $e->getMessage()]);
        }

        return redirect()
            ->route('cuentas-correo.index')
            ->with('success', 'OAuth vinculado para '.$cuenta->direccion.' (tokens cifrados, no en logs).');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->can('correos.manage'), 403);
    }
}
