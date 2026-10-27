@extends('layouts.pqrsd')
@section('title', $item->exists ? 'Editar área' : 'Nueva área')
@section('heading', $item->exists ? 'Editar área' : 'Nueva área')
@section('content')
<a class="back-button" href="{{ route('areas.index') }}">← Volver</a>
<form class="card card-pad" style="margin-top:18px" method="POST" action="{{ $item->exists ? route('areas.update', $item) : route('areas.store') }}">
@csrf
@if($item->exists) @method('PUT') @endif
<div class="form-grid">
<div><label class="form-label">Código</label><input class="form-input" name="codigo" value="{{ old('codigo', $item->codigo) }}" required></div>
<div><label class="form-label">Nombre</label><input class="form-input" name="nombre" value="{{ old('nombre', $item->nombre) }}" required></div>
<div><label class="form-label">Orden</label><input class="form-input" type="number" name="orden" value="{{ old('orden', $item->orden ?? 100) }}" min="0" max="9999"></div>
<div class="full"><label><input type="checkbox" name="activo" value="1" @checked(old('activo', $item->activo))> Activa</label></div>
</div>
<button class="btn btn-green" style="margin-top:16px" type="submit">Guardar</button>
</form>
@endsection
