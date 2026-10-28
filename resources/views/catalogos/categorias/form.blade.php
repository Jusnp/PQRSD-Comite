@extends('layouts.pqrsd')
@section('title', $item->exists ? 'Editar categoría' : 'Nueva categoría')
@section('heading', $item->exists ? 'Editar categoría' : 'Nueva categoría')
@section('content')
<a class="back-button" href="{{ route('categorias.index') }}">← Volver</a>
<form class="card card-pad" style="margin-top:18px" method="POST" action="{{ $item->exists ? route('categorias.update', $item) : route('categorias.store') }}">
@csrf
@if($item->exists) @method('PUT') @endif
<div class="form-grid">
<div><label class="form-label">Código</label><input class="form-input" name="codigo" value="{{ old('codigo', $item->codigo) }}" required></div>
<div><label class="form-label">Orden</label><input class="form-input" type="number" name="orden" value="{{ old('orden', $item->orden ?? 0) }}" min="0"></div>
<div class="full"><label class="form-label">Nombre</label><input class="form-input" name="nombre" value="{{ old('nombre', $item->nombre) }}" required></div>
<div class="full"><label class="form-label">Descripción</label><textarea class="form-input" name="descripcion" rows="2">{{ old('descripcion', $item->descripcion) }}</textarea></div>
<div class="full"><label><input type="checkbox" name="activo" value="1" @checked(old('activo', $item->activo ?? true))> Activa</label></div>
</div>
<h2 style="margin-top:22px">Subcategorías</h2>
<p class="muted">Deje vacías las filas que no use. Desmarcar «Activa» conserva el historial sin ofrecerla en formularios nuevos.</p>
@php
    $existentes = $item->exists ? $item->subcategorias : collect();
    $filas = old('subcategorias', $existentes->map(fn ($s) => ['id' => $s->id, 'codigo' => $s->codigo, 'nombre' => $s->nombre, 'activo' => $s->activo])->all());
    $filas = array_values($filas);
    while (count($filas) < 3) { $filas[] = ['id' => '', 'codigo' => '', 'nombre' => '', 'activo' => true]; }
@endphp
@foreach($filas as $i => $fila)
<div class="form-grid" style="margin-top:10px">
<input type="hidden" name="subcategorias[{{ $i }}][id]" value="{{ $fila['id'] ?? '' }}">
<div><label class="form-label">Código</label><input class="form-input" name="subcategorias[{{ $i }}][codigo]" value="{{ $fila['codigo'] ?? '' }}"></div>
<div><label class="form-label">Nombre</label><input class="form-input" name="subcategorias[{{ $i }}][nombre]" value="{{ $fila['nombre'] ?? '' }}"></div>
<div><label class="form-label">Activa</label><input type="checkbox" name="subcategorias[{{ $i }}][activo]" value="1" @checked($fila['activo'] ?? true)></div>
</div>
@endforeach
<button class="btn btn-green" style="margin-top:16px" type="submit">Guardar</button>
</form>
@endsection
