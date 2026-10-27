@extends('layouts.pqrsd')
@section('title', $item->exists ? 'Editar cuenta' : 'Nueva cuenta')
@section('heading', $item->exists ? 'Editar cuenta de correo' : 'Nueva cuenta de correo')
@section('content')
<a class="back-button" href="{{ route('cuentas-correo.index') }}">← Volver</a>
<form class="card card-pad" style="margin-top:18px" method="POST" action="{{ $item->exists ? route('cuentas-correo.update', $item) : route('cuentas-correo.store') }}">
@csrf
@if($item->exists) @method('PUT') @endif
<div class="form-grid">
<div><label class="form-label">Dirección</label><input class="form-input" type="email" name="direccion" value="{{ old('direccion', $item->direccion) }}" required></div>
<div><label class="form-label">Descripción</label><input class="form-input" name="descripcion" value="{{ old('descripcion', $item->descripcion) }}"></div>
<div><label class="form-label">Tipo de buzón (Workspace)</label>
<select class="form-select" name="tipo_buzon" required>
@foreach(['desconocido','individual','alias','grupo','reenvio','otro'] as $t)
<option value="{{ $t }}" @selected(old('tipo_buzon', $item->tipo_buzon ?: 'desconocido')===$t)>{{ $t }}</option>
@endforeach
</select>
<div class="form-help">No seleccionar individual/alias/grupo sin evidencia de Admin Console.</div>
</div>
<div><label class="form-label">Buzón canónico (si alias/reenvío/grupo)</label>
<input class="form-input" type="email" name="buzon_canonico" value="{{ old('buzon_canonico', $item->buzon_canonico) }}" placeholder="usuario-real@menteplena.com.co">
</div>
<div><label class="form-label">Responsable (nombre)</label><input class="form-input" name="responsable_nombre" value="{{ old('responsable_nombre', $item->responsable_nombre) }}"></div>
<div><label class="form-label">Área responsable</label><input class="form-input" name="area_responsable" value="{{ old('area_responsable', $item->area_responsable) }}"></div>
<div><label class="form-label">Aseguradora (opcional)</label>
<select class="form-select" name="aseguradora_id">
<option value="">—</option>
@foreach($aseguradoras as $a)
<option value="{{ $a->id }}" @selected(old('aseguradora_id', $item->aseguradora_id)==$a->id)>{{ $a->nombre }}</option>
@endforeach
</select></div>
<div><label class="form-label">Sede</label>
<select class="form-select" name="sede_id">
<option value="">—</option>
@foreach($sedes as $s)
<option value="{{ $s->id }}" @selected(old('sede_id', $item->sede_id)==$s->id)>{{ $s->nombre }}</option>
@endforeach
</select></div>
<div><label class="form-label">Estado autorización OAuth/API</label>
<select class="form-select" name="estado_autorizacion" required>
@foreach(['pendiente','autorizado','rechazado','no_aplica'] as $a)
<option value="{{ $a }}" @selected(old('estado_autorizacion', $item->estado_autorizacion ?: 'pendiente')===$a)>{{ $a }}</option>
@endforeach
</select></div>
<div class="full"><label class="form-label">Observaciones de inventario</label>
<textarea class="form-input" name="observaciones_inventario" rows="3" style="min-height:90px">{{ old('observaciones_inventario', $item->observaciones_inventario) }}</textarea>
</div>
<div><label class="form-label">Tipo de integración</label>
<select class="form-select" name="tipo_integracion" required>
@foreach(['mock','imap','microsoft_graph','google_api','central_forward'] as $t)
<option value="{{ $t }}" @selected(old('tipo_integracion', $item->tipo_integracion)===$t)>{{ $t }}</option>
@endforeach
</select></div>
<div class="full">
<label class="form-label">Referencia de credencial (env)</label>
<input class="form-input" name="credential_ref" value="{{ old('credential_ref', $item->credential_ref) }}" placeholder="MAIL_CRED_EJEMPLO" pattern="[A-Z][A-Z0-9_]*">
<div class="form-help">Solo el nombre de la variable. Preferir OAuth cifrado; nunca pegue secretos aquí.</div>
</div>
<div><label class="form-label">Host IMAP/API</label><input class="form-input" name="host" value="{{ old('host', $item->host) }}" placeholder="Pendiente de TI"></div>
<div><label class="form-label">Puerto</label><input class="form-input" type="number" name="puerto" value="{{ old('puerto', $item->puerto) }}"></div>
<div><label class="form-label">Cifrado</label>
<select class="form-select" name="cifrado">
<option value="">—</option>
@foreach(['ssl','tls','starttls'] as $c)
<option value="{{ $c }}" @selected(old('cifrado', $item->cifrado)===$c)>{{ $c }}</option>
@endforeach
</select></div>
<div><label><input type="checkbox" name="activo" value="1" @checked(old('activo', $item->activo))> Activa</label></div>
<div><label><input type="checkbox" name="es_buzon_central" value="1" @checked(old('es_buzon_central', $item->es_buzon_central))> Buzón central (reenvíos)</label></div>
</div>
<button class="btn btn-green" style="margin-top:16px" type="submit">Guardar</button>
</form>
@endsection
