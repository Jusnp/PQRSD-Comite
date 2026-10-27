<!DOCTYPE html>
<html lang="es" data-theme="{{ $pqrsdTheme ?? 'light' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ config('identidad.descripcion') }}">
    <title>@yield('title', 'PQRSD') — {{ config('identidad.nombre_corto') }}</title>
    @include('partials.favicon')
    @include('partials.theme-head')
    <link rel="stylesheet" href="{{ asset('css/pqrsd-theme.css') }}">
    <link rel="stylesheet" href="{{ asset('css/pqrsd-app.css') }}">
    @stack('styles')
</head>
<body>
<a class="pqrsd-skip-link" href="#contenido-principal">Saltar al contenido</a>

<button type="button" class="siruta-menu-trigger" id="pqrsd-menu-open" aria-controls="pqrsd-navigation" aria-expanded="false">
    Menú
</button>
<button type="button" class="siruta-menu-backdrop" id="pqrsd-menu-backdrop" hidden aria-label="Cerrar menú"></button>

<nav class="siruta-navigation" id="pqrsd-navigation" aria-label="Navegación principal">
    <header>
        <div class="nav-brand">
            @include('partials.brand-mark', ['variant' => 'nav', 'ariaLabel' => config('identidad.nombre_sistema')])
        </div>
        <button type="button" id="pqrsd-menu-close" aria-label="Cerrar menú">Cerrar</button>
    </header>

    @auth
        <h2>Operación</h2>
        @can('pqrsd.view')
            <a href="{{ route('pqrsd.index') }}" @if(request()->routeIs('pqrsd.index')) aria-current="page" @endif>Bandeja de PQRSD</a>
            <a href="{{ route('vencimientos.proximos') }}" @if(request()->routeIs('vencimientos.*')) aria-current="page" @endif>Vencimientos</a>
        @endcan
        @can('reportes.view')
            <a href="{{ route('reportes.indicadores') }}" @if(request()->routeIs('reportes.indicadores*')) aria-current="page" @endif>Indicadores PQRSD</a>
            <a href="{{ route('reportes.index') }}" @if(request()->routeIs('reportes.index')) aria-current="page" @endif>Reportes</a>
        @endcan
        @can('pqrsd.view')
            <a href="{{ route('publico.qr') }}" @if(request()->routeIs('publico.qr*')) aria-current="page" @endif>Formulario público / Código QR</a>
        @endcan
        <a href="{{ route('profile.edit') }}" @if(request()->routeIs('profile.*')) aria-current="page" @endif>Mi perfil</a>

        @canany(['usuarios.manage','correos.manage','sedes.manage','aseguradoras.manage','canales.manage','tipos.manage','motivos.manage','areas.manage','reglas_sla.manage','pqrsd.create_manual'])
        <details class="nav-admin-group" @if(request()->routeIs(['usuarios.*','sedes.*','aseguradoras.*','canales.*','tipos.*','motivos.*','areas.*','reglas.*','cuentas-correo.*','ops.*','pqrsd.create'])) open @endif>
            <summary>Administración</summary>
            @can('pqrsd.create_manual')
                <p class="nav-admin-label">Radicación manual</p>
                <a href="{{ route('pqrsd.create') }}" @if(request()->routeIs('pqrsd.create')) aria-current="page" @endif>Nueva PQRSD</a>
            @endcan
            @canany(['sedes.manage','aseguradoras.manage','canales.manage','tipos.manage','motivos.manage','areas.manage'])
                <p class="nav-admin-label">Catálogos</p>
            @endcanany
            @can('sedes.manage')
                <a href="{{ route('sedes.index') }}" @if(request()->routeIs('sedes.*')) aria-current="page" @endif>Sedes</a>
            @endcan
            @can('aseguradoras.manage')
                <a href="{{ route('aseguradoras.index') }}" @if(request()->routeIs('aseguradoras.*')) aria-current="page" @endif>Aseguradoras</a>
            @endcan
            @can('canales.manage')
                <a href="{{ route('canales.index') }}" @if(request()->routeIs('canales.*')) aria-current="page" @endif>Canales</a>
            @endcan
            @can('tipos.manage')
                <a href="{{ route('tipos.index') }}" @if(request()->routeIs('tipos.*')) aria-current="page" @endif>Tipos</a>
            @endcan
            @can('motivos.manage')
                <a href="{{ route('motivos.index') }}" @if(request()->routeIs('motivos.*')) aria-current="page" @endif>Motivos</a>
            @endcan
            @can('areas.manage')
                <a href="{{ route('areas.index') }}" @if(request()->routeIs('areas.*')) aria-current="page" @endif>Áreas internas</a>
            @endcan
            @can('usuarios.manage')
                <p class="nav-admin-label">Seguridad</p>
                <a href="{{ route('usuarios.index') }}" @if(request()->routeIs('usuarios.*')) aria-current="page" @endif>Usuarios y permisos</a>
            @endcan
            @canany(['reglas_sla.manage','correos.manage'])
                <p class="nav-admin-label">Configuración</p>
            @endcanany
            @can('reglas_sla.manage')
                <a href="{{ route('reglas.index') }}" @if(request()->routeIs('reglas.*')) aria-current="page" @endif>Reglas SLA</a>
            @endcan
            @can('correos.manage')
                <a href="{{ route('cuentas-correo.index') }}" @if(request()->routeIs('cuentas-correo.*')) aria-current="page" @endif>Integraciones / Cuentas correo</a>
                <a href="{{ route('ops.health') }}" @if(request()->routeIs('ops.*')) aria-current="page" @endif>Salud operativa</a>
            @endcan
        </details>
        @endcanany
    @else
        <a href="{{ route('login') }}">Iniciar sesión</a>
        <a href="{{ route('publico.formulario') }}">Formulario público</a>
        <a href="{{ route('publico.consulta') }}">Consultar radicado</a>
        <a href="{{ route('publico.qr') }}">Formulario público / Código QR</a>
    @endauth
