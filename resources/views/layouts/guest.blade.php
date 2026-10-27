<!DOCTYPE html>
<html lang="es" data-theme="{{ $pqrsdTheme ?? 'light' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('identidad.nombre_sistema', config('app.name')) }} — Acceso</title>
    @include('partials.favicon')
    @include('partials.theme-head')
    <link rel="stylesheet" href="{{ asset('css/pqrsd-theme.css') }}">
    <link rel="stylesheet" href="{{ asset('css/pqrsd-app.css') }}">
</head>
<body class="guest-body">
<a class="pqrsd-skip-link" href="#contenido-principal">Saltar al contenido</a>
<header class="app-header">
    <div class="app-header-inner">
        <div class="brand-area">
            <a href="{{ url('/') }}" class="brand-link" aria-label="{{ config('identidad.nombre_sistema') }}">
                @include('partials.brand-mark', ['variant' => 'header', 'ariaLabel' => config('identidad.nombre_sistema')])
            </a>
            <div class="brand-divider" aria-hidden="true"></div>
            <div class="header-title">
                <h1>{{ config('identidad.login_titulo', 'Acceso PQRSD') }}</h1>
                <p>{{ config('identidad.login_subtitulo') }}</p>
            </div>
        </div>
        <div class="header-actions">
            <button
                type="button"
                class="theme-toggle"
                id="pqrsd-theme-toggle"
                aria-label="{{ ($pqrsdTheme ?? 'light') === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro' }}"
                aria-pressed="{{ ($pqrsdTheme ?? 'light') === 'dark' ? 'true' : 'false' }}"
                title="{{ ($pqrsdTheme ?? 'light') === 'dark' ? 'Tema oscuro activo' : 'Tema claro activo' }}"
                data-theme-current="{{ $pqrsdTheme ?? 'light' }}"
            >{{ ($pqrsdTheme ?? 'light') === 'dark' ? '☀️' : '🌙' }}</button>
        </div>
    </div>
</header>
<main class="app-main public-hero" id="contenido-principal" tabindex="-1">
    <div class="card card-pad guest-card">
        {{ $slot }}
    </div>
</main>
@include('partials.theme-toggle-script')
</body>
</html>
