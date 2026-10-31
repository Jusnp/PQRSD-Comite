@extends('layouts.publico')
@section('title', 'Consulta de estado')
@section('heading', 'Estado de su PQRSD')
@section('subheading', 'Se necesita el código privado')

@section('content')
<div class="card card-pad">
    <p>
        Con ese número no es posible mostrar el estado.
        Si usted recibió un código privado de consulta, úselo en la consulta segura.
        Si no lo tiene, comuníquese con la institución.
    </p>
    <p class="muted" style="margin-bottom:0">
        Esta pantalla no muestra nombres, documentos, estado clínico, respuesta ni datos de otra solicitud.
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px">
        <a class="btn btn-secondary" href="{{ route('publico.consulta') }}">Nueva consulta</a>
        <a class="btn btn-secondary" href="{{ route('publico.consulta.segura') }}">Consulta segura</a>
    </div>
</div>
@endsection
