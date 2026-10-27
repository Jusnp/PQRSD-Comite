@extends('layouts.pqrsd')
@section('title', 'Mi perfil')
@section('heading', 'Mi perfil')
@section('subheading', 'Datos del funcionario en PQRSD')
@section('retorno')
    @can('pqrsd.view')
        <a href="{{ route('pqrsd.index') }}">← Volver a la bandeja</a>
    @endcan
@endsection
@section('content')
<div class="card card-pad" style="margin-bottom:16px">
    @include('profile.partials.update-profile-information-form')
</div>

<div class="card card-pad">
    @include('profile.partials.delete-user-form')
</div>
@endsection
