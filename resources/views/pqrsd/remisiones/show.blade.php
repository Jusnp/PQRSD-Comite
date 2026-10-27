@extends('layouts.pqrsd')

@section('title', 'Remisión — '.$pqrsd->radicado)
@section('heading', 'Remisión interna #'.$remision->id)
@section('subheading', $pqrsd->radicado.' · '.$remision->area?->nombre)

@section('content')
<a class="back-button" href="{{ route('pqrsd.show', $pqrsd) }}">← Volver al expediente</a>

@if(session('warning'))
    <div class="alert alert-warning" role="status">{{ session('warning') }}</div>
@endif

@if($alertaPlazo)
<div class="card card-pad" style="margin-top:18px;border:1px solid rgba(202,138,4,.45);background:rgba(250,204,21,.08)">
    <strong>Advertencia:</strong> la fecha límite interna es posterior al vencimiento oficial
    ({{ $pqrsd->fecha_limite?->timezone('America/Bogota')->format('d/m/Y H:i') }}).
</div>
@endif

<div class="form-grid" style="margin-top:18px">
    <div class="card card-pad">
        <h2 style="margin-top:0">Datos de la remisión</h2>
        <p><strong>Área:</strong> {{ $remision->area?->nombre }}</p>
        <p><strong>Estado:</strong> {{ $remision->etiquetaEstado() }}</p>
        <p><strong>Motivo:</strong> {{ $remision->motivo }}</p>
        <p><strong>Información solicitada:</strong> {{ $remision->informacion_solicitada }}</p>
        <p><strong>Responsable de seguimiento:</strong> {{ $remision->responsableSeguimiento?->name ?? '—' }}</p>
        <p><strong>Fecha límite interna:</strong> {{ $remision->fecha_limite_interna?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</p>
        <p><strong>Observaciones:</strong> {{ $remision->observaciones ?? '—' }}</p>
        <p><strong>Creada por:</strong> {{ $remision->creador?->name ?? '—' }}</p>
        <p class="muted">No reinicia SLA · Vencimiento oficial: {{ $pqrsd->fecha_limite?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</p>
    </div>
    <div class="card card-pad">
        <h2 style="margin-top:0">Seguimiento de envío / respuesta</h2>
        <p><strong>Fecha remisión:</strong> {{ $remision->fecha_remision?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</p>
        <p><strong>Medio de comunicación:</strong> {{ $remision->etiquetaMedio() }}</p>
        <p><strong>Evidencia de gestión:</strong> {{ $remision->evidencia_envio_detalle ?? '—' }}</p>
        <p><strong>Fecha respuesta área:</strong> {{ $remision->fecha_respuesta?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</p>
        <p><strong>Persona que respondió:</strong> {{ $remision->persona_responde ?? '—' }}</p>
        <p><strong>Contenido (información interna):</strong> {{ $remision->contenido_respuesta ?? '—' }}</p>
        <p><strong>Observaciones respuesta:</strong> {{ $remision->observaciones_respuesta ?? '—' }}</p>
        <p class="muted">Esta respuesta del área no es la respuesta oficial de PQRS al paciente/solicitante.</p>
    </div>
</div>

<div class="card card-pad" style="margin-top:18px">
    <h2 style="margin-top:0">Documentos de la remisión</h2>
    @if($remision->adjuntos->isNotEmpty())
        <ul>
            @foreach($remision->adjuntos as $adj)
                <li>
                    <a href="{{ route('pqrsd.adjuntos.download', [$pqrsd, $adj]) }}">{{ $adj->nombre_original }}</a>
                    <span class="muted">({{ $adj->tipo }})</span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="muted">Sin documentos vinculados a esta remisión.</p>
    @endif
</div>

<div class="card card-pad" style="margin-top:18px">
    <p class="muted" style="margin:0">
        Consulta histórica en solo lectura. Las remisiones a otras áreas ya no forman parte de la operación;
        no se pueden crear ni modificar solicitudes internas nuevas.
    </p>
</div>
@endsection
