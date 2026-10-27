@extends('layouts.pqrsd')

@section('title', 'Bandeja de PQRSD')
@section('heading', 'Bandeja de PQRSD')
@section('subheading', 'Centro operativo — listado filtrable con semáforo de cumplimiento')

@section('content')
@php
    $sortLink = function (string $campo, string $etiqueta) use ($sort, $dir) {
        $next = ($sort === $campo && $dir === 'asc') ? 'desc' : 'asc';
        $url = request()->fullUrlWithQuery(['sort' => $campo, 'dir' => $next, 'page' => null]);
        $mark = $sort === $campo ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        return '<a href="'.e($url).'">'.e($etiqueta).$mark.'</a>';
    };
@endphp
<style>
.badge-pendiente{display:inline-block;padding:2px 8px;border-radius:6px;background:rgba(202,138,4,.18);color:#a16207;font-size:11px;font-weight:700;margin-left:6px}
.badge-anonimo{display:inline-block;padding:2px 8px;border-radius:6px;background:rgba(100,116,139,.15);color:#475569;font-size:11px;font-weight:700}
.badge-vital{display:inline-block;padding:2px 8px;border-radius:6px;background:rgba(220,38,38,.15);color:#b91c1c;font-size:11px;font-weight:700;margin-left:6px}
.row-pendiente{background:rgba(250,204,21,.08)}
.row-vencido{background:rgba(239,68,68,.08)}
.row-proximo{background:rgba(249,115,22,.08)}
.filtros-avanzados[hidden]{display:none!important}
</style>
<div class="card card-pad" style="margin-bottom:18px">
    <form method="GET" class="form-grid" aria-label="Filtros de bandeja" id="form-filtros-bandeja">
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">
        <div class="full"><strong>Filtros principales</strong></div>
        <div>
            <label class="form-label" for="q">Buscar</label>
            <input class="form-input" id="q" type="search" name="q" value="{{ request('q') }}" placeholder="Radicado, solicitante o paciente">
        </div>
        <div>
            <label class="form-label" for="estado_operativo">Estado operativo</label>
            <select class="form-select" id="estado_operativo" name="estado_operativo">
                <option value="">Todos</option>
                @foreach (['pendiente_clasificacion','radicado','asignado','en_gestion','respondido','cerrado'] as $estado)
                    <option value="{{ $estado }}" @selected(request('estado_operativo') === $estado)>{{ str_replace('_',' ', $estado) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="nivel_riesgo">Nivel de riesgo</label>
            <select class="form-select" id="nivel_riesgo" name="nivel_riesgo">
                <option value="">Todos</option>
                @foreach (['pendiente','vital_menor','vital_general','priorizado','simple','ordinario'] as $riesgo)
                    <option value="{{ $riesgo }}" @selected(request('nivel_riesgo') === $riesgo)>{{ str_replace('_',' ', $riesgo) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="tipo_pqrsd_id">Tipo</label>
            <select class="form-select" id="tipo_pqrsd_id" name="tipo_pqrsd_id">
                <option value="">Todos</option>
                @foreach($tipos as $tipo)
                    <option value="{{ $tipo->id }}" @selected(request('tipo_pqrsd_id') == $tipo->id)>{{ $tipo->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="sede_id">Sede</label>
            <select class="form-select" id="sede_id" name="sede_id">
                <option value="">Todas</option>
                @foreach($sedes as $sede)
                    <option value="{{ $sede->id }}" @selected(request('sede_id') == $sede->id)>{{ $sede->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="aseguradora_id">EPS / Aseguradora</label>
            <select class="form-select" id="aseguradora_id" name="aseguradora_id">
                <option value="">Todas</option>
                @foreach($aseguradoras as $a)
                    <option value="{{ $a->id }}" @selected(request('aseguradora_id') == $a->id)>{{ $a->nombre }}</option>
                @endforeach
            </select>
        </div>

        <div class="full">
            <button type="button" class="btn btn-secondary" id="btn-toggle-avanzados" aria-expanded="{{ $avanzadosAbiertos ? 'true' : 'false' }}" aria-controls="filtros-avanzados">
                {{ $avanzadosAbiertos ? 'Ocultar más filtros' : 'Más filtros' }}
            </button>
        </div>

        <div id="filtros-avanzados" class="form-grid full filtros-avanzados" @if(! $avanzadosAbiertos) hidden @endif>
            <input type="hidden" name="avanzados" value="{{ $avanzadosAbiertos ? '1' : '0' }}" id="input-avanzados">
            <div class="full"><strong>Más filtros</strong></div>
            <div>
                <label class="form-label" for="motivo_pqrsd_id">Motivo</label>
                <select class="form-select" id="motivo_pqrsd_id" name="motivo_pqrsd_id">
                    <option value="">Todos</option>
                    <option value="sin_motivo" @selected(request('motivo_pqrsd_id') === 'sin_motivo')>Sin motivo registrado</option>
                    @foreach($motivos as $motivo)
                        <option value="{{ $motivo->id }}" @selected(request('motivo_pqrsd_id') == $motivo->id)>{{ $motivo->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label" for="fecha_desde">Recepción desde</label>
                <input class="form-input" id="fecha_desde" type="date" name="fecha_desde" value="{{ request('fecha_desde') }}">
            </div>
            <div>
                <label class="form-label" for="fecha_hasta">Recepción hasta</label>
                <input class="form-input" id="fecha_hasta" type="date" name="fecha_hasta" value="{{ request('fecha_hasta') }}">
            </div>
            <div>
                <label class="form-label" for="canal_ingreso_id">Canal de ingreso</label>
                <select class="form-select" id="canal_ingreso_id" name="canal_ingreso_id">
                    <option value="">Todos</option>
                    @foreach($canales as $canal)
                        <option value="{{ $canal->id }}" @selected(request('canal_ingreso_id') == $canal->id)>{{ $canal->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label" for="estado_cumplimiento">Cumplimiento</label>
                <select class="form-select" id="estado_cumplimiento" name="estado_cumplimiento">
                    <option value="">Todos</option>
                    @foreach (['dentro_plazo','proximo_vencer','vencido','resuelto_oportuno','resuelto_extemporaneo','sin_clasificar'] as $c)
                        <option value="{{ $c }}" @selected(request('estado_cumplimiento') === $c)>{{ str_replace('_',' ', $c) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label" for="es_anonimo">Anonimato</label>
                <select class="form-select" id="es_anonimo" name="es_anonimo">
                    <option value="">Todos</option>
                    <option value="1" @selected(request('es_anonimo') === '1')>Anónimos</option>
                    <option value="0" @selected(request('es_anonimo') === '0')>Identificados</option>
                </select>
            </div>
            <div>
                <label class="form-label" for="medio_respuesta">Medio de respuesta</label>
                <select class="form-select" id="medio_respuesta" name="medio_respuesta">
                    <option value="">Todos</option>
                    @foreach($mediosRespuesta as $codigo => $etiqueta)
                        <option value="{{ $codigo }}" @selected(request('medio_respuesta') === $codigo)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="full filter-actions">
            <button class="btn btn-green" type="submit">Filtrar</button>
            <a class="btn btn-secondary" href="{{ route('pqrsd.index') }}">Limpiar</a>
            @can('reportes.view')
                <a class="btn btn-secondary" href="{{ route('reportes.excel', request()->query()) }}">Exportar</a>
            @endcan
            @can('pqrsd.create_manual')
                <a class="btn btn-secondary" href="{{ route('pqrsd.create') }}">Nueva radicación</a>
            @endcan
        </div>
    </form>
</div>

<div class="card card-pad">
    <p class="bandeja-scroll-hint" id="bandeja-scroll-hint">
        En pantallas intermedias puede desplazarse horizontalmente dentro del listado; la columna «Ver expediente» permanece visible a la derecha.
    </p>
    <div
        class="table-wrap bandeja-table-wrap"
        tabindex="0"
        role="region"
        aria-label="Listado de PQRSD"
        aria-describedby="bandeja-scroll-hint"
    >
        <table class="pqrsd-table bandeja-table">
            <thead>
                <tr>
                    <th class="col-radicado" scope="col">{!! $sortLink('radicado', 'Radicado') !!}</th>
                    <th class="col-solicitante" scope="col">Solicitante</th>
                    <th class="col-tipo" scope="col">Tipo / motivo</th>
                    <th class="col-riesgo" scope="col">{!! $sortLink('nivel_riesgo', 'Riesgo') !!}</th>
                    <th class="col-estado" scope="col">Estado</th>
                    <th class="col-limite" scope="col">{!! $sortLink('fecha_limite', 'Fecha límite') !!}</th>
                    <th class="col-cumplimiento" scope="col">Cumplimiento</th>
                    <th class="col-accion" scope="col">Acción</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $caso)
                    @php
                        $cumplimiento = $caso->cumplimientoVigente();
                        $pendienteEval = $caso->estado_operativo === 'pendiente_clasificacion'
                            || $caso->nivel_riesgo === 'pendiente'
                            || $caso->estado_cumplimiento === 'sin_clasificar';
                        $vital = in_array($caso->nivel_riesgo, ['vital_menor','vital_general'], true);
                        $rowClass = match (true) {
                            $cumplimiento === 'vencido' => 'row-vencido',
                            $cumplimiento === 'proximo_vencer' => 'row-proximo',
                            $pendienteEval => 'row-pendiente',
                            default => '',
                        };
                        $ultima = $caso->ultimaActuacion;
                        $estadoLabel = \App\Support\EtiquetasExpediente::estadoOperativo($caso->estado_operativo);
                    @endphp
                    <tr class="{{ $rowClass }}">
                        <td class="col-radicado">
                            <a class="radicado-link" href="{{ route('pqrsd.show', $caso) }}">{{ $caso->radicado }}</a>
                            @if($pendienteEval)<span class="badge-pendiente">Pendiente evaluación</span>@endif
                            @if($vital)<span class="badge-vital">Vital</span>@endif
                            <div class="bandeja-meta">
                                Recepción {{ $caso->fecha_recepcion?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}
                                · {{ $caso->sede?->nombre ?? 'Sin sede' }}
                                · {{ $caso->aseguradora?->nombre ?? 'Sin EPS' }}
                                @if($ultima)
                                    · Última gestión: {{ $ultima->usuario?->name ?? 'Sistema' }}
                                    ({{ $ultima->created_at?->timezone('America/Bogota')->format('d/m/Y H:i') }})
                                @endif
                            </div>
                        </td>
                        <td class="col-solicitante">
                            {{ $caso->es_anonimo ? 'Anónimo' : ($caso->solicitante_nombre ?? '—') }}
                            @if($caso->es_anonimo)<div><span class="badge-anonimo">Anónimo</span></div>@endif
                        </td>
                        <td class="col-tipo">
                            <div class="bandeja-tipo-main">{{ $caso->tipo?->nombre ?? '—' }}</div>
                            <div class="bandeja-meta">{{ $caso->motivo?->nombre ?? 'Sin motivo' }}</div>
                        </td>
                        <td class="col-riesgo">{{ str_replace('_', ' ', $caso->nivel_riesgo) }}</td>
                        <td class="col-estado">{{ $estadoLabel }}</td>
                        <td class="col-limite">{{ $caso->fecha_limite?->timezone('America/Bogota')->format('d/m/Y H:i') ?? 'Sin clasificar' }}</td>
                        <td class="col-cumplimiento"><x-semaforo-sla :estado="$cumplimiento" /></td>
                        <td class="col-accion">
                            <a class="btn btn-secondary btn-ver-expediente" href="{{ route('pqrsd.show', $caso) }}">Ver expediente</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">No hay resultados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:16px">{{ $items->links() }}</div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const btn = document.getElementById('btn-toggle-avanzados');
    const box = document.getElementById('filtros-avanzados');
    const flag = document.getElementById('input-avanzados');
    if (!btn || !box) return;
    btn.addEventListener('click', () => {
        const open = box.hasAttribute('hidden');
        box.toggleAttribute('hidden', !open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.textContent = open ? 'Ocultar más filtros' : 'Más filtros';
        if (flag) flag.value = open ? '1' : '0';
    });
})();
</script>
@endpush
