@extends('layouts.pqrsd')

@section('title', 'Indicadores y reportes')
@section('heading', 'Indicadores y reportes')
@section('subheading', 'Oportunidad de respuesta — cohorte por fecha de recepción. Meta institucional 95 %.')

@section('content')
@php
    $g = $tablero['generales'];
    $f = $filtros;
    $consulta = $consulta ?? request()->query();
    $avanzadosAbiertos = filled($f['desde'] ?? null)
        || filled($f['hasta'] ?? null)
        || filled($f['nivel_riesgo'] ?? null)
        || filled($f['responsable_id'] ?? null)
        || filled($f['estado_cumplimiento'] ?? null);
@endphp
<style>
.filtros-avanzados[hidden]{display:none!important}
</style>

<div class="grid-stats" style="margin-bottom:18px">
    <div class="card stat-card"><div class="label">Casos totales</div><div class="value">{{ $g['recibidas'] }}</div></div>
    <div class="card stat-card"><div class="label">Pendientes de clasificación</div><div class="value">{{ $g['pendientes_clasificacion'] ?? 0 }}</div></div>
    <div class="card stat-card"><div class="label">Próximos a vencer</div><div class="value">{{ $g['proximas_vencer'] ?? 0 }}</div></div>
    <div class="card stat-card"><div class="label">Vencidos</div><div class="value">{{ $g['vencidas'] ?? 0 }}</div></div>
    <div class="card stat-card"><div class="label">Respondidos</div><div class="value">{{ $g['respondidas'] }}</div></div>
    <div class="card stat-card"><div class="label">Cerrados</div><div class="value">{{ $g['cerradas'] ?? 0 }}</div></div>
    <div class="card stat-card">
        <div class="label">Porcentaje de oportunidad</div>
        <div class="value">{{ $g['pct_oportunidad'] !== null ? $g['pct_oportunidad'].'%' : 'Sin datos' }}</div>
        <div class="muted">Meta {{ rtrim(rtrim(number_format($g['meta_oportunidad_pct'] ?? $tablero['meta_oportunidad_pct'], 2, '.', ''), '0'), '.') }}%</div>
    </div>
    <div class="card stat-card"><div class="label">Satisfacción del ciudadano</div><div class="value">{{ ($g['satisfaccion_promedio'] ?? null) !== null ? $g['satisfaccion_promedio'] : 'Sin datos' }}</div><div class="muted">Sin encuestas no se asume un valor positivo</div></div>
</div>

