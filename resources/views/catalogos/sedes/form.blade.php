@extends('layouts.pqrsd')
@section('title', $item->exists ? 'Editar sede' : 'Nueva sede')
@section('heading', $item->exists ? 'Editar sede' : 'Nueva sede')
@section('content')
<a class="back-button" href="{{ route('sedes.index') }}">← Volver</a>
<form class="card card-pad" style="margin-top:18px" method="POST" action="{{ $item->exists ? route('sedes.update', $item) : route('sedes.store') }}">
@csrf
@if($item->exists) @method('PUT') @endif
<div class="form-grid">
<div><label class="form-label">Código</label><input class="form-input" name="codigo" value="{{ old('codigo', $item->codigo) }}" required></div>
<div><label class="form-label">Nombre</label><input class="form-input" name="nombre" value="{{ old('nombre', $item->nombre) }}" required></div>
<div><label class="form-label">Ciudad</label><input class="form-input" name="ciudad" value="{{ old('ciudad', $item->ciudad) }}"></div>
<div><label class="form-label">Teléfono</label><input class="form-input" name="telefono" value="{{ old('telefono', $item->telefono) }}"></div>
<div class="full"><label class="form-label">Dirección</label><input class="form-input" name="direccion" value="{{ old('direccion', $item->direccion) }}"></div>
<div class="full"><label><input type="checkbox" name="activo" value="1" @checked(old('activo', $item->activo))> Activa</label></div>
</div>
<button class="btn btn-green" style="margin-top:16px" type="submit">Guardar</button>
</form>
@endsection
