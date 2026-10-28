@extends('layouts.pqrsd')

@section('title', 'Dashboard')
@section('heading', 'Dashboard PQRSD')
@section('subheading', 'Indicadores operativos, alertas y cumplimiento')

@section('content')
<form method="GET" class="card card-pad" style="margin-bottom:18px" aria-label="Filtros del dashboard">
    <div class="form-grid">
        <div>
            <label class="form-label" for="desde">Desde</label>
            <input class="form-input" id="desde" type="date" name="desde" value="{{ $filtros['desde'] ?? '' }}">
        </div>
        <div>
            <label class="form-label" for="hasta">Hasta</label>
            <input class="form-input" id="hasta" type="date" name="hasta" value="{{ $filtros['hasta'] ?? '' }}">
        </div>
        <div>
            <label class="form-label" for="sede_id">Sede</label>
            <select class="form-select" id="sede_id" name="sede_id">
                <option value="">Todas</option>
                @foreach($sedes as $sede)
                    <option value="{{ $sede->id }}" @selected(($filtros['sede_id'] ?? null) == $sede->id)>{{ $sede->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="aseguradora_id">EPS / Aseguradora</label>
            <select class="form-select" id="aseguradora_id" name="aseguradora_id">
                <option value="">Todas</option>
                @foreach($aseguradoras as $a)
                    <option value="{{ $a->id }}" @selected(($filtros['aseguradora_id'] ?? null) == $a->id)>{{ $a->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div class="full filter-actions">
            <button class="btn btn-green" type="submit">Aplicar filtros</button>
            <a class="btn btn-secondary" href="{{ route('dashboard') }}">Limpiar</a>
            @can('reportes.view')
                <a class="btn btn-secondary" href="{{ route('reportes.indicadores') }}">Indicadores y reportes</a>
            @endcan
        </div>
    </div>
</form>

@if($stats['vencidos'] > 0 || $stats['vitales'] > 0 || $stats['correos_pendientes'] > 0)
    <div class="alert alert-warning" role="status">
        Alertas activas:
        @if($stats['vencidos'] > 0) {{ $stats['vencidos'] }} vencidos · @endif
        @if($stats['vitales'] > 0) {{ $stats['vitales'] }} riesgo vital · @endif
        @if($stats['correos_pendientes'] > 0) {{ $stats['correos_pendientes'] }} correos por revisar @endif
    </div>
@endif

<div class="grid-stats" role="list">
    <div class="card stat-card" role="listitem"><div class="label">Total</div><div class="value">{{ $stats['total'] }}</div></div>
    <div class="card stat-card" role="listitem"><div class="label">Nuevos hoy</div><div class="value">{{ $stats['hoy'] }}</div></div>
    <div class="card stat-card" role="listitem"><div class="label">Abiertos</div><div class="value">{{ $stats['abiertos'] }}</div></div>
    <div class="card stat-card" role="listitem"><div class="label">En gestión</div><div class="value">{{ $stats['en_gestion'] }}</div></div>
    <div class="card stat-card" role="listitem"><div class="label"><a href="{{ route('vencimientos.vencidos') }}">Vencidos</a></div><div class="value">{{ $stats['vencidos'] }}</div></div>
    <div class="card stat-card" role="listitem"><div class="label"><a href="{{ route('vencimientos.proximos') }}">Próximos a vencer</a></div><div class="value">{{ $stats['proximos'] }}</div></div>
    <div class="card stat-card" role="listitem"><div class="label">Oportunos</div><div class="value">{{ $stats['oportunos'] }}</div></div>
    <div class="card stat-card" role="listitem"><div class="label">Extemporáneos</div><div class="value">{{ $stats['extemporaneos'] }}</div></div>
    <div class="card stat-card" role="listitem"><div class="label">Riesgo vital</div><div class="value">{{ $stats['vitales'] }}</div></div>
    <div class="card stat-card" role="listitem"><div class="label">Vital menores</div><div class="value">{{ $stats['vitales_menores'] }}</div></div>
    <div class="card stat-card" role="listitem"><div class="label">Sin clasificar</div><div class="value">{{ $stats['pendientes_clasificacion'] }}</div></div>
    <div class="card stat-card" role="listitem"><div class="label">Satisfacción</div><div class="value">{{ $stats['satisfaccion_promedio'] !== null ? $stats['satisfaccion_promedio'] : 'Sin datos' }}</div><div class="muted">Solo respuestas recibidas</div></div>
    <div class="card stat-card" role="listitem"><div class="label">Participación</div><div class="value">{{ $stats['tasa_participacion'] !== null ? $stats['tasa_participacion'].'%' : 'Sin datos' }}</div><div class="muted">No responder no cuenta como satisfacción</div></div>
    <div class="card stat-card" role="listitem"><div class="label">Tiempo prom. respuesta</div><div class="value" style="font-size:22px">{{ $stats['tiempo_promedio_horas'] !== null ? $stats['tiempo_promedio_horas'].' h' : '—' }}</div></div>
</div>

<div class="form-grid">
    <div class="card card-pad">
        <h2 style="margin-top:0">Por sede</h2>
        <div class="chart-bars">
            @php $max = max(1, $indicadores['por_sede']->max('total') ?? 1); @endphp
            @forelse($indicadores['por_sede'] as $row)
                <div class="chart-bar-row">
                    <span>{{ $row->etiqueta }}</span>
                    <div class="chart-bar-track" aria-hidden="true"><div class="chart-bar-fill" style="width:{{ ($row->total / $max) * 100 }}%"></div></div>
                    <strong>{{ $row->total }}</strong>
                </div>
            @empty
                <p class="muted">Sin datos.</p>
            @endforelse
        </div>
    </div>
    <div class="card card-pad">
        <h2 style="margin-top:0">Por EPS</h2>
        <div class="chart-bars">
            @php $maxE = max(1, $indicadores['por_eps']->max('total') ?? 1); @endphp
            @forelse($indicadores['por_eps'] as $row)
                <div class="chart-bar-row">
                    <span>{{ $row->etiqueta }}</span>
                    <div class="chart-bar-track" aria-hidden="true"><div class="chart-bar-fill" style="width:{{ ($row->total / $maxE) * 100 }}%;background:var(--blue)"></div></div>
                    <strong>{{ $row->total }}</strong>
                </div>
            @empty
                <p class="muted">Sin datos.</p>
            @endforelse
        </div>
    </div>
    <div class="card card-pad">
        <h2 style="margin-top:0">Por canal</h2>
        <div class="chart-bars">
            @php $maxC = max(1, $porCanal->max('total') ?? 1); @endphp
            @forelse($porCanal as $row)
                <div class="chart-bar-row">
                    <span>{{ $row->etiqueta }}</span>
                    <div class="chart-bar-track" aria-hidden="true"><div class="chart-bar-fill" style="width:{{ ($row->total / $maxC) * 100 }}%"></div></div>
                    <strong>{{ $row->total }}</strong>
                </div>
            @empty
                <p class="muted">Sin datos.</p>
            @endforelse
        </div>
    </div>
    <div class="card card-pad">
        <h2 style="margin-top:0">Alertas prioritarias</h2>
        <h3>Vencidos</h3>
        <ul>
            @forelse($alertas['vencidos'] as $a)
                <li><a href="{{ route('pqrsd.show', $a) }}">{{ $a->radicado }}</a> — límite {{ $a->fecha_limite?->timezone('America/Bogota')->format('d/m H:i') }}</li>
            @empty
                <li class="muted">Ninguno</li>
            @endforelse
        </ul>
        <h3>Vitales abiertos</h3>
        <ul>
            @forelse($alertas['vitales'] as $a)
                <li><a href="{{ route('pqrsd.show', $a) }}">{{ $a->radicado }}</a> — {{ str_replace('_',' ', $a->nivel_riesgo) }}</li>
            @empty
                <li class="muted">Ninguno</li>
            @endforelse
        </ul>
    </div>
</div>

<div class="card card-pad" style="margin-top:18px">
    <h2 style="margin-top:0">Radicados recientes</h2>
    <div class="table-wrap">
        <table class="pqrsd-table">
            <thead>
                <tr>
                    <th>Radicado</th>
                    <th>Canal</th>
                    <th>Tipo</th>
                    <th>Sede</th>
                    <th>Cumplimiento</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recientes as $caso)
                    <tr>
                        <td><a href="{{ route('pqrsd.show', $caso) }}">{{ $caso->radicado }}</a></td>
                        <td>{{ $caso->canal?->nombre }}</td>
                        <td>{{ $caso->tipo?->nombre ?? 'Pendiente' }}</td>
                        <td>{{ $caso->sede?->nombre ?? '—' }}</td>
                        <td><x-semaforo-sla :estado="$caso->estado_cumplimiento" /></td>
                        <td>{{ $caso->fecha_radicacion?->timezone('America/Bogota')->format('d/m/Y H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