<form method="GET" action="{{ route('reportes.indicadores') }}" class="card card-pad" style="margin-bottom:18px" aria-label="Filtros de indicadores">
    @if(!empty($erroresFiltro))
        <div class="alert alert-error" role="alert">
            @foreach($erroresFiltro as $mensaje)
                <div>{{ $mensaje }}</div>
            @endforeach
        </div>
    @endif
    <div class="form-grid">
        <div class="full"><strong>Filtros principales</strong></div>
        <div>
            <label class="form-label" for="anio">Año de recepción</label>
            <select class="form-select" id="anio" name="anio">
                <option value="todos" @selected(($f['anio'] ?? 'todos') === 'todos')>Todos</option>
                @foreach ($anios as $anio)
                    <option value="{{ $anio }}" @selected((string) ($f['anio'] ?? '') === (string) $anio)>{{ $anio }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="mes">Mes de recepción</label>
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
            <label class="form-label" for="aseguradora_id">EPS / Aseguradora</label>
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
        <div class="full">
            <button type="button" class="btn btn-secondary" id="btn-mas-filtros-reportes" aria-expanded="{{ $avanzadosAbiertos ? 'true' : 'false' }}" aria-controls="filtros-reportes-avanzados">
                {{ $avanzadosAbiertos ? 'Ocultar más filtros' : 'Más filtros' }}
            </button>
        </div>
        <div id="filtros-reportes-avanzados" class="form-grid full filtros-avanzados" @if(! $avanzadosAbiertos) hidden @endif>
            <div class="full"><strong>Más filtros</strong></div>
            <div>
                <label class="form-label" for="desde">Recepción desde</label>
                <input class="form-input" id="desde" type="date" name="desde" value="{{ $f['desde'] ?? '' }}">
            </div>
            <div>
                <label class="form-label" for="hasta">Recepción hasta</label>
                <input class="form-input" id="hasta" type="date" name="hasta" value="{{ $f['hasta'] ?? '' }}">
            </div>
            <div>
                <label class="form-label" for="nivel_riesgo">Nivel de riesgo</label>
                <select class="form-select" id="nivel_riesgo" name="nivel_riesgo">
                    <option value="">Todos</option>
                    @foreach (\App\Services\IndicadoresPqrsdService::NIVELES_RIESGO as $riesgo)
                        <option value="{{ $riesgo }}" @selected(($f['nivel_riesgo'] ?? '') === $riesgo)>{{ str_replace('_', ' ', $riesgo) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label" for="responsable_id">Responsable registrado</label>
                <select class="form-select" id="responsable_id" name="responsable_id">
                    <option value="">Todos</option>
                    @foreach ($responsables as $responsable)
                        <option value="{{ $responsable->id }}" @selected((string) ($f['responsable_id'] ?? '') === (string) $responsable->id)>{{ $responsable->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label" for="estado_cumplimiento">Estado de cumplimiento</label>
                <select class="form-select" id="estado_cumplimiento" name="estado_cumplimiento">
                    <option value="">Todos</option>
                    @foreach (\App\Services\IndicadoresPqrsdService::ESTADOS_CUMPLIMIENTO as $cumplimiento)
                        <option value="{{ $cumplimiento }}" @selected(($f['estado_cumplimiento'] ?? '') === $cumplimiento)>{{ str_replace('_', ' ', $cumplimiento) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="full" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <button class="btn btn-green" type="submit">Filtrar</button>
            <a class="btn btn-secondary" href="{{ route('reportes.indicadores') }}">Limpiar filtros</a>
        </div>
    </div>
    <p class="muted" style="margin:12px 0 0">La cohorte, el año, el mes y el rango desde/hasta usan la <strong>fecha de recepción</strong>. {{ $tablero['denominador_explicacion'] }}</p>
    <p class="muted" role="note">El cumplimiento se calcula con la fecha de respuesta y la fecha límite, no con el estado guardado del expediente.</p>
    <p class="muted" role="note">El responsable es el que quedó registrado en el expediente. La bandeja compartida no asigna uno de forma automática; si elige una persona, solo aparecen los expedientes que ya la tienen.</p>
    <p class="muted" role="note">Meta institucional de oportunidad: <strong>{{ rtrim(rtrim(number_format($tablero['meta_oportunidad_pct'], 2, '.', ''), '0'), '.') }}%</strong> (fuente: configuración PQRSD_META_OPORTUNIDAD).</p>
    @if($tablero['denuncia_aprobacion_produccion_pendiente'] ?? false)
        <p class="muted" role="note">Denuncias: 10 días hábiles activos en desarrollo. Aprobación jurídica para producción pendiente.</p>
    @endif
</form>

<section class="card card-pad" style="margin-bottom:18px" aria-labelledby="graficos-admin">
    <h2 id="graficos-admin" style="margin-top:0">Distribución administrativa</h2>
    <p class="muted">Las cifras de estas gráficas salen de la misma cohorte que las tarjetas y las exportaciones. Si no hay encuestas, no se dibuja satisfacción.</p>
    <div class="form-grid">
        <div>
            <h3>Por categoría</h3>
            <canvas id="chartCategoria" height="180" aria-label="Casos por categoría"></canvas>
            <table class="pqrsd-table"><tbody>
                @forelse($tablero['por_categoria'] ?? [] as $fila)
                    <tr><td>{{ $fila['etiqueta'] }}</td><td>{{ $fila['total'] }}</td></tr>
                @empty
                    <tr><td colspan="2">Sin datos de categoría.</td></tr>
                @endforelse
            </tbody></table>
        </div>
        <div>
            <h3>Por categoría y subcategoría</h3>
            <canvas id="chartSubcategoria" height="180" aria-label="Casos por subcategoría"></canvas>
            <table class="pqrsd-table"><tbody>
                @forelse($tablero['por_subcategoria'] ?? [] as $fila)
                    <tr><td>{{ $fila['etiqueta'] }}</td><td>{{ $fila['total'] }}</td></tr>
                @empty
                    <tr><td colspan="2">Sin datos de subcategoría.</td></tr>
                @endforelse
            </tbody></table>
        </div>
        <div>
            <h3>Vencidos por sede</h3>
            <canvas id="chartVencidosSede" height="180" aria-label="Casos vencidos por sede"></canvas>
            <table class="pqrsd-table"><tbody>
                @forelse($tablero['vencidos_por_sede'] ?? [] as $fila)
                    <tr><td>{{ $fila['etiqueta'] }}</td><td>{{ $fila['total'] }}</td></tr>
                @empty
                    <tr><td colspan="2">No hay casos vencidos en la cohorte.</td></tr>
                @endforelse
            </tbody></table>
        </div>
        <div>
            <h3>Satisfacción por sede</h3>
            <canvas id="chartSatisfaccionSede" height="180" aria-label="Satisfacción por sede"></canvas>
            <table class="pqrsd-table"><tbody>
                @forelse($tablero['satisfaccion_por_sede'] ?? [] as $fila)
                    <tr><td>{{ $fila['etiqueta'] }}</td><td>{{ $fila['promedio'] !== null ? $fila['promedio'].' ('.$fila['encuestas'].' encuestas)' : 'Sin encuestas' }}</td></tr>
                @empty
                    <tr><td colspan="2">Sin encuestas.</td></tr>
                @endforelse
            </tbody></table>
        </div>
        <div>
            <h3>Satisfacción por tipo</h3>
            <canvas id="chartSatisfaccionTipo" height="180" aria-label="Satisfacción por tipo"></canvas>
            <table class="pqrsd-table"><tbody>
                @forelse($tablero['satisfaccion_por_tipo'] ?? [] as $fila)
                    <tr><td>{{ $fila['etiqueta'] }}</td><td>{{ $fila['promedio'] !== null ? $fila['promedio'].' ('.$fila['encuestas'].' encuestas)' : 'Sin encuestas' }}</td></tr>
                @empty
                    <tr><td colspan="2">Sin encuestas.</td></tr>
                @endforelse
            </tbody></table>
        </div>
    </div>
    <p class="muted">La evolución mensual sigue la cohorte de recepción. La meta institucional permanece en {{ rtrim(rtrim(number_format($tablero['meta_oportunidad_pct'], 2, '.', ''), '0'), '.') }}%.</p>
</section>

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

<section class="card card-pad" id="soportes" style="margin-top:18px" aria-labelledby="soportes-titulo">
    <h2 id="soportes-titulo" style="margin-top:0">Reportes y soportes</h2>
    <p class="muted">El PDF no tiene firma digital certificada. Las exportaciones usan los mismos filtros visibles y no cambian la fórmula de oportunidad.</p>
    <div class="table-wrap">
        <table class="pqrsd-table">
            <thead>
                <tr>
                    <th>Radicado</th>
                    <th>Recepción</th>
                    <th>Tipo</th>
                    <th>Sede</th>
                    <th>Cumplimiento</th>
                    <th>Soportes</th>
                </tr>
            </thead>
            <tbody>
            @forelse($expedientes as $caso)
                <tr>
                    <td><a href="{{ route('pqrsd.show', $caso) }}">{{ $caso->radicado }}</a></td>
                    <td>{{ $caso->fecha_recepcion?->timezone('America/Bogota')->format('d/m/Y H:i') }}</td>
                    <td>{{ $caso->tipo?->nombre ?? 'En clasificación' }}</td>
                    <td>{{ $caso->sede?->nombre ?? '—' }}</td>
                    <td>{{ str_replace('_', ' ', $caso->cumplimientoVigente()) }}</td>
                    <td>
                        <a href="{{ route('reportes.expediente.pdf', $caso) }}">PDF</a>
                        · <a href="{{ route('reportes.expediente.zip', $caso) }}">Expediente completo</a>
                        · <a href="{{ route('pqrsd.show', $caso) }}#sec-historial">Historial</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No hay expedientes en esta cohorte.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:12px">{{ $expedientes->fragment('soportes')->links() }}</div>
    <h3 style="margin:18px 0 8px">Exportaciones</h3>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
        <a class="btn btn-secondary" href="{{ route('reportes.excel', $consulta) }}">Exportar Excel</a>
        <a class="btn btn-secondary" href="{{ route('reportes.pdf', $consulta) }}">Exportar PDF</a>
        <a class="btn btn-secondary" href="{{ route('reportes.indicadores.excel', $consulta) }}">Excel de indicadores</a>
        <a class="btn btn-secondary" href="{{ route('reportes.indicadores.pdf', $consulta) }}">PDF de indicadores</a>
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
<script>
(function () {
    const btn = document.getElementById('btn-mas-filtros-reportes');
    const box = document.getElementById('filtros-reportes-avanzados');
    if (!btn || !box) return;
    btn.addEventListener('click', function () {
        const open = box.hasAttribute('hidden');
        box.toggleAttribute('hidden', !open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.textContent = open ? 'Ocultar más filtros' : 'Más filtros';
    });
})();
</script>
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

    function graficoConteo(id, filas, color) {
        const el = document.getElementById(id);
        if (!el || !Array.isArray(filas) || filas.length === 0) return;
        new Chart(el, {
            type: 'bar',
            data: {
                labels: filas.map(f => f.etiqueta),
                datasets: [{ label: 'Casos', data: filas.map(f => f.total), backgroundColor: color }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }
    function graficoPromedio(id, filas, color) {
        const el = document.getElementById(id);
        if (!el) return;
        const utiles = (filas || []).filter(f => f.encuestas > 0 && f.promedio !== null);
        if (utiles.length === 0) {
            el.hidden = true;
            return;
        }
        new Chart(el, {
            type: 'bar',
            data: {
                labels: utiles.map(f => f.etiqueta),
                datasets: [{ label: 'Promedio (1 a 5)', data: utiles.map(f => f.promedio), backgroundColor: color }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, max: 5 } }
            }
        });
    }
    graficoConteo('chartCategoria', @json($tablero['por_categoria'] ?? []), '#2563eb');
    graficoConteo('chartSubcategoria', @json($tablero['por_subcategoria'] ?? []), '#0f766e');
    graficoConteo('chartVencidosSede', @json($tablero['vencidos_por_sede'] ?? []), '#ef4444');
    graficoPromedio('chartSatisfaccionSede', @json($tablero['satisfaccion_por_sede'] ?? []), '#d97706');
    graficoPromedio('chartSatisfaccionTipo', @json($tablero['satisfaccion_por_tipo'] ?? []), '#7c3aed');

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
