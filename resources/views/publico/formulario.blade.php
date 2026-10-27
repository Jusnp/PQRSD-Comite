@extends('layouts.publico')
@section('title', 'Radicar PQRSD')
@section('heading', config('identidad.publico_encabezado'))
@section('subheading', config('identidad.nombre_corto').' — este canal no reemplaza los servicios de urgencias')

@section('content')
@php
    $oldQuien = old('quien_radica', '');
@endphp
<div class="alert alert-warning" role="alert">
    <strong>Urgencia vital:</strong> si usted o un tercero enfrenta un riesgo inmediato para la vida o la salud,
    acuda a urgencias o llame a la línea de emergencias. No use este formulario como sustituto de atención médica urgente.
</div>

<form method="POST" action="{{ route('publico.store') }}" class="card card-pad" enctype="multipart/form-data" novalidate id="form-publico-pqrsd">
    @csrf
    <input type="hidden" name="via_qr" value="{{ $viaQr ? 1 : 0 }}">
    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
    <div class="visually-hidden" aria-hidden="true">
        <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    <fieldset class="form-section">
        <legend>A. ¿Quién presenta la solicitud?</legend>
        <div class="form-grid">
            <div class="full opciones-quien">
                <label class="opcion-radio">
                    <input type="radio" name="quien_radica" value="paciente" id="quien_paciente" @checked($oldQuien === 'paciente')>
                    <span>Soy el paciente</span>
                </label>
                <label class="opcion-radio">
                    <input type="radio" name="quien_radica" value="tercero" id="quien_tercero" @checked($oldQuien === 'tercero')>
                    <span>Presento esta solicitud en nombre de otra persona</span>
                </label>
                @error('quien_radica')
                    <div class="field-error" role="alert">{{ $message }}</div>
                @enderror
            </div>
            <div class="full">
                <label style="display:flex;gap:8px;align-items:flex-start;font-weight:700">
                    <input type="checkbox" name="es_anonimo" value="1" id="es_anonimo" @checked(old('es_anonimo'))>
                    <span>Deseo presentar esta solicitud sin revelar mi identidad.</span>
                </label>
                <p class="form-help" id="texto-anonimo" hidden>
                    Puede presentar su solicitud sin revelar su identidad. Puede indicar un medio de contacto para recibir la respuesta.
                    El anonimato aplica especialmente a quejas y denuncias; otros tipos quedan sujetos a validación institucional.
                </p>
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section" id="sec-solicitante">
        <legend id="legend-solicitante">B. Sus datos</legend>
        <div class="form-grid">
            <div class="campo-identidad">
                <label class="form-label" for="solicitante_nombre">Nombre completo <span class="required ident-req">*</span></label>
                <input class="form-input @error('solicitante_nombre') is-invalid @enderror" id="solicitante_nombre" name="solicitante_nombre" value="{{ old('solicitante_nombre') }}" autocomplete="name">
                @error('solicitante_nombre')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="campo-identidad">
                <label class="form-label" for="solicitante_tipo_documento">Tipo de documento <span class="required ident-req">*</span></label>
                <select class="form-select @error('solicitante_tipo_documento') is-invalid @enderror" id="solicitante_tipo_documento" name="solicitante_tipo_documento">
                    <option value="">Seleccione</option>
                    @foreach($tiposDocumento as $codigo => $etiqueta)
                        <option value="{{ $codigo }}" @selected(old('solicitante_tipo_documento') === $codigo)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
                @error('solicitante_tipo_documento')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="campo-identidad">
                <label class="form-label" for="solicitante_documento">Número de documento <span class="required ident-req">*</span></label>
                <input class="form-input @error('solicitante_documento') is-invalid @enderror" id="solicitante_documento" name="solicitante_documento" value="{{ old('solicitante_documento') }}" autocomplete="off">
                @error('solicitante_documento')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div id="bloque-nacimiento-paciente-propio" @if($oldQuien !== 'paciente') hidden @endif>
                <label class="form-label" for="paciente_fecha_nacimiento">Fecha de nacimiento</label>
                <input class="form-input" id="paciente_fecha_nacimiento" type="date" name="paciente_fecha_nacimiento" max="{{ now('America/Bogota')->toDateString() }}" value="{{ old('paciente_fecha_nacimiento') }}">
                <div class="form-help">La edad se calcula automáticamente.</div>
            </div>
            <div class="campo-tercero full" hidden>
                <label class="form-label" for="relacion_paciente">Relación con el paciente <span class="required">*</span></label>
                <input class="form-input @error('relacion_paciente') is-invalid @enderror" id="relacion_paciente" name="relacion_paciente" value="{{ old('relacion_paciente') }}" placeholder="Ej. acudiente, familiar, representante">
                @error('relacion_paciente')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="solicitante_telefono">Teléfono (complementario)</label>
                <input class="form-input @error('solicitante_telefono') is-invalid @enderror" id="solicitante_telefono" name="solicitante_telefono" value="{{ old('solicitante_telefono') }}" autocomplete="tel" inputmode="tel" placeholder="Ej. 3001234567">
                @error('solicitante_telefono')<div class="field-error">{{ $message }}</div>@enderror
                <div class="form-help">Solo números y símbolos telefónicos. No se enviará información clínica sensible por SMS.</div>
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section campo-tercero" id="sec-paciente" hidden>
        <legend>C. Datos del paciente</legend>
        <div class="form-grid">
            <div>
                <label class="form-label" for="paciente_nombre">Nombre del paciente <span class="required">*</span></label>
                <input class="form-input @error('paciente_nombre') is-invalid @enderror" id="paciente_nombre" name="paciente_nombre" value="{{ old('paciente_nombre') }}">
                @error('paciente_nombre')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="paciente_tipo_documento">Tipo de documento <span class="required">*</span></label>
                <select class="form-select @error('paciente_tipo_documento') is-invalid @enderror" id="paciente_tipo_documento" name="paciente_tipo_documento">
                    <option value="">Seleccione</option>
                    @foreach($tiposDocumento as $codigo => $etiqueta)
                        <option value="{{ $codigo }}" @selected(old('paciente_tipo_documento') === $codigo)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
                @error('paciente_tipo_documento')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="paciente_documento">Número de documento <span class="required">*</span></label>
                <input class="form-input @error('paciente_documento') is-invalid @enderror" id="paciente_documento" name="paciente_documento" value="{{ old('paciente_documento') }}">
                @error('paciente_documento')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="paciente_fecha_nacimiento_tercero">Fecha de nacimiento</label>
                <input class="form-input" id="paciente_fecha_nacimiento_tercero" type="date" max="{{ now('America/Bogota')->toDateString() }}" value="{{ old('paciente_fecha_nacimiento') }}">
                <div class="form-help">Se usa el mismo campo de fecha de nacimiento del formulario.</div>
            </div>
            <div>
                <label class="form-label" for="aseguradora_id">EPS / Aseguradora</label>
                <select class="form-select" id="aseguradora_id">
                    <option value="">No aplica / no sé</option>
                    @foreach($aseguradoras as $a)
                        <option value="{{ $a->id }}" @selected(old('aseguradora_id')==$a->id)>{{ $a->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label" for="sede_id_paciente">Sede</label>
                <select class="form-select" id="sede_id_paciente">
                    <option value="">No aplica / no sé</option>
                    @foreach($sedes as $sede)
                        <option value="{{ $sede->id }}" @selected(old('sede_id')==$sede->id)>{{ $sede->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="full">
                <label class="form-label" for="servicio_area">Servicio relacionado</label>
                <input class="form-input" id="servicio_area" value="{{ old('servicio_area') }}">
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section" id="sec-contexto-paciente-propio" @if($oldQuien !== 'paciente') hidden @endif>
        <legend>C. Contexto de la atención</legend>
        <div class="form-grid">
            <div>
                <label class="form-label" for="aseguradora_id_propio">EPS / Aseguradora</label>
                <select class="form-select" id="aseguradora_id_propio" name="aseguradora_id_propio_ui">
                    <option value="">No aplica / no sé</option>
                    @foreach($aseguradoras as $a)
                        <option value="{{ $a->id }}" @selected(old('aseguradora_id')==$a->id)>{{ $a->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label" for="sede_id_propio">Sede</label>
                <select class="form-select" id="sede_id_propio" name="sede_id_propio_ui">
                    <option value="">No aplica / no sé</option>
                    @foreach($sedes as $sede)
                        <option value="{{ $sede->id }}" @selected(old('sede_id')==$sede->id)>{{ $sede->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="full">
                <label class="form-label" for="servicio_area_propio">Servicio relacionado</label>
                <input class="form-input" id="servicio_area_propio" name="servicio_area_propio_ui" value="{{ old('servicio_area') }}">
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend>D. Manifestación</legend>
        <div class="form-grid">
            <div>
                <label class="form-label" for="tipo_pqrsd_id">Tipo de solicitud <span class="required">*</span></label>
                <select class="form-select @error('tipo_pqrsd_id') is-invalid @enderror" id="tipo_pqrsd_id" name="tipo_pqrsd_id" required>
                    <option value="">Seleccione</option>
                    @foreach($tipos as $tipo)
                        <option value="{{ $tipo->id }}" data-codigo="{{ $tipo->codigo }}" @selected(old('tipo_pqrsd_id')==$tipo->id)>{{ $tipo->nombre }}</option>
                    @endforeach
                </select>
                @error('tipo_pqrsd_id')<div class="field-error">{{ $message }}</div>@enderror
                <div class="form-help">El riesgo clínico lo clasifica el personal autorizado. Este formulario no modifica reglas SLA.</div>
            </div>
            <div>
                <label class="form-label" for="asunto">Asunto <span class="required">*</span></label>
                <input class="form-input @error('asunto') is-invalid @enderror" id="asunto" name="asunto" value="{{ old('asunto') }}" maxlength="255" required minlength="5">
                @error('asunto')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="fecha_hechos">Fecha de los hechos (si se conoce)</label>
                <input class="form-input" id="fecha_hechos" type="date" name="fecha_hechos" max="{{ now('America/Bogota')->toDateString() }}" value="{{ old('fecha_hechos') }}">
            </div>
            <div class="full">
                <label class="form-label" for="descripcion">Descripción de lo sucedido <span class="required">*</span></label>
                <textarea class="form-input @error('descripcion') is-invalid @enderror" id="descripcion" name="descripcion" rows="6" required minlength="20" style="min-height:140px">{{ old('descripcion') }}</textarea>
                @error('descripcion')<div class="field-error">{{ $message }}</div>@enderror
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend>E. ¿Cómo desea recibir o consultar la respuesta?</legend>
        <div class="form-grid">
            <div class="full">
                <label class="form-label" for="medio_respuesta">Medio de respuesta <span class="required">*</span></label>
                <select class="form-select @error('medio_respuesta') is-invalid @enderror" id="medio_respuesta" name="medio_respuesta" required>
                    <option value="">Seleccione</option>
                    @foreach($mediosRespuesta as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected(old('medio_respuesta')===$valor)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
                @error('medio_respuesta')<div class="field-error">{{ $message }}</div>@enderror
                <div class="form-help">Este medio aplica a toda la solicitud. El teléfono es solo complementario.</div>
            </div>
            <div id="bloque-email" class="full" hidden>
                <label class="form-label" for="solicitante_email">Correo electrónico <span class="required">*</span></label>
                <input class="form-input @error('solicitante_email') is-invalid @enderror" id="solicitante_email" type="email" name="solicitante_email" value="{{ old('solicitante_email') }}" autocomplete="email">
                @error('solicitante_email')<div class="field-error">{{ $message }}</div>@enderror
                <label style="display:flex;gap:8px;align-items:flex-start;font-weight:700;margin-top:10px">
                    <input type="checkbox" name="consentimiento_comunicacion_email" value="1" @checked(old('consentimiento_comunicacion_email'))>
                    <span>Autorizo el uso de este correo para comunicar la gestión de mi PQRSD. No hay envío automático por Gmail en esta fase.</span>
                </label>
                @error('consentimiento_comunicacion_email')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div id="bloque-direccion" class="full" hidden>
                <label class="form-label" for="solicitante_direccion">Dirección de correspondencia física <span class="required">*</span></label>
                <input class="form-input @error('solicitante_direccion') is-invalid @enderror" id="solicitante_direccion" name="solicitante_direccion" value="{{ old('solicitante_direccion') }}" maxlength="500">
                @error('solicitante_direccion')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div id="bloque-consulta-segura" class="full" hidden>
                <p class="form-help" style="margin:0">
                    Al radicar recibirá un número de radicado para consultar el estado general.
                    Además se le entregará un código privado para acceder a la respuesta completa cuando esté disponible.
                    El radicado por sí solo no abre el contenido privado.
                </p>
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend>F. Documentos de soporte</legend>
        <p class="muted" style="margin-top:0">
            Puede adjuntar hasta {{ $adjuntosConfig['max_files'] ?? 5 }} archivos
            (máx. {{ number_format(($adjuntosConfig['max_kb'] ?? 10240) / 1024, 0) }} MB c/u):
            PDF, Word (.doc, .docx) e imágenes (.jpg, .jpeg, .png, .webp).
        </p>
        <div>
            <label class="form-label" for="adjuntos">Seleccionar archivos</label>
            <input class="form-input" type="file" id="adjuntos" name="adjuntos[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp,application/pdf,image/*" multiple>
        </div>
        <div id="adjuntos-lista" class="adjuntos-lista" aria-live="polite"></div>
    </fieldset>

    <fieldset class="form-section">
        <legend>Tratamiento de datos</legend>
        <label style="display:flex;gap:8px;align-items:flex-start;font-weight:700">
            <input type="checkbox" name="acepto_tratamiento_datos" value="1" required @checked(old('acepto_tratamiento_datos'))>
            <span>{{ config('identidad.textos.tratamiento_datos') }} <span class="required">*</span></span>
        </label>
        @error('acepto_tratamiento_datos')<div class="field-error">{{ $message }}</div>@enderror
    </fieldset>

    {{-- Campos canónicos sincronizados por JS (sede/aseguradora/servicio/fecha) --}}
    <input type="hidden" name="aseguradora_id" id="aseguradora_id_hidden" value="{{ old('aseguradora_id') }}">
    <input type="hidden" name="sede_id" id="sede_id_hidden" value="{{ old('sede_id') }}">
    <input type="hidden" name="servicio_area" id="servicio_area_hidden" value="{{ old('servicio_area') }}">

    <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-green" type="submit" id="btn-enviar-publico">Enviar solicitud</button>
        <a class="btn btn-secondary" href="{{ route('publico.consulta') }}">Ya tengo un radicado</a>
    </div>
</form>
@endsection

@push('styles')
<style>
.form-section{border:0;margin:0 0 22px;padding:0}
.form-section legend{font-weight:800;font-size:1.05rem;margin-bottom:12px;padding:0;color:var(--text-primary)}
.adjuntos-lista{margin-top:12px;display:grid;gap:8px}
.adjunto-item{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--surface-2, rgba(0,0,0,.03));font-size:14px}
.adjunto-item button{margin-left:auto}
.opciones-quien{display:grid;gap:10px}
.opcion-radio{display:flex;gap:10px;align-items:flex-start;font-weight:700;padding:10px 12px;border:1px solid var(--border);border-radius:8px;cursor:pointer}
.field-error{color:var(--red,#b91c1c);font-size:13px;margin-top:6px;font-weight:600}
.is-invalid{border-color:var(--red,#b91c1c)!important}
</style>
@endpush

@push('scripts')
<script>
(function () {
    const anon = document.getElementById('es_anonimo');
    const textoAnon = document.getElementById('texto-anonimo');
    const medio = document.getElementById('medio_respuesta');
    const bloqueEmail = document.getElementById('bloque-email');
    const bloqueDir = document.getElementById('bloque-direccion');
    const bloqueConsulta = document.getElementById('bloque-consulta-segura');
    const input = document.getElementById('adjuntos');
    const lista = document.getElementById('adjuntos-lista');
    const form = document.getElementById('form-publico-pqrsd');
    const btn = document.getElementById('btn-enviar-publico');
    const maxFiles = {{ (int) ($adjuntosConfig['max_files'] ?? 5) }};
    const maxBytes = {{ (int) ($adjuntosConfig['max_kb'] ?? 10240) }} * 1024;
    let files = [];

    const fnNac = document.getElementById('paciente_fecha_nacimiento');
    const fnNacT = document.getElementById('paciente_fecha_nacimiento_tercero');
    fnNacT?.addEventListener('change', () => { if (fnNac) fnNac.value = fnNacT.value; });
    fnNac?.addEventListener('change', () => { if (fnNacT) fnNacT.value = fnNac.value; });

    function syncQuien() {
        const quien = document.querySelector('input[name="quien_radica"]:checked')?.value || '';
        const tercero = quien === 'tercero';
        const paciente = quien === 'paciente';
        document.querySelectorAll('.campo-tercero').forEach(el => { el.hidden = !tercero; });
        const ctx = document.getElementById('sec-contexto-paciente-propio');
        const nacPropio = document.getElementById('bloque-nacimiento-paciente-propio');
        if (ctx) ctx.hidden = !paciente;
        if (nacPropio) nacPropio.hidden = !paciente;
        const legend = document.getElementById('legend-solicitante');
        if (legend) legend.textContent = tercero ? 'B. Datos de quien presenta' : 'B. Sus datos';
        syncContextoCampos();
    }

    function syncIdentidad() {
        const on = !!anon?.checked;
        textoAnon.hidden = !on;
        document.querySelectorAll('.campo-identidad').forEach(el => {
            el.hidden = on;
            // No borrar valores al alternar: solo ocultar y quitar required visual.
        });
        document.querySelectorAll('.ident-req').forEach(el => { el.hidden = on; });
    }

    function syncMedio() {
        const v = medio?.value || '';
        bloqueEmail.hidden = v !== 'email';
        bloqueDir.hidden = v !== 'correspondencia';
        bloqueConsulta.hidden = v !== 'consulta_segura';
    }

    function syncContextoCampos() {
        const quien = document.querySelector('input[name="quien_radica"]:checked')?.value || '';
        const asegH = document.getElementById('aseguradora_id_hidden');
        const sedeH = document.getElementById('sede_id_hidden');
        const servH = document.getElementById('servicio_area_hidden');
        if (quien === 'tercero') {
            asegH.value = document.getElementById('aseguradora_id')?.value || '';
            sedeH.value = document.getElementById('sede_id_paciente')?.value || '';
            servH.value = document.getElementById('servicio_area')?.value || '';
        } else {
            asegH.value = document.getElementById('aseguradora_id_propio')?.value || '';
            sedeH.value = document.getElementById('sede_id_propio')?.value || '';
            servH.value = document.getElementById('servicio_area_propio')?.value || '';
        }
    }

    ['aseguradora_id', 'sede_id_paciente', 'servicio_area', 'aseguradora_id_propio', 'sede_id_propio', 'servicio_area_propio']
        .forEach(id => document.getElementById(id)?.addEventListener('change', syncContextoCampos));
    ['aseguradora_id_propio', 'sede_id_propio', 'servicio_area_propio']
        .forEach(id => document.getElementById(id)?.addEventListener('input', syncContextoCampos));

    document.querySelectorAll('input[name="quien_radica"]').forEach(r => r.addEventListener('change', syncQuien));
    anon?.addEventListener('change', syncIdentidad);
    medio?.addEventListener('change', syncMedio);
    syncQuien();
    syncIdentidad();
    syncMedio();

    function formatSize(n) {
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1048576).toFixed(1) + ' MB';
    }

    let aplicandoLista = false;

    function firmaLista(lista) {
        return Array.from(lista).map((archivo) => [
            archivo.name,
            archivo.size,
            archivo.lastModified,
            archivo.type || '',
        ].join('\0')).join('\n');
    }

    function syncInput() {
        const dt = new DataTransfer();
        files.forEach(f => dt.items.add(f));
        aplicandoLista = true;
        try {
            input.files = dt.files;
        } finally {
            aplicandoLista = false;
        }
        render();
    }

    function render() {
        lista.innerHTML = '';
        if (!files.length) {
            lista.innerHTML = '<p class="muted" style="margin:0">Ningún archivo seleccionado.</p>';
            return;
        }
        files.forEach((f, i) => {
            const row = document.createElement('div');
            row.className = 'adjunto-item';
            row.innerHTML =
                '<span><strong>' + f.name.replace(/[<>&]/g, '') + '</strong></span>' +
                '<span class="muted">' + formatSize(f.size) + '</span>' +
                '<span class="muted">' + (f.type || 'archivo') + '</span>' +
                '<span class="badge badge-green">Listo para enviar</span>';
            const rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'btn btn-secondary';
            rm.textContent = 'Quitar';
            rm.addEventListener('click', () => {
                files.splice(i, 1);
                syncInput();
            });
            row.appendChild(rm);
            lista.appendChild(row);
        });
    }

    input?.addEventListener('change', () => {
        if (aplicandoLista) {
            return;
        }
        const incoming = Array.from(input.files || []);
        if (firmaLista(incoming) === firmaLista(files)) {
            return;
        }
        for (const f of incoming) {
            if (files.length >= maxFiles) {
                window.pqrsdAviso?.({
                    titulo: 'Archivos adjuntos',
                    mensaje: 'Máximo ' + maxFiles + ' archivos.',
                    variante: 'advertencia',
                });
                break;
            }
            if (f.size > maxBytes) {
                window.pqrsdAviso?.({
                    titulo: 'Archivo no admitido',
                    mensaje: f.name + ' supera el tamaño máximo permitido.',
                    variante: 'advertencia',
                });
                continue;
            }
            files.push(f);
        }
        syncInput();
    });

    form?.addEventListener('submit', () => {
        if (fnNac && fnNacT && !fnNac.value && fnNacT.value) fnNac.value = fnNacT.value;
        syncContextoCampos();
        // Deshabilitar campos ocultos de la sección no activa para no enviar duplicados confusos
        const quien = document.querySelector('input[name="quien_radica"]:checked')?.value || '';
        document.querySelectorAll('#sec-paciente input, #sec-paciente select').forEach(el => {
            if (quien !== 'tercero') el.disabled = true;
        });
        document.querySelectorAll('#sec-contexto-paciente-propio input, #sec-contexto-paciente-propio select').forEach(el => {
            if (quien !== 'paciente') el.disabled = true;
        });
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Enviando…';
        }
    });

    render();
})();
</script>
@endpush
