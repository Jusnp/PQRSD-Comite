@php
    $urlPolitica = trim((string) config('identidad.politica_tratamiento_url', ''));
    $sitioPolitica = trim((string) config('identidad.politica_sitio_url', ''));
    $seccionPolitica = trim((string) config('identidad.politica_sitio_seccion', 'Contacto / PQRS'));
    $versionPolitica = trim((string) config('identidad.politica_tratamiento_version', ''));
    $codigoPolitica = trim((string) config('identidad.politica_tratamiento_codigo', ''));
    $fechaPolitica = trim((string) config('identidad.politica_tratamiento_fecha', ''));
    $politicaPublicada = $urlPolitica !== '' && $versionPolitica !== '';
@endphp
<p class="muted" style="margin:0">
    @if($politicaPublicada)
        @if($sitioPolitica !== '')
            <a href="{{ $sitioPolitica }}" target="_blank" rel="noopener noreferrer">Sitio oficial del Comité</a>
            (sección {{ $seccionPolitica }}).
        @endif
        <a href="{{ $urlPolitica }}" target="_blank" rel="noopener noreferrer">Consultar Política de Tratamiento de Datos Personales</a>
        del Comité de Estudios Médicos S.A.S.@if($codigoPolitica !== '') ({{ $codigoPolitica }})@endif,
        versión {{ $versionPolitica }}@if($fechaPolitica !== ''), {{ $fechaPolitica }}@endif.
    @elseif($urlPolitica !== '')
        <a href="{{ $urlPolitica }}" target="_blank" rel="noopener noreferrer">Consultar Política de Tratamiento de Datos Personales</a>.
        Falta la versión institucional configurada, así que el sistema no registra una aceptación.
    @else
        Política de Tratamiento de Datos Personales del Comité de Estudios Médicos: pendiente de publicación oficial.
        No se enlaza un documento de otra institución.
    @endif
</p>
