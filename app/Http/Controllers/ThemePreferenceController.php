<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie;

class ThemePreferenceController extends Controller
{
    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', 'in:light,dark'],
        ]);

        $theme = $data['theme'];

        if (Auth::check()) {
            Auth::user()->forceFill(['theme_preference' => $theme])->save();
        }

        $cookie = cookie('pqrsd_theme', $theme, 60 * 24 * 365, '/', null, false, false, false, 'Lax');

        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'theme' => $theme])->withCookie($cookie);
        }

        return back()->withCookie($cookie);
    }
}
