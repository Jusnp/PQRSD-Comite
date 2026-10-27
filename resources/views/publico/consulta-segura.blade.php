@extends('layouts.publico')
@section('title', 'Consulta segura')
@section('heading', 'Consulta segura de respuesta')
@section('subheading', config('identidad.nombre_corto').' — requiere radicado y código privado')

@section('content')
<div class="card card-pad">
    <p class="muted" style="margin-top:0">
        Use esta opción para acceder al contenido privado de la respuesta cuando esté disponible.
        El código de consulta es personal; no lo comparta ni lo envíe en la URL.
    </p>
    <form method="POST" action="{{ route('publico.consulta.segura.store') }}" autocomplete="off">
        @csrf
        <div class="form-grid">
            <div>
                <label class="form-label" for="radicado">Radicado <span class="required">*</span></label>
                <input class="form-input @error('radicado') is-invalid @enderror" id="radicado" name="radicado" value="{{ old('radicado') }}" required placeholder="PQRSD-2026-000001" autocomplete="off">
                @error('radicado')
                    <div class="field-error" role="alert">{{ $message }}</div>
                @enderror
            </div>
            <div>
                <label class="form-label" for="token">Código de consulta <span class="required">*</span></label>
                <input class="form-input @error('token') is-invalid @enderror" id="token" name="token" type="password" value="{{ old('token') }}" required autocomplete="off" minlength="20">
                @error('token')
                    <div class="field-error" role="alert">{{ $message }}</div>
                @enderror
                <div class="form-help">Conserve radicado y código fuera del navegador. Solo con ambos puede ver la respuesta completa.</div>
            </div>
        </div>
        <button class="btn btn-green" style="margin-top:16px" type="submit">Consultar respuesta</button>
    </form>
    <p class="muted" style="margin-top:18px;margin-bottom:0">
        ¿Solo desea el estado general? Use la <a href="{{ route('publico.consulta') }}">consulta con número de radicado</a>.
    </p>
</div>
@endsection
