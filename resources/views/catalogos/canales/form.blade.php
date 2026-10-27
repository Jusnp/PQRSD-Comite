@extends('layouts.pqrsd')
@section('title', $item->exists ? 'Editar canal' : 'Nuevo canal')
@section('heading', $item->exists ? 'Editar canal' : 'Nuevo canal')
@section('content')
<a class="back-button" href="{{ route('canales.index') }}">← Volver</a>
<form class="card card-pad" style="margin-top:18px" method="POST" action="{{ $item->exists ? route('canales.update', $item) : route('canales.store') }}">
@csrf
@if($item->exists) @method('PUT') @endif
<div class="form-grid">
<div><label class="form-label">Código</label><input class="form-input" name="codigo" value="{{ old('codigo', $item->codigo) }}" required></div>
<div><label class="form-label">Nombre</label><input class="form-input" name="nombre" value="{{ old('nombre', $item->nombre) }}" required></div>
<div class="full"><label class="form-label">Descripción</label><textarea class="form-input" name="descripcion" rows="3">{{ old('descripcion', $item->descripcion) }}</textarea></div>
<div><label><input type="checkbox" name="requiere_digitalizacion" value="1" @checked(old('requiere_digitalizacion', $item->requiere_digitalizacion))> Requiere digitalización</label></div>
<div><label><input type="checkbox" name="activo" value="1" @checked(old('activo', $item->activo))> Activo</label></div>
</div>
<button class="btn btn-green" style="margin-top:16px" type="submit">Guardar</button>
</form>
@endsection
