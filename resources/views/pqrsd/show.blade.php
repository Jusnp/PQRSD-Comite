@extends('layouts.pqrsd')

@section('title', $pqrsd->radicado)
@section('heading', $pqrsd->radicado)
@section('subheading', 'Expediente operativo — clasificar, responder y finalizar')

@section('content')
@php
    $cerrado = $pqrsd->estado_operativo === 'cerrado';
    $pendienteEval = $pqrsd->estado_operativo === 'pendiente_clasificacion'
        || $pqrsd->nivel_riesgo === 'pendiente'
        || $pqrsd->estado_cumplimiento === 'sin_clasificar'
        || ! filled($pqrsd->tipo_pqrsd_id);
    $plazoProvisional = $pendienteEval && filled($pqrsd->fecha_limite);
    $esVital = in_array($pqrsd->nivel_riesgo, ['vital_menor', 'vital_general'], true);
    $cumplimiento = $pqrsd->cumplimientoVigente();
    $vencido = $cumplimiento === 'vencido';
    $proximo = $cumplimiento === 'proximo_vencer';
    $etiquetaRespuesta = match ($pqrsd->respuesta_estado) {
        null, '' => 'Sin respuesta elaborada',
        'elaborada' => 'Respuesta elaborada',
        'aprobada' => 'Respuesta aprobada',
        'puesta_disposicion' => 'Disponible en consulta segura',
        'enviada' => 'Comunicada por canal verificable',
        'consultada' => 'Consultada por el ciudadano en el portal',
        default => str_replace('_', ' ', (string) $pqrsd->respuesta_estado),
    };
    $slaFmt = app(\App\Services\SlaCalculator::class);
    $restanteTxt = $slaFmt->formatearDuracion($plazo['restante_segundos'] ?? 0);
    $transcurridoTxt = $slaFmt->formatearDuracion($plazo['transcurrido_segundos'] ?? 0);
    $puedeClasificar = ! $cerrado && auth()->user()?->can('classify', $pqrsd);
    $puedeAuditar = auth()->user()?->can('reportes.view');
    $puedeActualizar = ! $cerrado && auth()->user()?->can('update', $pqrsd);
    $puedeCerrar = ! $cerrado && auth()->user()?->can('close', $pqrsd);
    $puedeResponder = $puedeActualizar;
    $requisitosCierre = $pqrsd->requisitosPendientesCierre();
    $puedeFinalizar = $puedeCerrar && $pqrsd->puedeFinalizarse();
    $metaFinalizacion = $pqrsd->etiquetaProgresoFinalizacion();
    $hitosEstado = \App\Support\EtiquetasExpediente::hitosEstado($pqrsd);
    $cerradoPor = $cerrado
        ? $pqrsd->historialEstados->first(fn ($h) => $h->campo === 'estado_operativo' && $h->valor_nuevo === 'cerrado')
        : null;
    $adjuntosCiudadano = $pqrsd->adjuntos->whereIn('tipo', ['soporte_publico', 'formulario_publico']);
    $adjuntosInternos = $pqrsd->adjuntos->where('tipo', 'soporte_interno')->whereNull('remision_interna_id');
    $remisionesHistoricas = $pqrsd->remisionesInternas;
@endphp

@if($puedeAuditar)
    <p style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn btn-secondary" href="{{ route('reportes.expediente.pdf', $pqrsd) }}">Informe PDF</a>
        <a class="btn btn-secondary" href="{{ route('reportes.expediente.zip', $pqrsd) }}">Descargar expediente completo</a>
    </p>
    <p class="muted">El PDF no tiene firma digital certificada. El ZIP incluye un manifiesto con hashes de integridad.</p>
@endif

<a class="back-button" href="{{ route('pqrsd.index') }}">← Volver al listado</a>

@if($pendienteEval)
    <div class="exp-alert exp-alert-warn" role="status">
        <strong>Pendiente de evaluación clínica / clasificación.</strong>
        No se asigna riesgo «simple» automáticamente.
        @if($plazoProvisional)
            El plazo o riesgo visibles pueden ser <strong>provisionales</strong> hasta completar la clasificación formal.
        @else
            Clasifique el caso antes de fijar plazos definitivos.
        @endif
    </div>
@endif
@if($esVital && ! $cerrado)
    <div class="exp-alert exp-alert-danger" role="alert">
        <strong>Riesgo vital.</strong>
        Atienda con prioridad. El vencimiento oficial no se pausa.
    </div>
