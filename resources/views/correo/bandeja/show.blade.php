@extends('layouts.pqrsd')
@section('title', 'Mensaje correo')
@section('heading', $mensaje->asunto ?: '(sin asunto)')
@section('subheading', 'Detalle, hilo y acciones de clasificación')
@section('content')
<a class="back-button" href="{{ route('bandeja-correo.index') }}">← Bandeja</a>

<div class="form-grid" style="margin-top:18px">
    <div class="card card-pad">
        <p><strong>Cuenta:</strong> {{ $mensaje->cuenta?->direccion }}</p>
        <p><strong>Destinatario original:</strong> {{ $mensaje->destinatario_original ?? '—' }}</p>
        <p><strong>Remitente:</strong> {{ $mensaje->remitente_nombre }} &lt;{{ $mensaje->remitente }}&gt;</p>
        <p><strong>Message-ID:</strong> <code>{{ $mensaje->message_id }}</code></p>
        <p><strong>In-Reply-To:</strong> <code>{{ $mensaje->in_reply_to ?: '—' }}</code></p>
        <p><strong>Clasificación:</strong> {{ str_replace('_',' ', $mensaje->clasificacion) }}</p>
        <p><strong>Motivo:</strong> {{ $mensaje->motivo_clasificacion }}</p>
        <p><strong>Radicado:</strong>
            @if($mensaje->pqrsd)
                <a href="{{ route('pqrsd.show', $mensaje->pqrsd) }}">{{ $mensaje->pqrsd->radicado }}</a>
            @else
                {{ $mensaje->radicado_detectado ?: '—' }}
            @endif
        </p>
        <h3>Contenido</h3>
        <pre style="white-space:pre-wrap;font-family:inherit">{{ $mensaje->cuerpo_texto }}</pre>
        @if($mensaje->adjuntos->isNotEmpty())
            <h3>Adjuntos</h3>
            <ul>
                @foreach($mensaje->adjuntos as $adj)
                    <li>{{ $adj->nombre_original }} ({{ $adj->tamano }} bytes)</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="stack-gap">
        @can('correos.review')
        @if($mensaje->clasificacion === 'pendiente_revision')
        <div class="card card-pad">
            <h3 style="margin-top:0">Acciones de revisión</h3>
            <form method="POST" action="{{ route('bandeja-correo.confirmar', $mensaje) }}" style="margin-bottom:12px">
                @csrf
                <button class="btn btn-green" type="submit">Confirmar como nueva PQRSD</button>
            </form>
            <form method="POST" action="{{ route('bandeja-correo.asociar', $mensaje) }}" style="margin-bottom:12px">
                @csrf
                <label class="form-label">Asociar a radicado existente</label>
                <select class="form-select" name="pqrsd_id" required>
                    <option value="">Seleccione</option>
                    @foreach($casos as $caso)
                        <option value="{{ $caso->id }}">{{ $caso->radicado }} — {{ $caso->solicitante_nombre ?: 's/n' }}</option>
                    @endforeach
                </select>
                <button class="btn btn-secondary" style="margin-top:10px" type="submit">Asociar</button>
            </form>
            <form method="POST" action="{{ route('bandeja-correo.descartar', $mensaje) }}" data-pqrsd-confirmar data-variante="peligro" data-titulo="¿Descartar este mensaje?" data-mensaje="El mensaje quedará descartado en la bandeja de PQRSD. Esta acción no borra correos en Google ni cambia Authentik." data-aceptar="Descartar mensaje">
                @csrf
                <label class="form-label">Descartar</label>
                <input class="form-input" name="motivo" required placeholder="Motivo del descarte">
                <button class="btn btn-red" style="margin-top:10px" type="submit">Descartar</button>
            </form>
        </div>
        @endif
        @endcan

        @can('correos.reply')
        <div class="card card-pad">
            <h3 style="margin-top:0">Responder (conserva hilo)</h3>
            <form method="POST" action="{{ route('bandeja-correo.responder', $mensaje) }}">
                @csrf
                <label class="form-label">Asunto</label>
                <input class="form-input" name="asunto" value="{{ old('asunto', 'Re: '.($mensaje->asunto ?: '')) }}">
                <label class="form-label" style="margin-top:10px">Cuerpo</label>
                <textarea class="form-input" name="cuerpo" rows="6" required style="min-height:140px">{{ old('cuerpo') }}</textarea>
                <button class="btn btn-green" style="margin-top:10px" type="submit">Enviar respuesta</button>
            </form>
        </div>
        @endcan
    </div>
</div>

<div class="card card-pad" style="margin-top:18px">
    <h2 style="margin-top:0">Historial de conversación</h2>
    @foreach($hilo as $item)
        <div class="timeline-item">
            <strong>{{ $item->direccion === 'saliente' ? '→ Saliente' : '← Entrante' }} — {{ $item->asunto }}</strong>
            <div class="muted">{{ $item->fecha_mensaje?->timezone('America/Bogota')->format('d/m/Y H:i') }} · {{ $item->remitente }} · {{ $item->message_id }}</div>
            <div>{{ \Illuminate\Support\Str::limit($item->cuerpo_texto, 280) }}</div>
        </div>
    @endforeach
</div>
@endsection
