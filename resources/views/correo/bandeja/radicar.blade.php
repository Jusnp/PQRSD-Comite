@extends('layouts.pqrsd')
@section('title', 'Radicar correo')
@section('heading', 'Radicar correo')
@section('subheading', 'Revise el mensaje y complete los datos antes de crear el expediente')
@section('content')
<a class="back-button" href="{{ route('bandeja-correo.show', $mensaje) }}">← Volver al mensaje</a>

<div class="form-grid" style="margin-top:18px">
    <div class="card card-pad">
        <p><strong>Remitente:</strong> {{ $mensaje->remitente_nombre }} &lt;{{ $mensaje->remitente }}&gt;</p>
        <p><strong>Asunto:</strong> {{ $mensaje->asunto ?: '(sin asunto)' }}</p>
        <p><strong>Fecha:</strong> {{ $mensaje->fecha_mensaje?->timezone('America/Bogota')->format('d/m/Y H:i') }}</p>
        <p><strong>Buzón:</strong> {{ $mensaje->cuenta?->direccion }}</p>
        <p><strong>Estado:</strong> {{ $mensaje->etiquetaRevision() }}</p>
        <h3>Contenido recibido</h3>
        <pre style="white-space:pre-wrap;font-family:inherit">{{ $mensaje->cuerpo_texto }}</pre>
    </div>

    <form class="card card-pad" method="POST" action="{{ route('bandeja-correo.radicar.store', $mensaje) }}">
        @csrf
        <h3 style="margin-top:0">Datos del expediente</h3>
        <p class="muted">La recepción queda en la fecha del correo. El canal es correo institucional.</p>

        <label class="form-label">
            <input type="checkbox" name="es_anonimo" value="1" @checked(old('es_anonimo'))>
            Solicitud anónima
        </label>

        <label class="form-label" for="solicitante_nombre">Nombre del solicitante</label>
        <input class="form-input" id="solicitante_nombre" name="solicitante_nombre" value="{{ old('solicitante_nombre', $mensaje->remitente_nombre) }}" maxlength="255">

        <label class="form-label" for="solicitante_email">Correo del solicitante</label>
        <input class="form-input" id="solicitante_email" type="email" name="solicitante_email" value="{{ old('solicitante_email', $mensaje->remitente) }}" maxlength="255">

        <label class="form-label" for="solicitante_telefono">Teléfono</label>
        <input class="form-input" id="solicitante_telefono" name="solicitante_telefono" value="{{ old('solicitante_telefono') }}" maxlength="30">

        <label class="form-label" for="solicitante_documento">Documento</label>
        <input class="form-input" id="solicitante_documento" name="solicitante_documento" value="{{ old('solicitante_documento') }}" maxlength="30">

        <label class="form-label" for="tipo_pqrsd_id">Tipo</label>
        <select class="form-select" id="tipo_pqrsd_id" name="tipo_pqrsd_id">
            <option value="">Pendiente de clasificación</option>
            @foreach($tipos as $tipo)
                <option value="{{ $tipo->id }}" @selected(old('tipo_pqrsd_id') == $tipo->id)>{{ $tipo->nombre }}</option>
            @endforeach
        </select>

        <label class="form-label" for="motivo_pqrsd_id">Motivo</label>
        <select class="form-select" id="motivo_pqrsd_id" name="motivo_pqrsd_id">
            <option value="">Sin motivo</option>
            @foreach($motivos as $motivo)
                <option value="{{ $motivo->id }}" @selected(old('motivo_pqrsd_id') == $motivo->id)>{{ $motivo->nombre }}</option>
            @endforeach
        </select>

        <label class="form-label" for="nivel_riesgo">Nivel de riesgo</label>
        <select class="form-select" id="nivel_riesgo" name="nivel_riesgo" required>
            @foreach(['pendiente','vital_menor','vital_general','priorizado','simple','ordinario'] as $riesgo)
                <option value="{{ $riesgo }}" @selected(old('nivel_riesgo', 'pendiente') === $riesgo)>{{ str_replace('_', ' ', $riesgo) }}</option>
            @endforeach
        </select>

        <label class="form-label" for="sede_id">Sede</label>
        <select class="form-select" id="sede_id" name="sede_id">
            <option value="">Sin sede</option>
            @foreach($sedes as $sede)
                <option value="{{ $sede->id }}" @selected(old('sede_id', $mensaje->cuenta?->sede_id) == $sede->id)>{{ $sede->nombre }}</option>
            @endforeach
        </select>

        @if($mensaje->cuenta?->aseguradora_id)
            <input type="hidden" name="aseguradora_id" value="{{ $mensaje->cuenta->aseguradora_id }}">
            <p><strong>Organización del buzón:</strong> {{ $mensaje->cuenta->aseguradora?->nombre }}</p>
        @else
            <label class="form-label" for="aseguradora_id">EPS / Aseguradora</label>
            <select class="form-select" id="aseguradora_id" name="aseguradora_id">
                <option value="">Sin EPS</option>
                @foreach($aseguradoras as $aseguradora)
                    <option value="{{ $aseguradora->id }}" @selected(old('aseguradora_id') == $aseguradora->id)>{{ $aseguradora->nombre }}</option>
                @endforeach
            </select>
        @endif

        <label class="form-label" for="descripcion">Descripción revisada</label>
        <textarea class="form-input" id="descripcion" name="descripcion" rows="8" required minlength="10" style="min-height:160px">{{ old('descripcion', trim(($mensaje->asunto ? $mensaje->asunto."\n\n" : '').($mensaje->cuerpo_texto ?? ''))) }}</textarea>

        <button class="btn btn-green" style="margin-top:14px" type="submit">Crear expediente</button>
    </form>
</div>
@endsection
