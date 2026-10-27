@php
    $variant = $variant ?? 'header';
    $nombre = config('identidad.nombre_corto', 'Comité de Estudios Médicos');
    $aria = $ariaLabel ?? config('identidad.nombre_sistema', $nombre);
    $headerRel = config('identidad.logo_header', 'images/logo-comite-estudios-medicos.png');
    $principalRel = config('identidad.logo_principal', 'images/logo-comite-estudios-medicos.png');
    $compactoRel = config('identidad.logo_compacto', 'images/logo-comite-estudios-medicos.png');
    $logoRel = $variant === 'nav'
        ? ($compactoRel ?: $headerRel ?: $principalRel)
        : ($headerRel ?: $principalRel ?: $compactoRel);
    $logoAbs = $logoRel ? public_path($logoRel) : null;
    $tieneLogo = is_string($logoRel) && $logoRel !== '' && is_string($logoAbs) && is_file($logoAbs);
    $cacheBust = $tieneLogo ? (string) filemtime($logoAbs) : '1';
    $cssClass = $variant === 'nav' ? 'nav-brand-logo brand-logo' : 'brand-logo';
    // Proporción real del PNG rectangular (~688×308 ≈ 172:77). No forzar 1:1.
    $attrW = $variant === 'nav' ? 129 : 172;
    $attrH = $variant === 'nav' ? 58 : 77;
@endphp
@if($tieneLogo)
    <img
        src="{{ asset($logoRel) }}?v={{ $cacheBust }}"
        alt="{{ $nombre }}"
        class="{{ $cssClass }}"
        width="{{ $attrW }}"
        height="{{ $attrH }}"
        decoding="async"
    >
@else
    {{-- Reserva técnica: solo si faltan los PNG institucionales en public/images --}}
    <span class="brand-wordmark brand-wordmark--{{ $variant }}" aria-label="{{ $aria }}">{{ $nombre }}</span>
@endif
