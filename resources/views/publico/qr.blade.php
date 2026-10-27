@extends(auth()->check() ? 'layouts.pqrsd' : 'layouts.publico')
@section('title', 'Formulario público / Código QR')
@section('heading', config('identidad.qr_titulo'))
@section('subheading', 'Enlace y código para radicar PQRSD ante '.config('identidad.nombre_corto'))

@section('content')
@unless($distributable)
<div class="alert alert-warning" role="alert">
    <strong>QR de prueba — No apto para distribuir a pacientes.</strong>
    La URL actual apunta a un entorno local o no definitivo
    (<code>{{ $url }}</code>).
    Origen de configuración: <code>{{ $urlSource }}</code>.
    En producción configure <code>PQRSD_PUBLIC_URL</code> con el dominio HTTPS institucional.
    En desarrollo, <code>PQRSD_DEV_URL</code> / <code>APP_URL</code> deben coincidir con el host y puerto reales del servidor.
</div>
@endunless

@if($distributable)
<div class="alert alert-success" role="status">
    URL pública configurada (<code>{{ $urlSource }}</code>). Este código puede prepararse para impresión en sedes.
</div>
@endif

<p style="max-width:42rem;margin:0 0 18px">
    {{ config('identidad.textos.qr_explicacion') }}
</p>

<div class="card qr-panel">
    <p class="muted" style="margin-top:0"><strong>Vista previa</strong></p>
    <img src="{{ $qrSvgAsset }}" alt="Código QR del {{ config('identidad.qr_titulo') }}" width="320" height="320">
    <p style="margin-top:16px"><strong>Enlace al formulario público:</strong></p>
    <p><a id="url-publico-qr" href="{{ $url }}">{{ $url }}</a></p>
    <p class="muted">
        El botón Abrir, Copiar enlace y el código QR (PNG/SVG) usan exactamente esta misma URL.
        No incluye datos personales.
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
        <a class="btn btn-green" href="{{ $url }}" target="_blank" rel="noopener">Abrir formulario</a>
        <button type="button" class="btn btn-secondary" id="btn-copiar-url">Copiar enlace</button>
        <a class="btn btn-secondary" href="{{ route('publico.qr.download', ['format' => 'png']) }}">Descargar QR en PNG</a>
        <a class="btn btn-secondary" href="{{ route('publico.qr.download', ['format' => 'svg']) }}">Descargar QR en SVG</a>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.getElementById('btn-copiar-url')?.addEventListener('click', async () => {
    const url = document.getElementById('url-publico-qr')?.href || '';
    try {
        await navigator.clipboard.writeText(url);
        window.pqrsdEstado?.('Enlace copiado.');
    } catch (e) {
        window.pqrsdAviso?.({
            titulo: 'No se pudo copiar automáticamente',
            mensaje: 'Seleccione el enlace y cópielo manualmente.',
            valor: url,
            variante: 'info',
        });
    }
});
</script>
@endpush
