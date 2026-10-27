<?php

namespace App\Providers;

use App\Models\CanalIngreso;
use App\Models\CuentaCorreo;
use App\Models\MensajeCorreo;
use App\Models\MotivoPqrsd;
use App\Models\ReglaSla;
use App\Models\TipoPqrsd;
use App\Services\Authentik\AuthentikApiClient;
use App\Services\Authentik\FakeAuthentikApiClient;
use App\Services\Authentik\HttpAuthentikApiClient;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Authentik\Provider as AuthentikProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthentikApiClient::class, function ($app) {
            if ($app->environment('testing')) {
                return new FakeAuthentikApiClient;
            }

            return new HttpAuthentikApiClient;
        });
    }

    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('authentik', AuthentikProvider::class);
        });

        date_default_timezone_set(config('app.timezone', 'America/Bogota'));
        Paginator::defaultView('vendor.pagination.simple-pqrsd');
        Paginator::defaultSimpleView('vendor.pagination.simple-pqrsd');

        Route::bind('canal', fn (string $value) => CanalIngreso::findOrFail($value));
        Route::bind('tipo', fn (string $value) => TipoPqrsd::findOrFail($value));
        Route::bind('motivo', fn (string $value) => MotivoPqrsd::findOrFail($value));
        Route::bind('regla', fn (string $value) => ReglaSla::findOrFail($value));
        Route::bind('cuentaCorreo', fn (string $value) => CuentaCorreo::findOrFail($value));
        Route::bind('mensaje', fn (string $value) => MensajeCorreo::findOrFail($value));

        View::composer(['layouts.pqrsd', 'layouts.publico', 'layouts.guest', 'errors.marco'], function ($view) {
            $theme = Auth::user()?->resolvedTheme()
                ?? request()->cookie('pqrsd_theme')
                ?? 'light';
            if (! in_array($theme, ['light', 'dark'], true)) {
                $theme = 'light';
            }
            $view->with('pqrsdTheme', $theme);
        });
    }
}
