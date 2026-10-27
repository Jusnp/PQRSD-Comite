<!DOCTYPE html>
<html lang="es" data-theme="{{ $pqrsdTheme ?? 'light' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $titulo }} — {{ config('identidad.nombre_corto') }}</title>
    @include('partials.favicon')
    @include('partials.theme-head')
    <link rel="stylesheet" href="{{ asset('css/pqrsd-theme.css') }}">
    <link rel="stylesheet" href="{{ asset('css/pqrsd-app.css') }}">
</head>
<body>
<main class="card card-pad" style="max-width:560px;margin:48px auto" role="main">
    <p class="muted" style="margin-top:0">Comité de Estudios Médicos · PQRSD</p>
    <h1 style="margin-top:0">{{ $titulo }}</h1>
    <p>{{ $mensaje }}</p>
    <p style="margin-bottom:0">
        @if($accion === 'bandeja')
            <a class="btn btn-green" href="{{ route('pqrsd.index') }}">Volver a la bandeja</a>
        @elseif($accion === 'login')
            <a class="btn btn-green" href="{{ route('login') }}">Iniciar sesión</a>
        @else
            <a class="btn btn-green" href="{{ route('publico.formulario') }}">Ir al formulario público</a>
        @endif
    </p>
</main>
</body>
</html>
