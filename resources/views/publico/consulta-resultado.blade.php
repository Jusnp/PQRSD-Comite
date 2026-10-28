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
        {{ \App\Support\MedioRespuesta::etiqueta($caso->medio_respuesta) }}
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

    @if($caso->estado_operativo === 'cerrado')
        <hr>
        <h2 style="font-size:1.1rem">Valoración de la atención</h2>
        @if($caso->encuesta)
            <p>Ya registró su valoración. Gracias.</p>
        @else
            <form method="POST" action="{{ route('publico.encuesta') }}">
                @csrf
                <input type="hidden" name="radicado" value="{{ $caso->radicado }}">
                <input type="hidden" name="token" value="{{ $tokenConsulta }}">
                <label class="form-label" for="puntuacion">¿Qué tan satisfecho quedó con la atención de su solicitud?</label>
                <select class="form-select" id="puntuacion" name="puntuacion" required>
                    <option value="">Seleccione</option>
                    <option value="1">1. Muy insatisfecho</option>
                    <option value="2">2. Insatisfecho</option>
                    <option value="3">3. Neutral</option>
                    <option value="4">4. Satisfecho</option>
                    <option value="5">5. Muy satisfecho</option>
                </select>
                <label class="form-label" for="atendida">¿Considera que su solicitud fue atendida adecuadamente?</label>
                <select class="form-select" id="atendida" name="atendida" required>
                    <option value="">Seleccione</option>
                    <option value="si">Sí</option>
                    <option value="parcialmente">Parcialmente</option>
                    <option value="no">No</option>
                </select>
                <label class="form-label" for="comentario">¿Desea dejarnos un comentario?</label>
                <textarea class="form-input" id="comentario" name="comentario" rows="3" maxlength="1000"></textarea>
                <button class="btn btn-green" style="margin-top:12px" type="submit">Enviar valoración</button>
            </form>
        @endif
    @endif

    <p class="muted">Por protección de datos no se muestran adjuntos, teléfono, documentos, datos clínicos ni observaciones internas en la consulta pública.</p>
    <a class="btn btn-secondary" href="{{ route('publico.consulta') }}">Nueva consulta</a>
</div>
@endsection
