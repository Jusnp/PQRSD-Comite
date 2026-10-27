@extends('layouts.publico')
@section('title', 'Consultar estado')
@section('heading', 'Consultar estado de PQRSD')
@section('subheading', config('identidad.nombre_corto').' — solo necesita su número de radicado')

@section('content')
<div class="card card-pad">
    <p class="muted" style="margin-top:0">
        Ingrese el número de radicado para conocer el estado general de su solicitud
        (Recibida, En trámite o Finalizada). Esta consulta no muestra datos personales ni la respuesta completa.
    </p>
    <form method="POST" action="{{ route('publico.consulta.store') }}" autocomplete="off">
        @csrf
        <div class="form-grid">
            <div class="full">
                <label class="form-label" for="radicado">Número de radicado <span class="required">*</span></label>
                <input class="form-input @error('radicado') is-invalid @enderror" id="radicado" name="radicado" value="{{ old('radicado') }}" required placeholder="PQRSD-2026-000001" autocomplete="off">
                @error('radicado')
                    <div class="field-error" role="alert">{{ $message }}</div>
                @enderror
            </div>
        </div>
        <button class="btn btn-green" style="margin-top:16px" type="submit">Consultar</button>
    </form>
    <p class="muted" style="margin-top:18px;margin-bottom:0">
        Si eligió <strong>consulta segura</strong> y desea ver la respuesta completa cuando esté disponible,
        use la <a href="{{ route('publico.consulta.segura') }}">consulta segura con código privado</a>.
    </p>
</div>
@endsection
