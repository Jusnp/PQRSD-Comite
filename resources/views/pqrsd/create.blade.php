@extends('layouts.pqrsd')

@section('title', 'Nueva PQRSD')
@section('heading', 'Nueva PQRSD')
@section('subheading', 'Radicación manual sin exigir datos completos')

@section('content')
<a class="back-button" href="{{ route('pqrsd.index') }}">← Volver al listado</a>

<form method="POST" action="{{ route('pqrsd.store') }}" class="card card-pad" style="margin-top:18px">
    @csrf
    <div class="form-grid">
        <div>
            <label class="form-label">Fecha de recepción original</label>
            <input class="form-input" type="datetime-local" name="fecha_recepcion" value="{{ old('fecha_recepcion', now('America/Bogota')->format('Y-m-d\TH:i')) }}">
            <div class="form-help">Se preserva aunque la radicación sea posterior.</div>
        </div>
        <div>
            <label class="form-label">Canal de ingreso <span class="required">*</span></label>
            <select class="form-select" name="canal_ingreso_id" required>
                <option value="">Seleccione</option>
                @foreach ($canales as $canal)
                    <option value="{{ $canal->id }}" @selected(old('canal_ingreso_id') == $canal->id)>{{ $canal->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Tipo PQRSD</label>
            <select class="form-select" name="tipo_pqrsd_id">
                <option value="">Pendiente de clasificar</option>
                @foreach ($tipos as $tipo)
                    <option value="{{ $tipo->id }}" @selected(old('tipo_pqrsd_id') == $tipo->id)>{{ $tipo->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Motivo</label>
            <select class="form-select" name="motivo_pqrsd_id">
                <option value="">Sin motivo</option>
                @foreach ($motivos as $motivo)
                    <option value="{{ $motivo->id }}" @selected(old('motivo_pqrsd_id') == $motivo->id)>{{ $motivo->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Nivel de riesgo <span class="required">*</span></label>
            <select class="form-select" name="nivel_riesgo" required>
                @foreach (['pendiente','vital_menor','vital_general','priorizado','simple','ordinario'] as $riesgo)
                    <option value="{{ $riesgo }}" @selected(old('nivel_riesgo', 'pendiente') === $riesgo)>{{ str_replace('_',' ', $riesgo) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Sede</label>
            <select class="form-select" name="sede_id">
                <option value="">Sin sede</option>
                @foreach ($sedes as $sede)
                    <option value="{{ $sede->id }}" @selected(old('sede_id') == $sede->id)>{{ $sede->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">EPS / Aseguradora</label>
            <select class="form-select" name="aseguradora_id">
                <option value="">Sin aseguradora</option>
                @foreach ($aseguradoras as $aseg)
                    <option value="{{ $aseg->id }}" @selected(old('aseguradora_id') == $aseg->id)>{{ $aseg->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Responsable</label>
            <select class="form-select" name="responsable_id">
                <option value="">Sin asignar</option>
                @foreach ($responsables as $user)
                    <option value="{{ $user->id }}" @selected(old('responsable_id') == $user->id)>{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="full">
            <label style="display:flex;gap:8px;align-items:center;font-weight:700">
                <input type="checkbox" name="es_anonimo" value="1" @checked(old('es_anonimo'))>
                Solicitud anónima / datos incompletos permitidos
            </label>
        </div>
        <div>
            <label class="form-label">Nombre del solicitante</label>
            <input class="form-input" type="text" name="solicitante_nombre" value="{{ old('solicitante_nombre') }}">
        </div>
        <div>
            <label class="form-label">Documento solicitante</label>
            <div style="display:flex;gap:8px">
                <input class="form-input" style="max-width:90px" type="text" name="solicitante_tipo_documento" value="{{ old('solicitante_tipo_documento', 'CC') }}" placeholder="Tipo">
                <input class="form-input" type="text" name="solicitante_documento" value="{{ old('solicitante_documento') }}" placeholder="Número">
            </div>
        </div>
        <div>
            <label class="form-label">Correo solicitante</label>
            <input class="form-input" type="email" name="solicitante_email" value="{{ old('solicitante_email') }}">
        </div>
        <div>
            <label class="form-label">Teléfono solicitante</label>
            <input class="form-input" type="text" name="solicitante_telefono" value="{{ old('solicitante_telefono') }}">
        </div>
        <div>
            <label class="form-label">Nombre del paciente</label>
            <input class="form-input" type="text" name="paciente_nombre" value="{{ old('paciente_nombre') }}">
        </div>
        <div>
            <label class="form-label">Documento paciente</label>
            <div style="display:flex;gap:8px">
                <input class="form-input" style="max-width:90px" type="text" name="paciente_tipo_documento" value="{{ old('paciente_tipo_documento', 'CC') }}">
                <input class="form-input" type="text" name="paciente_documento" value="{{ old('paciente_documento') }}">
            </div>
        </div>
        <div>
            <label class="form-label" for="paciente_fecha_nacimiento">Fecha de nacimiento</label>
            <input class="form-input" id="paciente_fecha_nacimiento" type="date" name="paciente_fecha_nacimiento" max="{{ now('America/Bogota')->toDateString() }}" value="{{ old('paciente_fecha_nacimiento') }}">
            <div class="form-help">La edad se calcula automáticamente a la fecha de recepción (no se escribe a mano).</div>
        </div>
        <div>
            <label class="form-label" for="servicio_area_ref">Referencia sede / servicio / colaborador</label>
            <input class="form-input" id="servicio_area_ref" type="text" name="servicio_area" value="{{ old('servicio_area') }}" placeholder="Útil en felicitaciones y remisiones">
        </div>
        <div>
            <label class="form-label">Representante / acudiente</label>
            <input class="form-input" type="text" name="representante_nombre" value="{{ old('representante_nombre') }}">
        </div>
        <div class="full">
            <label class="form-label">Descripción de los hechos <span class="required">*</span></label>
            <textarea class="form-input" name="descripcion" rows="5" required style="min-height:120px">{{ old('descripcion') }}</textarea>
        </div>
        <div class="full">
            <label class="form-label">Observaciones internas</label>
            <textarea class="form-input" name="observaciones_internas" rows="3" style="min-height:90px">{{ old('observaciones_internas') }}</textarea>
        </div>
    </div>
    <div style="margin-top:20px;display:flex;gap:10px">
        <button class="btn btn-green" type="submit">Radicar PQRSD</button>
        <a class="btn btn-secondary" href="{{ route('pqrsd.index') }}">Cancelar</a>
    </div>
</form>
@endsection
