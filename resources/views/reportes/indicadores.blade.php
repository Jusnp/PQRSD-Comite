@extends('layouts.pqrsd')

@section('title', 'Indicadores PQRSD')
@section('heading', 'Indicadores PQRSD')
@section('subheading', 'Oportunidad de respuesta — cohorte por fecha de recepción')

@section('content')
@php
    $g = $tablero['generales'];
    $f = $filtros;
@endphp

<form method="GET" action="{{ route('reportes.indicadores') }}" class="card card-pad" style="margin-bottom:18px" aria-label="Filtros de indicadores">
    <div class="form-grid">
        <div>
            <label class="form-label" for="anio">Año</label>
            <select class="form-select" id="anio" name="anio">
                <option value="todos" @selected(($f['anio'] ?? 'todos') === 'todos')>Todos</option>
                @foreach ($anios as $anio)
                    <option value="{{ $anio }}" @selected((string) ($f['anio'] ?? '') === (string) $anio)>{{ $anio }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="mes">Mes</label>
            <select class="form-select" id="mes" name="mes">
                <option value="todos" @selected(($f['mes'] ?? 'todos') === 'todos')>Todos</option>
                @for ($m = 1; $m <= 12; $m++)
                    <option value="{{ $m }}" @selected((string) ($f['mes'] ?? '') === (string) $m)>{{ \Carbon\Carbon::create(2026, $m, 1)->translatedFormat('F') }}</option>
                @endfor
            </select>
        </div>
        <div>
            <label class="form-label" for="sede_id">Sede</label>
            <select class="form-select" id="sede_id" name="sede_id">
                <option value="todos" @selected(($f['sede_id'] ?? 'todos') === 'todos')>Todas</option>
                @foreach ($sedes as $sede)
                    <option value="{{ $sede->id }}" @selected((string) ($f['sede_id'] ?? '') === (string) $sede->id)>{{ $sede->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="aseguradora_id">Aseguradora</label>
            <select class="form-select" id="aseguradora_id" name="aseguradora_id">
                <option value="todos" @selected(($f['aseguradora_id'] ?? 'todos') === 'todos')>Todas</option>
                @foreach ($aseguradoras as $a)
                    <option value="{{ $a->id }}" @selected((string) ($f['aseguradora_id'] ?? '') === (string) $a->id)>{{ $a->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="tipo_pqrsd_id">Tipo de PQRSD</label>
            <select class="form-select" id="tipo_pqrsd_id" name="tipo_pqrsd_id">
                <option value="todos" @selected(($f['tipo_pqrsd_id'] ?? 'todos') === 'todos')>Todos</option>
                @foreach ($tipos as $tipo)
                    <option value="{{ $tipo->id }}" @selected((string) ($f['tipo_pqrsd_id'] ?? '') === (string) $tipo->id)>{{ $tipo->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="motivo_pqrsd_id">Motivo</label>
            <select class="form-select" id="motivo_pqrsd_id" name="motivo_pqrsd_id">
                <option value="todos" @selected(($f['motivo_pqrsd_id'] ?? 'todos') === 'todos')>Todos</option>
                <option value="sin_motivo" @selected(($f['motivo_pqrsd_id'] ?? '') === 'sin_motivo')>Sin motivo registrado</option>
                @foreach ($motivos as $motivo)
                    <option value="{{ $motivo->id }}" @selected((string) ($f['motivo_pqrsd_id'] ?? '') === (string) $motivo->id)>{{ $motivo->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <button class="btn btn-green" type="submit">Filtrar</button>
            <a class="btn btn-secondary" href="{{ route('reportes.indicadores') }}">Limpiar filtros</a>
        </div>
    </div>
    <p class="muted" style="margin:12px 0 0">Vista principal: cohorte por <strong>fecha de recepción</strong>. {{ $tablero['denominador_explicacion'] }}</p>
    <p class="muted" role="note">Meta institucional de oportunidad: <strong>{{ rtrim(rtrim(number_format($tablero['meta_oportunidad_pct'], 2, '.', ''), '0'), '.') }}%</strong> (fuente: configuración PQRSD_META_OPORTUNIDAD).</p>
    @if($tablero['denuncia_aprobacion_produccion_pendiente'] ?? false)
        <p class="muted" role="note">Denuncias: 10 días hábiles activos en desarrollo. Aprobación jurídica para producción pendiente.</p>
    @endif
</form>

<div class="grid-stats" style="margin-bottom:18px">
    <div class="card stat-card"><div class="label">Recibidas</div><div class="value">{{ $g['recibidas'] }}</div></div>
    <div class="card stat-card"><div class="label">Respondidas</div><div class="value">{{ $g['respondidas'] }}</div></div>
    <div class="card stat-card"><div class="label">Pendientes</div><div class="value">{{ $g['pendientes'] }}</div></div>
    <div class="card stat-card"><div class="label">Vencidas s/ respuesta</div><div class="value">{{ $g['vencidas_sin_respuesta'] }}</div></div>
    <div class="card stat-card"><div class="label">Oportunas</div><div class="value" style="color:var(--green)">{{ $g['oportunas'] }}</div></div>
    <div class="card stat-card"><div class="label">Fuera de plazo</div><div class="value" style="color:var(--red)">{{ $g['fuera_plazo'] }}</div></div>
    <div class="card stat-card">
        <div class="label">% Oportunidad</div>
        <div class="value">{{ $g['pct_oportunidad'] !== null ? $g['pct_oportunidad'].'%' : 'Sin datos' }}</div>
        <div class="muted">
            Denominador: {{ $g['evaluables'] }} evaluables · Meta {{ rtrim(rtrim(number_format($g['meta_oportunidad_pct'] ?? $tablero['meta_oportunidad_pct'], 2, '.', ''), '0'), '.') }}%:
            @if(($g['cumplimiento_meta'] ?? 'sin_datos') === 'cumple')
                <span style="color:var(--green)">cumple</span>
            @elseif(($g['cumplimiento_meta'] ?? '') === 'no_cumple')
                <span style="color:var(--red)">no cumple</span>
            @else
                sin datos
            @endif
        </div>
    </div>
    <div class="card stat-card">
        <div class="label">Tiempo promedio respuesta</div>
        <div class="value" style="font-size:18px">{{ $g['tiempo_promedio_legible'] ?? 'Sin datos' }}</div>
        <div class="muted">Solo con respuesta oficial</div>
    </div>
</div>

<section class="card card-pad" style="margin-bottom:18px" aria-labelledby="circ-riesgo">
    <h2 id="circ-riesgo" style="margin-top:0">Indicadores por nivel de riesgo</h2>
    <div class="indicadores-circulares">
        @foreach ($tablero['circulares_riesgo'] as $item)
            @include('reportes.partials.circular', ['item' => $item])
        @endforeach
    </div>
</section>

<section class="card card-pad" style="margin-bottom:18px" aria-labelledby="circ-tipo">
    <h2 id="circ-tipo" style="margin-top:0">Indicadores por tipo de manifestación</h2>
    <div class="indicadores-circulares">
        @foreach ($tablero['circulares_tipo'] as $item)
            @include('reportes.partials.circular', ['item' => $item])
        @endforeach
    </div>
</section>

<div class="form-grid" style="margin-bottom:18px">
    <section class="card card-pad" aria-labelledby="chart-barras">
        <h2 id="chart-barras" style="margin-top:0">Respondidos: oportunos vs fuera de plazo</h2>
        <p class="muted">Cantidades de casos (no porcentajes).</p>
        <canvas id="chartBarras" height="220" aria-label="Gráfica de barras de oportunidad"></canvas>
        <table class="pqrsd-table" style="margin-top:12px">
            <caption class="sr-only">Equivalente tabular de la gráfica de barras</caption>
            <thead><tr><th>Clasificación</th><th>Oportunos</th><th>Fuera de plazo</th></tr></thead>
            <tbody>
            @forelse ($tablero['barras']['labels'] as $i => $label)
                <tr>
                    <td>{{ $label }}</td>
                    <td>{{ $tablero['barras']['oportunos'][$i] }}</td>
                    <td>{{ $tablero['barras']['fuera'][$i] }}</td>
                </tr>
            @empty
                <tr><td colspan="3">Sin datos para graficar</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
    <section class="card card-pad" aria-labelledby="chart-lineas">
        <h2 id="chart-lineas" style="margin-top:0">{{ $tablero['evolucion_oportunidad']['titulo'] }}</h2>
        @php
            $evoMeta = $tablero['evolucion_oportunidad'];
            $ejeEvo = $evoMeta['eje_x'] ?? 'Mes';
        @endphp
        <p class="muted">
            Cohorte por fecha de recepción —
            @if(!empty($evoMeta['mes']))
                {{ \Carbon\Carbon::create($evoMeta['anio'], $evoMeta['mes'], 1)->translatedFormat('F Y') }}
            @else
                año {{ $evoMeta['anio'] }}
            @endif
            · Agrupación: {{ $ejeEvo }}.
        </p>
        @if($evoMeta['sin_datos_serie'] ?? false)
            <p class="muted" role="status" id="evo-sin-datos">Sin datos evaluables para el período seleccionado.</p>
        @endif
        <canvas id="chartLineas" height="220" aria-label="{{ $evoMeta['titulo'] }}" @if($evoMeta['sin_datos_serie'] ?? false) hidden @endif></canvas>
        <table class="pqrsd-table" style="margin-top:12px">
            <caption class="sr-only">Equivalente tabular de la evolución de oportunidad</caption>
            <thead>
            <tr>
                <th>{{ $ejeEvo }}</th>
                <th>Evaluables</th>
                <th>Oportunos</th>
                <th>Fuera de plazo</th>
                <th>% Oportunidad</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($evoMeta['detalle'] as $i => $d)
                <tr>
                    <td>{{ $evoMeta['labels'][$i] ?? $d['etiqueta'] }}</td>
                    <td>{{ $d['evaluables'] }}</td>
                    <td>{{ $d['oportunos'] }}</td>
                    <td>{{ $d['fuera_plazo'] }}</td>
                    <td>{{ $d['pct_oportunidad'] !== null ? $d['pct_oportunidad'].'%' : 'Sin datos' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Sin datos</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>

<section class="card card-pad" style="margin-bottom:18px" aria-labelledby="chart-volumen">
    <h2 id="chart-volumen" style="margin-top:0">{{ $tablero['volumen_por_respuesta']['titulo'] }}</h2>
    <p class="muted">{{ $tablero['volumen_por_respuesta']['nota'] }}</p>
    <canvas id="chartVolumen" height="160" aria-label="Volumen mensual recepción y respuesta"></canvas>
</section>

<section class="card card-pad" aria-labelledby="tabla-ind">
    <div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;align-items:center">
        <h2 id="tabla-ind" style="margin:0">Tabla de resultados</h2>
        <div style="display:flex;gap:8px">
            <a class="btn btn-secondary" href="{{ route('reportes.indicadores.excel', request()->query()) }}">Excel</a>
            <a class="btn btn-secondary" href="{{ route('reportes.indicadores.pdf', request()->query()) }}">PDF</a>
        </div>
    </div>
    <div class="table-wrap" style="margin-top:14px">
        <table class="pqrsd-table">
            <thead>
            <tr>
                <th>Clasificación</th>
                <th>Recibidos</th>
                <th>Respondidos</th>
                <th>Pendientes</th>
                <th>Vencidos</th>
                <th>Oportunos</th>
                <th>Fuera plazo</th>
                <th>Tiempo prom.</th>
                <th>% Oportunidad</th>
                <th>Meta {{ rtrim(rtrim(number_format($tablero['meta_oportunidad_pct'], 2, '.', ''), '0'), '.') }}%</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($tablero['tabla'] as $fila)
                <tr>
                    <td>{{ $fila['etiqueta'] }}</td>
                    <td>{{ $fila['recibidos'] }}</td>
                    <td>{{ $fila['respondidos'] }}</td>
                    <td>{{ $fila['pendientes'] }}</td>
                    <td>{{ $fila['vencidos'] }}</td>
                    <td>{{ $fila['oportunos'] }}</td>
                    <td>{{ $fila['fuera_plazo'] }}</td>
                    <td>{{ $fila['tiempo_promedio_legible'] ?? '—' }}</td>
                    <td>{{ $fila['pct_oportunidad'] !== null ? $fila['pct_oportunidad'].'%' : 'Sin datos' }}</td>
                    <td>
                        @if(($fila['cumplimiento_meta'] ?? 'sin_datos') === 'cumple') Cumple
                        @elseif(($fila['cumplimiento_meta'] ?? '') === 'no_cumple') No cumple
                        @else Sin datos
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection

@push('styles')
<style>
.indicadores-circulares {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 16px;
}
.circular-card {
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px;
    background: var(--bg-card-secondary);
    text-align: center;
}
.circular-ring {
    width: 96px;
    height: 96px;
    margin: 0 auto 10px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: conic-gradient(var(--ring-color, #94a3b8) calc(var(--pct, 0) * 1%), #cbd5e1 0);
}
html[data-theme="dark"] .circular-ring {
    background: conic-gradient(var(--ring-color, #64748b) calc(var(--pct, 0) * 1%), #334354 0);
}
.circular-ring-inner {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: var(--bg-card);
    display: grid;
    place-items: center;
    font-weight: 700;
    font-size: 15px;
}
.circular-card h3 { margin: 0 0 6px; font-size: 14px; }
.sr-only {
    position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
    overflow: hidden; clip: rect(0,0,0,0); border: 0;
}
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const text = isDark ? '#f4f7fa' : '#142033';
    const grid = isDark ? '#334354' : '#d8e0e7';
    Chart.defaults.color = text;
    Chart.defaults.borderColor = grid;

    const barras = @json($tablero['barras']);
    new Chart(document.getElementById('chartBarras'), {
        type: 'bar',
        data: {
            labels: barras.labels,
            datasets: [
                { label: 'Oportunos', data: barras.oportunos, backgroundColor: '#16a34a' },
                { label: 'Fuera de plazo', data: barras.fuera, backgroundColor: '#ef4444' }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });

    const evo = @json($tablero['evolucion_oportunidad']);
    const chartLineasEl = document.getElementById('chartLineas');
    if (chartLineasEl && !evo.sin_datos_serie) {
        const nLabels = (evo.labels || []).length || 1;
        const datasets = [
            { label: '% Oportunas', data: evo.pct_oportuno, borderColor: '#16a34a', tension: 0.25, spanGaps: true },
            { label: '% Fuera de plazo', data: evo.pct_fuera, borderColor: '#ef4444', tension: 0.25, spanGaps: true }
        ];
        if (evo.meta !== null && evo.meta !== undefined) {
            datasets.push({
                label: 'Meta institucional (' + evo.meta + '%)',
                data: Array(nLabels).fill(evo.meta),
                borderColor: '#3b82f6',
                borderDash: [6, 4],
                pointRadius: 0,
                tension: 0
            });
        }
        new Chart(chartLineasEl, {
            type: 'line',
            data: { labels: evo.labels, datasets },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            title: function (items) {
                                const i = items[0]?.dataIndex ?? 0;
                                const d = evo.detalle[i] || {};
                                return d.etiqueta || items[0]?.label || '';
                            },
                            afterBody: function (items) {
                                const i = items[0]?.dataIndex ?? 0;
                                const d = evo.detalle[i] || {};
                                const pct = d.pct_oportunidad;
                                return [
                                    'Período: ' + (d.etiqueta || ''),
                                    'Respuestas evaluables: ' + (d.evaluables ?? 0),
                                    'Respuestas oportunas: ' + (d.oportunos ?? 0),
                                    'Fuera de plazo: ' + (d.fuera_plazo ?? 0),
                                    'Oportunidad: ' + (pct === null || pct === undefined ? 'Sin datos' : pct + '%')
                                ];
                            }
                        }
                    }
                },
                scales: {
                    x: { title: { display: true, text: evo.eje_x || 'Período' } },
                    y: { min: 0, max: 100, title: { display: true, text: '% oportunidad' } }
                }
            }
        });
    }

    const vol = @json($tablero['volumen_por_respuesta']);
    new Chart(document.getElementById('chartVolumen'), {
        type: 'bar',
        data: {
            labels: vol.labels,
            datasets: [
                { label: 'Recibidas (por recepción)', data: vol.recibidas, backgroundColor: '#2563eb' },
                { label: 'Respondidas (por fecha respuesta)', data: vol.respondidas, backgroundColor: '#7c3aed' }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });
});
</script>
@endpush
