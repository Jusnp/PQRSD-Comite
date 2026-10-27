@extends('layouts.publico')
@section('title', 'Estado '.$caso->radicado)
@section('heading', $caso->radicado)
@section('subheading', config('identidad.nombre_corto').' — información autorizada para el titular')

@section('content')
<div class="card card-pad">
    <p><strong>Estado operativo:</strong> {{ str_replace('_',' ', $caso->estado_operativo) }}</p>
    <p><strong>Cumplimiento:</strong> <x-semaforo-sla :estado="$caso->estado_cumplimiento" /></p>
    <p><strong>Tipo:</strong> {{ $caso->tipo?->nombre ?? 'En clasificación' }}</p>
    <p><strong>Canal:</strong> {{ $caso->canal?->nombre }}</p>
    <p><strong>Sede:</strong> {{ $caso->sede?->nombre ?? '—' }}</p>
    <p><strong>Modalidad:</strong> {{ $caso->es_anonimo ? 'Sin revelar identidad' : 'Identificada' }}</p>
    <p><strong>Medio de respuesta:</strong>
        {{ config('pqrsd_publico.medios_respuesta.'.$caso->medio_respuesta, $caso->medio_respuesta ?? '—') }}
    </p>
    <p><strong>Recepción:</strong> {{ $caso->fecha_recepcion?->timezone('America/Bogota')->format('d/m/Y H:i') }}</p>
    <p><strong>Fecha límite:</strong> {{ $caso->fecha_limite?->timezone('America/Bogota')->format('d/m/Y H:i') ?? 'Pendiente de clasificación' }}</p>

    @if($respuestaPublica)
        <hr>
        <h2 style="margin-top:0;font-size:1.1rem">Respuesta puesta a su disposición</h2>
        <div class="alert alert-success" role="status">
            {{ $respuestaPublica }}
        </div>
        <p class="muted">
            Estado de respuesta: {{ str_replace('_', ' ', $caso->respuesta_estado ?? '—') }}.
            Consultar esta pantalla no equivale automáticamente a acuse de recibo formal si la normativa exige otro medio.
        </p>
    @else
        <p class="muted">Aún no hay una respuesta puesta a disposición en la consulta segura, o el caso sigue en gestión.</p>
    @endif

    <p class="muted">Por protección de datos no se muestran adjuntos, datos clínicos ni observaciones internas en la consulta pública.</p>
    <a class="btn btn-secondary" href="{{ route('publico.consulta') }}">Nueva consulta</a>
</div>
@endsection
