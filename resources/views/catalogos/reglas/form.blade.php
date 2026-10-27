@extends('layouts.pqrsd')
@section('title', $item->exists ? 'Editar regla SLA' : 'Nueva regla SLA')
@section('heading', $item->exists ? 'Editar regla SLA' : 'Nueva regla SLA')
@section('content')
<a class="back-button" href="{{ route('reglas.index') }}">← Volver</a>
<form class="card card-pad" style="margin-top:18px" method="POST" action="{{ $item->exists ? route('reglas.update', $item) : route('reglas.store') }}">
@csrf
@if($item->exists) @method('PUT') @endif
<div class="form-grid">
<div><label class="form-label">Código</label><input class="form-input" name="codigo" value="{{ old('codigo', $item->codigo) }}" required></div>
<div><label class="form-label">Nombre</label><input class="form-input" name="nombre" value="{{ old('nombre', $item->nombre) }}" required></div>
<div><label class="form-label">Nivel de riesgo</label>
<select class="form-select" name="nivel_riesgo" required>
@foreach(['vital_menor','vital_general','priorizado','simple','ordinario'] as $riesgo)
<option value="{{ $riesgo }}" @selected(old('nivel_riesgo', $item->nivel_riesgo) === $riesgo)>{{ str_replace('_',' ', $riesgo) }}</option>
@endforeach
</select></div>
<div><label class="form-label">Cantidad</label><input class="form-input" type="number" min="1" name="cantidad" value="{{ old('cantidad', $item->cantidad) }}" required></div>
<div><label class="form-label">Unidad</label>
<select class="form-select" name="unidad" required>
@foreach(['horas_calendario','dias_calendario','dias_habiles'] as $unidad)
<option value="{{ $unidad }}" @selected(old('unidad', $item->unidad) === $unidad)>{{ str_replace('_',' ', $unidad) }}</option>
@endforeach
</select></div>
<div><label class="form-label">Prioridad</label><input class="form-input" type="number" min="1" name="prioridad" value="{{ old('prioridad', $item->prioridad ?? 100) }}" required></div>
<div class="full"><label class="form-label">Descripción</label><textarea class="form-input" name="descripcion" rows="3">{{ old('descripcion', $item->descripcion) }}</textarea></div>
@if($item->exists)
<div class="full"><label class="form-label">Motivo de la nueva versión</label><input class="form-input" name="motivo_version" value="{{ old('motivo_version') }}" placeholder="Ej. Ajuste por validación jurídica"></div>
@endif
<div class="full"><label><input type="checkbox" name="activo" value="1" @checked(old('activo', $item->activo))> Activa</label></div>
</div>
<button class="btn btn-green" style="margin-top:16px" type="submit">Guardar</button>
</form>
@endsection
