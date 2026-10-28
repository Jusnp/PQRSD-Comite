<!DOCTYPE html>
<html lang="es" data-theme="{{ $pqrsdTheme ?? 'light' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'PQRSD') — {{ config('identidad.nombre_corto') }}</title>
    @include('partials.favicon')
    @include('partials.theme-head')
    <link rel="stylesheet" href="{{ asset('css/pqrsd-theme.css') }}">
    <link rel="stylesheet" href="{{ asset('css/pqrsd-app.css') }}">
    @stack('styles')
</head>
<body class="publico-body">
<a class="pqrsd-skip-link" href="#contenido-principal">Saltar al contenido</a>
<header class="app-header">
    <div class="app-header-inner">
        <div class="brand-area">
            <a href="{{ route('publico.formulario') }}" class="brand-link" aria-label="{{ config('identidad.nombre_sistema') }}">
                @include('partials.brand-mark', ['variant' => 'header', 'ariaLabel' => config('identidad.nombre_sistema')])
            </a>
            <div class="brand-divider" aria-hidden="true"></div>
            <div class="header-title">
                <h1>@yield('heading', config('identidad.publico_encabezado'))</h1>
                <p>@yield('subheading', config('identidad.nombre_corto'))</p>
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
            <a class="btn btn-secondary" href="{{ route('publico.formulario') }}">Radicar PQRSD</a>
            <a class="btn btn-secondary" href="{{ route('publico.consulta') }}">Consultar radicado</a>
        </div>
    </div>
</header>
<main class="app-main public-hero" id="contenido-principal" tabindex="-1">
    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            <strong>Revise los datos marcados.</strong> Complete los campos obligatorios e intente de nuevo.
            <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif
    @yield('content')
</main>
<footer class="app-main" style="padding-top:0">
    @include('partials.politica-datos')
</footer>
@include('partials.theme-toggle-script')
@include('partials.pqrsd-dialogo')
@stack('scripts')
</body>
</html>
