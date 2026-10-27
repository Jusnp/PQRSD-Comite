@extends('layouts.pqrsd')
@section('title', 'Reportes')
@section('heading', 'Reportes e indicadores')
@section('subheading', 'Inventario filtrable — exportación administrativa (no sustituye anexos oficiales Supersalud)')

@section('content')
<div class="alert alert-info" role="note">
    Estos reportes son de gestión interna. Antes de usarlos ante autoridades, valide estructuras y obligaciones regulatorias aplicables.
    El porcentaje de oportunidad usa la fecha de respuesta y la fecha límite, la misma regla del tablero de indicadores.
    El estado guardado del expediente se conserva como antecedente y puede verse en la columna «Cumplimiento almacenado» de la exportación.
</div>

<form method="GET" class="card card-pad" style="margin-bottom:18px" aria-label="Filtros de reporte">
    <div class="form-grid">
        <div><label class="form-label">Desde</label><input class="form-input" type="date" name="desde" value="{{ $filtros['desde'] ?? '' }}"></div>
        <div><label class="form-label">Hasta</label><input class="form-input" type="date" name="hasta" value="{{ $filtros['hasta'] ?? '' }}"></div>
        <div>
            <label class="form-label">Sede</label>
            <select class="form-select" name="sede_id">
                <option value="">Todas</option>
                @foreach($sedes as $s)<option value="{{ $s->id }}" @selected(($filtros['sede_id'] ?? null)==$s->id)>{{ $s->nombre }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="form-label">EPS</label>
            <select class="form-select" name="aseguradora_id">
                <option value="">Todas</option>
                @foreach($aseguradoras as $a)<option value="{{ $a->id }}" @selected(($filtros['aseguradora_id'] ?? null)==$a->id)>{{ $a->nombre }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Tipo</label>
            <select class="form-select" name="tipo_pqrsd_id">
                <option value="">Todos</option>
                @foreach($tipos as $t)<option value="{{ $t->id }}" @selected(($filtros['tipo_pqrsd_id'] ?? null)==$t->id)>{{ $t->nombre }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Riesgo</label>
            <select class="form-select" name="nivel_riesgo">
                <option value="">Todos</option>
                @foreach(['vital_menor','vital_general','priorizado','simple','ordinario','pendiente'] as $r)
                    <option value="{{ $r }}" @selected(($filtros['nivel_riesgo'] ?? null)===$r)>{{ str_replace('_',' ',$r) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Responsable</label>
            <select class="form-select" name="responsable_id">
                <option value="">Todos</option>
                @foreach($responsables as $u)<option value="{{ $u->id }}" @selected(($filtros['responsable_id'] ?? null)==$u->id)>{{ $u->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Cumplimiento</label>
            <select class="form-select" name="estado_cumplimiento">
                <option value="">Todos</option>
                @foreach(['dentro_plazo','proximo_vencer','vencido','resuelto_oportuno','resuelto_extemporaneo','sin_clasificar'] as $c)
                    <option value="{{ $c }}" @selected(($filtros['estado_cumplimiento'] ?? null)===$c)>{{ str_replace('_',' ',$c) }}</option>
                @endforeach
            </select>
        </div>
        <div class="full filter-actions">
            <button class="btn btn-green" type="submit">Filtrar</button>
            <a class="btn btn-secondary" href="{{ route('reportes.excel', request()->query()) }}">Exportar Excel</a>
            <a class="btn btn-secondary" href="{{ route('reportes.pdf', request()->query()) }}">Exportar PDF</a>
        </div>
    </div>
</form>

<div class="grid-stats">
    <div class="card stat-card"><div class="label">Total filtrado</div><div class="value">{{ $indicadores['total'] }}</div></div>
    <div class="card stat-card"><div class="label">Vencidos</div><div class="value">{{ $indicadores['vencidos'] }}</div></div>
    <div class="card stat-card"><div class="label">Oportunos</div><div class="value">{{ $indicadores['oportunos'] }}</div></div>
    <div class="card stat-card"><div class="label">Extemporáneos</div><div class="value">{{ $indicadores['extemporaneos'] }}</div></div>
    <div class="card stat-card"><div class="label">% oportunidad</div><div class="value" style="font-size:22px">{{ $indicadores['pct_oportunidad'] !== null ? $indicadores['pct_oportunidad'].'%' : '—' }}</div></div>
    <div class="card stat-card"><div class="label">Tiempo prom. (h)</div><div class="value" style="font-size:22px">{{ $indicadores['tiempo_promedio_horas'] ?? '—' }}</div></div>
</div>

<div class="form-grid" style="margin-bottom:18px">
    @foreach([
        'por_sede' => 'Por sede',
        'por_eps' => 'Por EPS',
        'por_tipo' => 'Por tipo',
        'por_riesgo' => 'Por riesgo',
        'por_responsable' => 'Por responsable',
    ] as $key => $titulo)
        <div class="card card-pad">
            <h2 style="margin-top:0">{{ $titulo }}</h2>
            <div class="chart-bars">
                @php $max = max(1, $indicadores[$key]->max('total') ?? 1); @endphp
                @forelse($indicadores[$key] as $row)
                    <div class="chart-bar-row">
                        <span>{{ str_replace('_',' ', $row->etiqueta) }}</span>
                        <div class="chart-bar-track"><div class="chart-bar-fill" style="width:{{ ($row->total/$max)*100 }}%"></div></div>
                        <strong>{{ $row->total }}</strong>
                    </div>
                @empty
                    <p class="muted">Sin datos</p>
                @endforelse
            </div>
        </div>
    @endforeach
</div>

<div class="card card-pad table-wrap">
    <table class="pqrsd-table">
        <thead>
            <tr>
                <th>Radicado</th><th>Recepción</th><th>Tipo</th><th>Riesgo</th><th>EPS</th><th>Sede</th>
                <th>Responsable</th><th>Cumplimiento</th><th>Límite</th><th>Respuesta</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $caso)
                <tr>
                    <td><a href="{{ route('pqrsd.show', $caso) }}">{{ $caso->radicado }}</a></td>
                    <td>{{ $caso->fecha_recepcion?->timezone('America/Bogota')->format('d/m/Y H:i') }}</td>
                    <td>{{ $caso->tipo?->nombre ?? '—' }}</td>
                    <td>{{ str_replace('_',' ', $caso->nivel_riesgo) }}</td>
                    <td>{{ $caso->aseguradora?->nombre ?? '—' }}</td>
                    <td>{{ $caso->sede?->nombre ?? '—' }}</td>
                    <td>{{ $caso->responsable?->name ?? '—' }}</td>
                    <td><x-semaforo-sla :estado="$caso->cumplimientoVigente()" :mostrar-texto="false" /> {{ str_replace('_',' ', $caso->cumplimientoVigente()) }}</td>
                    <td>{{ $caso->fecha_limite?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</td>
                    <td>{{ $caso->fecha_respuesta?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    {{ $items->links() }}
</div>
@endsection
