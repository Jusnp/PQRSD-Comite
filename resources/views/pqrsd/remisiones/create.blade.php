@extends('layouts.pqrsd')

@section('title', 'Solicitar información — '.$pqrsd->radicado)
@section('heading', 'Solicitar información a otra área')
@section('subheading', $pqrsd->radicado.' — registro manual (sin reiniciar SLA)')

@section('content')
<a class="back-button" href="{{ route('pqrsd.show', $pqrsd) }}#sec-remisiones">← Volver al expediente</a>

@if(in_array($pqrsd->nivel_riesgo, ['vital_menor','vital_general'], true))
<div class="card card-pad" style="margin-top:18px;border:1px solid rgba(220,38,38,.35);background:rgba(220,38,38,.06)">
    <strong>Riesgo vital.</strong> Conserve los procedimientos de atención inmediata. Esta solicitud no pausa ni modifica el vencimiento oficial.
</div>
@endif

<div class="card card-pad" style="margin-top:18px">
    <p class="muted">
        Vencimiento oficial del radicado:
        <strong>{{ $pqrsd->fecha_limite?->timezone('America/Bogota')->format('d/m/Y H:i') ?? 'Sin clasificar / sin plazo' }}</strong>.
        La fecha límite interna solo orienta el seguimiento del auxiliar. No envía correo automático.
    </p>
    <form method="POST" action="{{ route('pqrsd.remisiones.store', $pqrsd) }}" enctype="multipart/form-data" class="form-grid">
        @csrf
        <div class="full">
            <label class="form-label" for="area_institucional_id">Área</label>
            <select class="form-select" id="area_institucional_id" name="area_institucional_id" required>
                <option value="">Seleccione…</option>
                @foreach($areas as $area)
                    <option value="{{ $area->id }}" @selected(old('area_institucional_id') == $area->id)>{{ $area->nombre }}</option>
                @endforeach
            </select>
            @error('area_institucional_id')<div class="muted" style="color:#b91c1c">{{ $message }}</div>@enderror
        </div>
        <div class="full">
            <label class="form-label" for="motivo">Motivo o información requerida</label>
            <textarea class="form-input" id="motivo" name="motivo" rows="3" required minlength="5" maxlength="255" style="min-height:80px">{{ old('motivo') }}</textarea>
            @error('motivo')<div class="muted" style="color:#b91c1c">{{ $message }}</div>@enderror
        </div>
        <div>
            <label class="form-label" for="fecha_limite_interna">Fecha límite interna (opcional)</label>
            <input class="form-input" id="fecha_limite_interna" type="datetime-local" name="fecha_limite_interna" value="{{ old('fecha_limite_interna') }}"
                   data-vencimiento-oficial="{{ $pqrsd->fecha_limite?->timezone('America/Bogota')->format('Y-m-d\TH:i') }}">
            <p class="muted" id="alerta-plazo-interno" hidden style="color:#a16207;margin-top:6px">
                Advertencia: la fecha interna es posterior al vencimiento oficial de la PQRSD.
            </p>
        </div>
        <div class="full">
            <label class="form-label" for="observaciones">Observaciones (opcional)</label>
            <textarea class="form-input" id="observaciones" name="observaciones" rows="2" style="min-height:70px">{{ old('observaciones') }}</textarea>
        </div>
        <div class="full">
            <label class="form-label" for="adjuntos_remision">Documentos (opcional)</label>
            <input class="form-input" id="adjuntos_remision" type="file" name="adjuntos[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp">
        </div>
        <div class="full filter-actions">
            <button class="btn btn-green" type="submit">Registrar solicitud</button>
            <a class="btn btn-secondary" href="{{ route('pqrsd.show', $pqrsd) }}#sec-remisiones">Cancelar</a>
        </div>
    </form>
</div>
<script>
(function () {
    const input = document.getElementById('fecha_limite_interna');
    const alerta = document.getElementById('alerta-plazo-interno');
    const oficial = input?.dataset?.vencimientoOficial;
    if (!input || !alerta || !oficial) return;
    function check() {
        alerta.hidden = !(input.value && input.value > oficial);
    }
    input.addEventListener('change', check);
    check();
})();
</script>
@endsection