@endif
@if($vencido && ! $cerrado)
    <div class="exp-alert exp-alert-danger" role="alert">
        <strong>Caso vencido.</strong>
        Fecha límite: {{ $pqrsd->fecha_limite?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}.
        Puede finalizarse solo cuando exista comunicación válida; el incumplimiento se conserva.
    </div>
@elseif($proximo && ! $cerrado)
    <div class="exp-alert exp-alert-warn" role="status">
        <strong>Próximo a vencer.</strong>
        Restante: {{ $restanteTxt }}.
    </div>
@endif
@if($cerrado)
    <div class="exp-alert exp-alert-info" role="status">
        <strong>PQRSD finalizada.</strong>
        Consulta en solo lectura; no se permiten modificaciones.
    </div>
@endif
@error('expediente')
    <div class="exp-alert exp-alert-danger" role="alert">
        <strong>Conflicto de actualización.</strong>
        {{ $message }}
    </div>
@enderror

{{-- Resumen compacto --}}
<section class="exp-summary card card-pad {{ $esVital ? 'exp-summary-vital' : '' }} {{ $vencido ? 'exp-summary-vencido' : '' }} {{ $pendienteEval ? 'exp-summary-pendiente' : '' }}" aria-labelledby="exp-resumen-titulo">
    <div class="exp-summary-top">
        <div>
            <p class="exp-kicker" id="exp-resumen-titulo">Resumen del radicado</p>
            <h2 class="exp-radicado">{{ $pqrsd->radicado }}</h2>
            <p class="muted exp-summary-meta">
                {{ $pqrsd->tipo?->nombre ?? 'Tipo pendiente' }}
                · {{ $pqrsd->motivo?->nombre ?? 'Sin motivo' }}
                · Canal {{ $pqrsd->canal?->nombre ?? '—' }}
            </p>
        </div>
        <div class="exp-summary-semaforo">
            <div class="muted">Cumplimiento</div>
            <x-semaforo-sla :estado="$cumplimiento" size="lg" />
        </div>
    </div>

    <dl class="exp-chips" aria-label="Datos clave del expediente">
        <div>
            <dt>Estado</dt>
            <dd>{{ \App\Support\EtiquetasExpediente::estadoOperativo($pqrsd->estado_operativo) }}</dd>
        </div>
        <div class="{{ $plazoProvisional ? 'exp-chip-provisional' : '' }}">
            <dt>Nivel de riesgo</dt>
            <dd class="{{ $esVital ? 'exp-text-danger' : ($pendienteEval ? 'exp-text-warn' : '') }}">
                {{ str_replace('_', ' ', $pqrsd->nivel_riesgo) }}
                @if($plazoProvisional)<span class="exp-provisional-note">Provisional — falta clasificación formal</span>@endif
            </dd>
        </div>
        <div>
            <dt>Recepción</dt>
            <dd>{{ $pqrsd->fecha_recepcion?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</dd>
        </div>
        <div class="{{ $plazoProvisional ? 'exp-chip-provisional' : '' }}">
            <dt>Fecha límite</dt>
            <dd>
                {{ $pqrsd->fecha_limite?->timezone('America/Bogota')->format('d/m/Y H:i') ?? 'Sin clasificar' }}
                @if($plazoProvisional)<span class="exp-provisional-note">Plazo provisional</span>@endif
            </dd>
        </div>
        <div>
            <dt>Transcurrido / Restante</dt>
            <dd>{{ $transcurridoTxt }} / {{ $restanteTxt }}</dd>
        </div>
        <div>
            <dt>Sede / EPS</dt>
            <dd>{{ $pqrsd->sede?->nombre ?? '—' }} · {{ $pqrsd->aseguradora?->nombre ?? '—' }}</dd>
        </div>
        <div>
            <dt>Estado de respuesta</dt>
            <dd>
                <span class="{{ in_array($pqrsd->respuesta_estado, ['puesta_disposicion','enviada','consultada'], true) ? 'badge-green' : ($pqrsd->respuesta_estado ? 'badge-blue' : 'badge-gray') }}">
                    {{ $etiquetaRespuesta }}
                </span>
            </dd>
        </div>
        <div>
            <dt>Finalización</dt>
            <dd>{{ $metaFinalizacion }}</dd>
        </div>
    </dl>

    <p class="muted exp-sla-note">
        Inicio de conteo: {{ $pqrsd->fecha_inicio_conteo?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}
        · Regla {{ $pqrsd->reglaSla?->codigo ?? 'N/A' }}{{ $pqrsd->regla_sla_version ? ' v'.$pqrsd->regla_sla_version : '' }}
        @if($pqrsd->reglaSla?->unidad === 'dias_habiles')
            · Días hábiles CO
        @endif
    </p>
</section>

@if(! $cerrado && ($puedeClasificar || $puedeActualizar || $puedeCerrar))
<nav class="exp-actions" aria-label="Acciones del expediente">
    @if($puedeClasificar)
        <a class="btn btn-secondary" href="#sec-clasificacion">Clasificar PQRSD</a>
    @endif
    @if($puedeActualizar)
        <a class="btn btn-secondary" href="#sec-respuesta">Respuesta de PQRS</a>
    @endif
    @if($puedeFinalizar)
        <a class="btn btn-secondary" href="#sec-cierre">Finalizar PQRSD</a>
    @elseif($puedeCerrar)
        <a class="btn btn-secondary" href="#sec-cierre">Estado y finalización</a>
    @endif
</nav>
@endif

{{-- A. Información de la PQRSD --}}
<details class="exp-section card" id="sec-personas" open>
    <summary class="exp-section-summary">
        <span class="exp-section-title">Información de la PQRSD</span>
        <span class="exp-section-meta muted">{{ $pqrsd->es_anonimo ? 'Anónimo' : ($pqrsd->solicitante_nombre ?? 'Sin nombre') }}</span>
    </summary>
    <div class="exp-section-body card-pad">
        <div class="exp-two-col">
            <div>
                <h3 class="exp-subtitle">Solicitante</h3>
                <p><strong>Nombre:</strong> {{ $pqrsd->es_anonimo ? 'Anónimo' : ($pqrsd->solicitante_nombre ?? '—') }}</p>
                <p><strong>Documento:</strong> {{ $pqrsd->es_anonimo ? 'No aportado (anónimo)' : (trim(($pqrsd->solicitante_tipo_documento ?? '').' '.($pqrsd->solicitante_documento ?? '')) ?: '—') }}</p>
                <p><strong>Contacto:</strong> {{ $pqrsd->solicitante_email ?? '—' }} / {{ $pqrsd->solicitante_telefono ?? '—' }}</p>
                <p><strong>Dirección correspondencia:</strong> {{ $pqrsd->solicitante_direccion ?? '—' }}</p>
                <p><strong>Anónimo:</strong> {{ $pqrsd->es_anonimo ? 'Sí (sin revelar identidad)' : 'No' }}</p>
                @if($pqrsd->anonimo_pendiente_validacion_juridica)
                    <p class="muted">Tipo anónimo pendiente de validación jurídica/institucional.</p>
                @endif
                <p><strong>Medio de respuesta:</strong> {{ \App\Support\MedioRespuesta::etiqueta($pqrsd->medio_respuesta) }}</p>
                @if($pqrsd->medio_respuesta === 'telefono')
                    <p class="muted">El teléfono es el medio de contacto preferido. La respuesta escrita permanece en el expediente y en la consulta segura. Una llamada solo consta si hay evidencia de fecha, funcionario y resultado. Su valor como notificación formal está pendiente de validación jurídica.</p>
                @elseif($pqrsd->medio_respuesta === 'correspondencia')
                    <p class="muted">Este expediente conservó correspondencia física. Las radicaciones nuevas ya no ofrecen ese medio.</p>
                @endif
            </div>
            <div>
                <h3 class="exp-subtitle">Paciente</h3>
                <p><strong>Paciente:</strong> {{ $pqrsd->paciente_nombre ?? '—' }}</p>
                <p><strong>Fecha de nacimiento:</strong> {{ $pqrsd->paciente_fecha_nacimiento?->format('d/m/Y') ?? '—' }}</p>
                <p><strong>Edad a la recepción:</strong> {{ $pqrsd->paciente_edad !== null ? $pqrsd->paciente_edad.' años (calculada)' : '—' }}</p>
                <p><strong>Representante:</strong> {{ $pqrsd->representante_nombre ?? '—' }}</p>
                <p><strong>Servicio/área:</strong> {{ $pqrsd->servicio_area ?? '—' }}</p>
            </div>
        </div>
        <h3 class="exp-subtitle">Descripción</h3>
        <p class="exp-descripcion">{{ $pqrsd->descripcion }}</p>
        @if($pqrsd->observaciones_internas)
            <h3 class="exp-subtitle">Observaciones internas</h3>
            <p>{{ $pqrsd->observaciones_internas }}</p>
        @endif
        <p class="muted">
            Radicación: {{ $pqrsd->fecha_radicacion?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}
            · Recepción: {{ $pqrsd->fecha_recepcion?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}
        </p>

        <h3 class="exp-subtitle">Archivos recibidos del ciudadano</h3>
        @if($adjuntosCiudadano->isNotEmpty())
            <ul class="exp-adjuntos">
                @foreach($adjuntosCiudadano as $adj)
                    <li>
                        <a href="{{ route('pqrsd.adjuntos.download', [$pqrsd, $adj]) }}">{{ $adj->nombre_original }}</a>
                        <span class="muted">({{ number_format(($adj->tamano ?? 0) / 1024, 1) }} KB)</span>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="muted">Sin archivos aportados por el ciudadano.</p>
        @endif
    </div>
</details>

{{-- B. Clasificación --}}
<details class="exp-section card" id="sec-clasificacion" @if($pendienteEval && ! $cerrado) open @endif>
    <summary class="exp-section-summary">
        <span class="exp-section-title">Clasificación</span>
        <span class="exp-section-meta muted">{{ str_replace('_', ' ', $pqrsd->nivel_riesgo) }} · {{ $pqrsd->tipo?->nombre ?? 'Tipo pendiente' }}</span>
    </summary>
    <div class="exp-section-body card-pad">
        @if($cerrado)
            <p class="muted">Caso cerrado. Clasificación no disponible.</p>
            <p><strong>Tipo:</strong> {{ $pqrsd->tipo?->nombre ?? 'Pendiente de clasificación' }} · <strong>Motivo:</strong> {{ $pqrsd->motivo?->nombre ?? '—' }}</p>
            <p><strong>Categoría:</strong> {{ $pqrsd->categoria?->nombre ?? '—' }} / {{ $pqrsd->subcategoria?->nombre ?? '—' }}</p>
            <p><strong>Riesgo:</strong> {{ str_replace('_', ' ', $pqrsd->nivel_riesgo) }}</p>
            <p><strong>Plazo:</strong> {{ $pqrsd->fecha_limite?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</p>
        @elseif($puedeClasificar)
            @if($pqrsd->pendienteDeClasificacion())
                <p class="alert alert-warning" role="status">
                    Sin clasificación desde la recepción: {{ $pqrsd->etiquetaEsperaClasificacion() }}.
                    Clasificar no reinicia el plazo.
                    @if($pqrsd->esperaSuperaPlazoMasCorto())
                        La espera ya supera el plazo más corto vigente (8 horas). El caso puede quedar vencido al confirmar el riesgo.
                    @endif
                    @if($pqrsd->alerta_vital_preventiva)
                        El ciudadano indicó un posible riesgo vital. La clasificación definitiva la hace el personal autorizado.
                    @endif
                </p>
            @endif
            <h3 class="exp-subtitle">Clasificar riesgo</h3>
            <p class="muted">Justifique el nivel. El SLA se calcula desde la recepción original. No se asigna «simple» por omisión. La bandeja es compartida: no se requiere asignar responsable.</p>
            <form method="POST" action="{{ route('pqrsd.clasificar', $pqrsd) }}" class="exp-form-narrow">
                @csrf
                <input type="hidden" name="expediente_version" value="{{ $pqrsd->versionToken() }}">
                <label class="form-label" for="nivel_riesgo">Nivel de riesgo</label>
                <select class="form-select" id="nivel_riesgo" name="nivel_riesgo" required>
                    <option value="">Seleccione…</option>
                    @foreach (['vital_menor','vital_general','priorizado','simple','ordinario'] as $riesgo)
                        <option value="{{ $riesgo }}" @selected(old('nivel_riesgo', $pqrsd->nivel_riesgo === 'pendiente' ? '' : $pqrsd->nivel_riesgo) === $riesgo)>{{ str_replace('_',' ', $riesgo) }}</option>
                    @endforeach
                </select>
                <label class="form-label" for="categoria_pqrsd_id" style="margin-top:10px">Categoría</label>
                <select class="form-select" id="categoria_pqrsd_id" name="categoria_pqrsd_id">
                    <option value="">Sin cambio</option>
                    @foreach($categorias as $categoria)
                        <option value="{{ $categoria->id }}" @selected(old('categoria_pqrsd_id', $pqrsd->categoria_pqrsd_id) == $categoria->id)>{{ $categoria->nombre }}</option>
                    @endforeach
                </select>
                <label class="form-label" for="subcategoria_pqrsd_id" style="margin-top:10px">Subcategoría</label>
                <select class="form-select" id="subcategoria_pqrsd_id" name="subcategoria_pqrsd_id">
                    <option value="">Sin cambio</option>
                    @foreach($categorias as $categoria)
                        @foreach($categoria->subcategorias as $sub)
                            <option value="{{ $sub->id }}" data-categoria="{{ $categoria->id }}" @selected(old('subcategoria_pqrsd_id', $pqrsd->subcategoria_pqrsd_id) == $sub->id)>{{ $categoria->nombre }} — {{ $sub->nombre }}</option>
                        @endforeach
                    @endforeach
                </select>
                <label class="form-label" for="motivo_pqrsd_id" style="margin-top:10px">Motivo</label>
                <select class="form-select" id="motivo_pqrsd_id" name="motivo_pqrsd_id">
                    <option value="">Sin cambio</option>
                    @foreach($motivos as $motivo)
                        <option value="{{ $motivo->id }}" @selected(old('motivo_pqrsd_id', $pqrsd->motivo_pqrsd_id) == $motivo->id)>{{ $motivo->nombre }}@if($motivo->subcategoriaSugerida) — sugiere {{ $motivo->subcategoriaSugerida->nombre }}@endif</option>
                    @endforeach
                </select>
                <label class="form-label" for="tipo_pqrsd_id" style="margin-top:10px">Tipo de manifestación</label>
                <select class="form-select" id="tipo_pqrsd_id" name="tipo_pqrsd_id">
                    <option value="">Sin cambio</option>
                    @foreach($tipos as $tipo)
                        <option value="{{ $tipo->id }}" @selected(old('tipo_pqrsd_id', $pqrsd->tipo_pqrsd_id) == $tipo->id)>{{ $tipo->nombre }}</option>
                    @endforeach
                </select>
                <label class="form-label" for="justificacion" style="margin-top:10px">Justificación</label>
                <textarea class="form-input" id="justificacion" name="justificacion" rows="3" required minlength="10" style="min-height:80px">{{ old('justificacion') }}</textarea>
                @if($pqrsd->fecha_limite)
                <label style="display:flex;gap:8px;align-items:flex-start;font-weight:700;margin-top:10px">
                    <input type="checkbox" name="forzar_recalculo" value="1">
                    <span>Forzar recálculo de plazo (mantener inicio = recepción)</span>
                </label>
                @endif
                <button class="btn btn-green" style="margin-top:12px" type="submit">Aplicar clasificación</button>
            </form>
        @endif
        @if(! $cerrado)
            <p style="margin-top:14px"><strong>Plazo correspondiente:</strong> {{ $pqrsd->fecha_limite?->timezone('America/Bogota')->format('d/m/Y H:i') ?? 'Pendiente de clasificación formal' }}</p>
        @endif
    </div>
</details>

{{-- C. Respuesta de PQRS --}}
<details class="exp-section card" id="sec-respuesta" @if($errors->hasAny(['detalle_respuesta', 'respuesta_canal_comunicacion', 'respuesta_evidencia_comunicacion', 'adjuntos'])) open @endif>
    <summary class="exp-section-summary">
        <span class="exp-section-title">Respuesta de PQRS</span>
        <span class="exp-section-meta muted">{{ $etiquetaRespuesta }}</span>
    </summary>
    <div class="exp-section-body card-pad">
        @if($puedeResponder)
            <p class="muted" style="margin-top:0">
                Distinga: <strong>elaborar</strong> (borrador),
                <strong>publicar en consulta segura</strong> y
                <strong>registrar comunicación por otro medio</strong>.
                Solo la publicación o la comunicación válida alimentan el indicador de oportunidad y habilitan el cierre.
                Gmail aún no está conectado.
            </p>
            @if($pqrsd->respuesta_consulta_texto)
                <p><strong>Texto actual:</strong></p>
                <p class="muted">{{ \Illuminate\Support\Str::limit($pqrsd->respuesta_consulta_texto, 280) }}</p>
            @endif
            @if($pqrsd->respuesta_elaborada_en)
                <p class="muted">Fecha de elaboración: {{ $pqrsd->respuesta_elaborada_en->timezone('America/Bogota')->format('d/m/Y H:i') }}</p>
            @endif
            @if($pqrsd->fecha_respuesta)
                <p class="muted">Fecha de comunicación (oportunidad): {{ $pqrsd->fecha_respuesta->timezone('America/Bogota')->format('d/m/Y H:i') }}</p>
            @endif
            <form method="POST" action="{{ route('pqrsd.responder', $pqrsd) }}" id="form-respuesta-pqrs">
                @csrf
                <input type="hidden" name="expediente_version" value="{{ $pqrsd->versionToken() }}">
                <label class="form-label" for="detalle_respuesta">Contenido de la respuesta</label>
                <textarea class="form-input" id="detalle_respuesta" name="detalle_respuesta" rows="4" required minlength="10" style="min-height:100px">{{ old('detalle_respuesta', $pqrsd->respuesta_consulta_texto) }}</textarea>

                <p class="respuesta-ayuda" id="ayuda-fecha-respuesta" style="margin-top:12px">
                    La <strong>Fecha de elaboración de respuesta</strong> se registra automáticamente al guardar el borrador.
                    La <strong>Fecha de comunicación</strong> (usada en oportunidad) se registra con el reloj del servidor
                    al publicar en consulta segura o al evidenciar un envío por otro medio. No es editable por el auxiliar.
                </p>

                <label class="form-label" for="respuesta_estado_destino" style="margin-top:12px">Estado de la respuesta</label>
                <select class="form-select" id="respuesta_estado_destino" name="respuesta_estado_destino">
                    <option value="elaborada" @selected(old('respuesta_estado_destino', $pqrsd->respuesta_estado ?: 'elaborada') === 'elaborada')>Respuesta elaborada</option>
                    <option value="aprobada" @selected(old('respuesta_estado_destino') === 'aprobada')>Respuesta aprobada (cuando aplique)</option>
                    <option value="puesta_disposicion" @selected(old('respuesta_estado_destino') === 'puesta_disposicion')>Disponible en consulta segura</option>
                    <option value="enviada" @selected(old('respuesta_estado_destino') === 'enviada')>Comunicada por canal verificable</option>
                </select>
                <p class="respuesta-ayuda" id="ayuda-estado-respuesta">
                    Elabore primero el contenido. Solo marque publicación o comunicación cuando corresponda al medio real usado.
                </p>

                <fieldset class="respuesta-opciones">
                    <legend>Publicación y comunicación</legend>
                    <label style="display:flex;gap:8px;align-items:flex-start;font-weight:700;margin-top:4px">
                        <input type="checkbox" name="poner_disposicion_consulta" value="1" id="poner_disposicion_consulta" @checked(old('poner_disposicion_consulta'))>
                        <span>
                            Disponible en consulta segura
                            <span class="respuesta-ayuda" style="display:block;font-weight:500">
                                El ciudadano podrá ver el texto en el portal con radicado y código. No confirma lectura.
                            </span>
                        </span>
                    </label>
                    <label style="display:flex;gap:8px;align-items:flex-start;font-weight:700;margin-top:10px">
                        <input type="checkbox" name="marcar_envio_verificable" value="1" id="marcar_envio_verificable" @checked(old('marcar_envio_verificable'))>
                        <span>
                            Registrar envío realizado por otro medio
                            <span class="respuesta-ayuda" style="display:block;font-weight:500">
                                Requiere canal y evidencia verificable (fecha, funcionario y resultado). Sin esa evidencia no queda registrada una llamada. No usa Gmail automático ni afirma acuse de recibo. La llamada como notificación formal está pendiente de validación jurídica.
                            </span>
                        </span>
                    </label>
                    <div class="exp-envio-campos {{ old('marcar_envio_verificable') || old('respuesta_estado_destino') === 'enviada' ? 'is-visible' : '' }}" id="campos-envio-verificable">
                        <label class="form-label" for="respuesta_canal_comunicacion">Canal utilizado</label>
                        <input class="form-input" id="respuesta_canal_comunicacion" name="respuesta_canal_comunicacion" maxlength="80" value="{{ old('respuesta_canal_comunicacion', $pqrsd->respuesta_canal_comunicacion) }}" placeholder="Ej. Teléfono con evidencia, correo institucional, consulta segura">
                        @error('respuesta_canal_comunicacion')<div class="muted" style="color:var(--red)">{{ $message }}</div>@enderror
                        <label class="form-label" for="respuesta_evidencia_comunicacion" style="margin-top:10px">Evidencia de comunicación</label>
                        <textarea class="form-input" id="respuesta_evidencia_comunicacion" name="respuesta_evidencia_comunicacion" rows="2" minlength="10" maxlength="2000" placeholder="Referencia verificable: número de guía, asunto/fecha del correo, acta, etc.">{{ old('respuesta_evidencia_comunicacion', $pqrsd->respuesta_evidencia_comunicacion) }}</textarea>
                        @error('respuesta_evidencia_comunicacion')<div class="muted" style="color:var(--red)">{{ $message }}</div>@enderror
                    </div>
                </fieldset>

                <button class="btn btn-green" style="margin-top:14px" type="submit">Guardar respuesta</button>
            </form>

            <div id="form-adjuntar-soporte-respuesta" style="margin-top:22px;padding-top:16px;border-top:1px solid color-mix(in srgb, var(--border) 80%, transparent)">
                <h3 class="exp-subtitle">Soportes de la respuesta (opcional)</h3>
                <p class="muted" style="margin-top:0">PDF, Word (.doc, .docx) o imágenes. Máx. 5 archivos · 10 MB c/u. Almacenamiento privado.</p>
                <form method="POST" action="{{ route('pqrsd.adjuntos.store', $pqrsd) }}" enctype="multipart/form-data">
                    @csrf
                    <label class="form-label" for="adjuntos_respuesta">Seleccionar archivos</label>
                    <input class="form-input" id="adjuntos_respuesta" type="file" name="adjuntos[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" required>
                    <button class="btn btn-secondary" style="margin-top:12px" type="submit">Adjuntar soporte a la respuesta</button>
                </form>
            </div>
        @else
            <p><strong>Estado de respuesta:</strong> {{ $etiquetaRespuesta }}</p>
            @if($pqrsd->respuesta_consulta_texto)
                <p class="exp-descripcion">{{ $pqrsd->respuesta_consulta_texto }}</p>
            @else
                <p class="muted">Sin respuesta de PQRS registrada o el caso está cerrado para edición.</p>
            @endif
            @if($pqrsd->respuesta_elaborada_en)
                <p class="muted">Elaborada: {{ $pqrsd->respuesta_elaborada_en->timezone('America/Bogota')->format('d/m/Y H:i') }}</p>
            @endif
            @if($pqrsd->fecha_respuesta)
                <p class="muted">Comunicación (oportunidad): {{ $pqrsd->fecha_respuesta->timezone('America/Bogota')->format('d/m/Y H:i') }}</p>
            @endif
            @if($pqrsd->respuesta_puesta_disposicion_en)
                <p class="muted">Disponible en portal desde: {{ $pqrsd->respuesta_puesta_disposicion_en->timezone('America/Bogota')->format('d/m/Y H:i') }}</p>
            @endif
            @if($pqrsd->respuesta_enviada_en)
                <p class="muted">Evidencia de comunicación: {{ $pqrsd->respuesta_enviada_en->timezone('America/Bogota')->format('d/m/Y H:i') }}
                    @if($pqrsd->respuesta_canal_comunicacion)
                        · Canal: {{ $pqrsd->respuesta_canal_comunicacion }}
                    @endif
                </p>
            @endif
            @if($pqrsd->respuesta_consultada_en)
                <p class="muted">Consultada por el ciudadano: {{ $pqrsd->respuesta_consultada_en->timezone('America/Bogota')->format('d/m/Y H:i') }}</p>
            @endif
        @endif

        @if($adjuntosInternos->isNotEmpty())
            <h3 class="exp-subtitle" style="margin-top:18px">Soportes incorporados al expediente</h3>
            <ul class="exp-adjuntos">
                @foreach($adjuntosInternos as $adj)
                    <li>
                        <a href="{{ route('pqrsd.adjuntos.download', [$pqrsd, $adj]) }}">{{ $adj->nombre_original }}</a>
                        <span class="muted">({{ number_format(($adj->tamano ?? 0) / 1024, 1) }} KB)</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</details>

{{-- D. Estado y finalización --}}
<details class="exp-section card" id="sec-cierre" @if($errors->has('motivo_cierre') || $errors->has('expediente') || $puedeFinalizar) open @endif>
    <summary class="exp-section-summary">
        <span class="exp-section-title">Estado y finalización</span>
        <span class="exp-section-meta muted">{{ $metaFinalizacion }}</span>
    </summary>
    <div class="exp-section-body card-pad">
        @if($cerrado)
            <div class="exp-alert exp-alert-info" role="status">
                <strong>Estado: Finalizada</strong>
            </div>
            <p><strong>Fecha y hora de cierre:</strong> {{ $pqrsd->fecha_cierre?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</p>
            <p><strong>Usuario que finalizó:</strong> {{ $cerradoPor?->usuario?->name ?? 'Sistema' }}</p>
            <p><strong>Motivo:</strong> {{ $cerradoPor?->motivo ?? '—' }}</p>
            <p><strong>Canal de comunicación:</strong> {{ $pqrsd->respuesta_canal_comunicacion ?: (in_array($pqrsd->respuesta_estado, ['puesta_disposicion', 'consultada'], true) ? 'Consulta segura (portal)' : '—') }}</p>
            <p><strong>Fecha de respuesta oficial válida:</strong> {{ $pqrsd->fecha_respuesta?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</p>
            <p><strong>Resultado de oportunidad:</strong>
                @if($cumplimiento === 'resuelto_oportuno')
                    Dentro del plazo (resuelto oportuno)
                @elseif($cumplimiento === 'resuelto_extemporaneo')
                    Fuera del plazo (resuelto extemporáneo)
                @else
                    {{ str_replace('_', ' ', $cumplimiento) }}
                @endif
            </p>
            <p><strong>Estado de respuesta:</strong> {{ $etiquetaRespuesta }}</p>
        @else
            <p><strong>Estado actual:</strong> {{ \App\Support\EtiquetasExpediente::estadoOperativo($pqrsd->estado_operativo) }}</p>
            <p><strong>Cumplimiento del plazo:</strong> {{ str_replace('_', ' ', $cumplimiento) }}
                · {{ $transcurridoTxt }} transcurridos · {{ $restanteTxt }} restantes
            </p>
            <p><strong>Estado de respuesta:</strong> {{ $etiquetaRespuesta }}</p>

            @if(! $puedeFinalizar)
                <div class="exp-alert exp-alert-info" role="status">
                    <strong>{{ $metaFinalizacion }}</strong>
                    @if($requisitosCierre !== [])
                        <ul class="exp-cierre-pendientes">
                            @foreach($requisitosCierre as $req)
                                <li>{{ $req }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="muted" style="margin-bottom:0">
                        El plazo SLA es el máximo para responder. Puede finalizarse antes del vencimiento cuando la atención y la comunicación estén completas. Un vencimiento sin respuesta no cierra el caso automáticamente.
                    </p>
                </div>
            @endif

            @if($puedeFinalizar)
                <p class="muted">Requisitos completos. Finalizar no cambia la fecha de comunicación ni el resultado de oportunidad.</p>
                <form method="POST" action="{{ route('pqrsd.cerrar', $pqrsd) }}" class="exp-form-narrow" id="form-finalizar-pqrsd" data-pqrsd-confirmar data-variante="peligro" data-titulo="¿Finalizar esta PQRSD?" data-mensaje="Estás a punto de finalizar el expediente {{ $pqrsd->radicado }}. Esta acción deja el expediente en solo lectura." data-aceptar="Finalizar PQRSD">
                    @csrf
                    <input type="hidden" name="expediente_version" value="{{ $pqrsd->versionToken() }}">
                    <label class="form-label" for="motivo_cierre">Motivo de cierre</label>
                    <input class="form-input" id="motivo_cierre" name="motivo_cierre" required minlength="10" maxlength="500" placeholder="Ej. Respuesta comunicada al ciudadano y gestión concluida">
                    <button class="btn btn-green" style="margin-top:12px" type="submit">Finalizar PQRSD</button>
                </form>
            @endif
        @endif

        <h3 class="exp-subtitle">Historial automático</h3>
        <p class="muted" style="margin-top:0">Acontecimientos registrados por el sistema (radicación, clasificación, respuesta, comunicación, finalización). No requiere formularios manuales.</p>
        @forelse ($hitosEstado as $hito)
            <div class="timeline-item">
                <strong>{{ $hito['etiqueta'] }}</strong>
                <div class="muted">{{ $hito['fecha'] ?? '—' }} — {{ $hito['usuario'] ?? 'Sistema' }}</div>
                @if(! empty($hito['detalle']))
                    <div>{{ $hito['detalle'] }}</div>
                @endif
            </div>
        @empty
            <p class="muted">Aún no hay acontecimientos de estado para mostrar.</p>
        @endforelse

        @if($pqrsd->actuaciones->isNotEmpty())
            <details class="exp-audit-details" style="margin-top:16px">
                <summary class="exp-subtitle" style="cursor:pointer">Consultar gestiones históricas</summary>
                <p class="muted">Registros previos conservados para trazabilidad. Ya no se crean gestiones manuales nuevas.</p>
                @foreach ($pqrsd->actuaciones as $actuacion)
                    @php
                        $nombreGestion = \App\Support\EtiquetasExpediente::tituloGestion($actuacion);
                        $tipoAmigable = \App\Support\EtiquetasExpediente::tipoGestion($actuacion->tipo);
                    @endphp
                    <div class="timeline-item">
                        <strong>{{ $nombreGestion }}</strong>
                        <div class="muted">
                            {{ $actuacion->created_at?->timezone('America/Bogota')->format('d/m/Y H:i') }}
                            — {{ $actuacion->usuario?->name ?? 'Sistema' }}
                            · {{ $tipoAmigable }}
                        </div>
                        @if(filled($actuacion->detalle))
                            <div>{{ $actuacion->detalle }}</div>
                        @endif
                    </div>
                @endforeach
            </details>
        @endif

        @if($remisionesHistoricas->isNotEmpty())
            <details class="exp-audit-details" style="margin-top:16px" id="sec-remisiones-historicas">
                <summary class="exp-subtitle" style="cursor:pointer">Consultar remisiones históricas (solo lectura)</summary>
                <p class="muted">Las remisiones a otras áreas ya no forman parte de la operación. Se conservan para consulta autorizada.</p>
                <ul class="exp-solicitudes-lista">
                    @foreach($remisionesHistoricas as $rem)
                        <li>
                            <strong>{{ $rem->area?->nombre ?? 'Área' }}</strong>
                            — {{ \Illuminate\Support\Str::limit($rem->motivo, 80) }}
                            <span class="muted">({{ $rem->etiquetaEstado() }})</span>
                            <a href="{{ route('pqrsd.remisiones.show', [$pqrsd, $rem]) }}">Consultar</a>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        @if(isset($notificaciones) && $notificaciones->isNotEmpty())
            <details class="exp-audit-details" style="margin-top:16px">
                <summary class="exp-subtitle" style="cursor:pointer">Alertas de plazo (SLA)</summary>
                @foreach($notificaciones as $n)
                    <div class="timeline-item">
                        <strong>{{ str_replace('_', ' ', $n->tipo) }} — {{ $n->estado }}</strong>
                        @php
                            $cantidadAviso = (int) ($n->metadatos['destinatarios_cantidad'] ?? 0);
                            $etiquetaAviso = 'Aviso interno';
                            if ($n->canal === 'correo' && filled($n->destinatario) && ! str_contains((string) $n->destinatario, ',')) {
                                $etiquetaAviso = $n->destinatario;
                            } elseif (! filled($n->destinatario) && $cantidadAviso === 0) {
                                $etiquetaAviso = 'sin destinatario';
                            }
                        @endphp
                        <div class="muted">{{ $n->enviada_en?->timezone('America/Bogota')->format('d/m/Y H:i') ?? $n->created_at?->timezone('America/Bogota')->format('d/m/Y H:i') }} · {{ $etiquetaAviso }}</div>
                        <div>{{ $n->mensaje }}</div>
                    </div>
                @endforeach
            </details>
        @endif

        @can('auditoria.view')
            <details class="exp-audit-details" id="sec-historial" style="margin-top:16px">
                <summary class="exp-subtitle" style="cursor:pointer">Ver detalles de auditoría</summary>
                <p class="muted">Registro técnico íntegro. No altera ni elimina eventos históricos.</p>
                @forelse ($pqrsd->auditorias as $aud)
                    <div class="timeline-item">
                        <strong>{{ $aud->accion }}</strong>
                        <div class="muted">{{ $aud->created_at?->timezone('America/Bogota')->format('d/m/Y H:i') }} — {{ $aud->usuario?->name ?? 'Sistema' }}</div>
                    </div>
                @empty
                    <p class="muted">Sin eventos de auditoría.</p>
                @endforelse
            </details>
        @endcan
    </div>
</details>

@push('scripts')
<script>
(function () {
    function openParentDetails(el) {
        var node = el;
        while (node) {
            if (node.tagName === 'DETAILS') {
                node.open = true;
                break;
            }
            node = node.parentElement;
        }
    }
    document.querySelectorAll('.exp-actions a[href^="#"]').forEach(function (link) {
        link.addEventListener('click', function () {
            var id = link.getAttribute('href').slice(1);
            var target = document.getElementById(id);
            if (! target) {
                return;
            }
            if (target.tagName === 'DETAILS') {
                target.open = true;
            } else {
                openParentDetails(target);
            }
        });
    });

    var estadoResp = document.getElementById('respuesta_estado_destino');
    var chkPortal = document.getElementById('poner_disposicion_consulta');
    var chkEnvio = document.getElementById('marcar_envio_verificable');
    var ayudaEstado = document.getElementById('ayuda-estado-respuesta');
    var camposEnvio = document.getElementById('campos-envio-verificable');
    if (estadoResp && ayudaEstado) {
        var ayudas = {
            elaborada: 'Guarda el borrador. No cuenta para oportunidad ni habilita el cierre por sí sola.',
            aprobada: 'Marca aprobación interna. Aún no implica publicación ni comunicación al ciudadano.',
            puesta_disposicion: 'Publica en consulta segura. Cuenta como comunicación válida para oportunidad y cierre. No confirma lectura.',
            enviada: 'Registra evidencia de comunicación externa (canal + evidencia obligatorios). No usa Gmail automático.'
        };
        function syncRespuestaUi() {
            var v = estadoResp.value;
            ayudaEstado.textContent = ayudas[v] || ayudas.elaborada;
            if (chkPortal && v === 'puesta_disposicion') {
                chkPortal.checked = true;
            }
            if (chkEnvio && v === 'enviada') {
                chkEnvio.checked = true;
            }
            if (camposEnvio) {
                var showEnvio = (chkEnvio && chkEnvio.checked) || v === 'enviada';
                camposEnvio.classList.toggle('is-visible', !!showEnvio);
            }
        }
        estadoResp.addEventListener('change', syncRespuestaUi);
        if (chkEnvio) chkEnvio.addEventListener('change', syncRespuestaUi);
        if (chkPortal) chkPortal.addEventListener('change', syncRespuestaUi);
        syncRespuestaUi();
    }
})();
</script>
@endpush
@endsection