</nav>

<header class="app-header">
    <div class="app-header-inner">
        <div class="brand-area">
            <a href="{{ auth()->check() ? route('pqrsd.index') : url('/') }}" class="brand-link" aria-label="{{ config('identidad.nombre_sistema') }} — Bandeja de PQRSD">
                @include('partials.brand-mark', ['variant' => 'header', 'ariaLabel' => config('identidad.nombre_sistema')])
            </a>
            <div class="brand-divider" aria-hidden="true"></div>
            <div class="header-title">
                @hasSection('retorno')
                    <p class="pqrsd-retorno">@yield('retorno')</p>
                @endif
                <h1>@yield('heading', 'Bandeja de PQRSD')</h1>
                <p>@yield('subheading', 'Gestión de peticiones, quejas, reclamos, sugerencias y denuncias')</p>
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
            @auth
                <span class="header-user">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="logout-button" type="submit">Salir</button>
                </form>
            @endauth
        </div>
    </div>
</header>

<main class="app-main" id="contenido-principal" tabindex="-1">
    @auth
        <nav class="pqrsd-nav" aria-label="Accesos rápidos">
            @can('pqrsd.view')
                <a href="{{ route('pqrsd.index') }}" class="{{ request()->routeIs('pqrsd.index') ? 'is-active' : '' }}">Bandeja</a>
                <a href="{{ route('vencimientos.proximos') }}" class="{{ request()->routeIs('vencimientos.*') ? 'is-active' : '' }}">Vencimientos</a>
            @endcan
            @can('reportes.view')
                <a href="{{ route('reportes.indicadores') }}" class="{{ request()->routeIs('reportes.indicadores*') ? 'is-active' : '' }}">Indicadores</a>
                <a href="{{ route('reportes.index') }}" class="{{ request()->routeIs('reportes.index') ? 'is-active' : '' }}">Reportes</a>
            @endcan
            @can('pqrsd.view')
                <a href="{{ route('publico.qr') }}" class="{{ request()->routeIs('publico.qr*') ? 'is-active' : '' }}">Formulario público / Código QR</a>
            @endcan
        </nav>
    @endauth

    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error" role="alert">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            <strong>Revise el formulario:</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

<script>
(function () {
    const html = document.documentElement;
    const nav = document.getElementById('pqrsd-navigation');
    const openBtn = document.getElementById('pqrsd-menu-open');
    const closeBtn = document.getElementById('pqrsd-menu-close');
    const backdrop = document.getElementById('pqrsd-menu-backdrop');

    function setOpen(open) {
        nav.classList.toggle('is-open', open);
        backdrop.hidden = !open;
        html.classList.toggle('siruta-menu-open', open);
        openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    openBtn?.addEventListener('click', () => setOpen(true));
    closeBtn?.addEventListener('click', () => setOpen(false));
    backdrop?.addEventListener('click', () => setOpen(false));
})();
</script>
@include('partials.theme-toggle-script')
@include('partials.pqrsd-dialogo')
@stack('scripts')
</body>
</html>
