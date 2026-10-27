@extends('layouts.pqrsd')
@section('title', $item->exists ? 'Editar motivo' : 'Nuevo motivo')
@section('heading', $item->exists ? 'Editar motivo' : 'Nuevo motivo')
@section('content')
<a class="back-button" href="{{ route('motivos.index') }}">← Volver</a>
<form class="card card-pad" style="margin-top:18px" method="POST" action="{{ $item->exists ? route('motivos.update', $item) : route('motivos.store') }}">
@csrf
@if($item->exists) @method('PUT') @endif
<div class="form-grid">
<div><label class="form-label">Tipo</label>
<select class="form-select" name="tipo_pqrsd_id" required>
@foreach($tipos as $tipo)
<option value="{{ $tipo->id }}" @selected(old('tipo_pqrsd_id', $item->tipo_pqrsd_id) == $tipo->id)>{{ $tipo->nombre }}</option>
@endforeach
</select></div>
<div><label class="form-label">Código</label><input class="form-input" name="codigo" value="{{ old('codigo', $item->codigo) }}" required></div>
<div class="full"><label class="form-label">Nombre</label><input class="form-input" name="nombre" value="{{ old('nombre', $item->nombre) }}" required></div>
<div class="full"><label class="form-label">Descripción</label><textarea class="form-input" name="descripcion" rows="3">{{ old('descripcion', $item->descripcion) }}</textarea></div>
<div class="full"><label><input type="checkbox" name="activo" value="1" @checked(old('activo', $item->activo))> Activo</label></div>
</div>
<button class="btn btn-green" style="margin-top:16px" type="submit">Guardar</button>
</form>
@endsection
