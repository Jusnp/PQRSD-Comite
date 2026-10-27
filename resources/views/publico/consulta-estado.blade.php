@extends('layouts.publico')
@section('title', 'Estado '.$radicado)
@section('heading', 'Estado de su PQRSD')
@section('subheading', $radicado)

@section('content')
<div class="card card-pad">
    <p><strong>Número de radicado:</strong> <code>{{ $radicado }}</code></p>
    <p><strong>Estado general:</strong>
        <span class="badge {{ $estadoPublico === 'Finalizada' ? 'badge-green' : ($estadoPublico === 'Recibida' ? 'badge-blue' : 'badge-gray') }}">
            {{ $estadoPublico }}
        </span>
    </p>
    <p>{{ $mensaje }}</p>
    @if($medioEtiqueta)
        <p class="muted">
            Medio indicado para la respuesta: {{ $medioEtiqueta }}.
            La respuesta completa se entregará por ese canal cuando corresponda; no se muestra aquí.
        </p>
    @endif
    <p class="muted" style="margin-bottom:0">
        Por protección de datos, esta consulta no muestra nombres, documentos, datos de contacto,
        información clínica, adjuntos ni el texto de la respuesta.
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px">
        <a class="btn btn-secondary" href="{{ route('publico.consulta') }}">Nueva consulta</a>
        <a class="btn btn-secondary" href="{{ route('publico.consulta.segura') }}">Consulta segura (respuesta completa)</a>
        <a class="btn btn-secondary" href="{{ route('publico.formulario') }}">Nueva solicitud</a>
    </div>
</div>
@endsection
