@extends('layouts.pqrsd')
@section('title', $item->exists ? 'Editar aseguradora' : 'Nueva aseguradora')
@section('heading', $item->exists ? 'Editar aseguradora' : 'Nueva aseguradora')
@section('content')
<a class="back-button" href="{{ route('aseguradoras.index') }}">← Volver</a>
<form class="card card-pad" style="margin-top:18px" method="POST" action="{{ $item->exists ? route('aseguradoras.update', $item) : route('aseguradoras.store') }}">
@csrf
@if($item->exists) @method('PUT') @endif
<div class="form-grid">
<div><label class="form-label">Código</label><input class="form-input" name="codigo" value="{{ old('codigo', $item->codigo) }}" required></div>
<div><label class="form-label">Nombre</label><input class="form-input" name="nombre" value="{{ old('nombre', $item->nombre) }}" required></div>
<div><label class="form-label">NIT</label><input class="form-input" name="nit" value="{{ old('nit', $item->nit) }}"></div>
<div><label class="form-label">Tipo</label>
<select class="form-select" name="tipo">
@foreach(['eps','arl','poliza','otra'] as $tipo)
<option value="{{ $tipo }}" @selected(old('tipo', $item->tipo) === $tipo)>{{ strtoupper($tipo) }}</option>
@endforeach
</select></div>
<div class="full"><label><input type="checkbox" name="activo" value="1" @checked(old('activo', $item->activo))> Activa</label></div>
</div>
<button class="btn btn-green" style="margin-top:16px" type="submit">Guardar</button>
</form>
@endsection
