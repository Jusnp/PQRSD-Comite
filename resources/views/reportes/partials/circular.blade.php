@php
    $pct = $item['pct_oportunidad'];
    $meta = $item['meta_oportunidad_pct'] ?? ($tablero['meta_oportunidad_pct'] ?? 95);
    $cumplimiento = $item['cumplimiento_meta'] ?? 'sin_datos';
    $sinDatos = $cumplimiento === 'sin_datos' || $pct === null;
    $color = match ($cumplimiento) {
        'cumple' => '#16a34a',
        'no_cumple' => '#ef4444',
        default => '#94a3b8',
    };
@endphp
<article class="circular-card">
    <div class="circular-ring" style="--pct: {{ $sinDatos ? 0 : $pct }}; --ring-color: {{ $color }};" role="img" aria-label="{{ $item['etiqueta'] }}: {{ $sinDatos ? 'Sin datos' : $pct.'% oportunidad' }}">
        <div class="circular-ring-inner">{{ $sinDatos ? '—' : $pct.'%' }}</div>
    </div>
    <h3>{{ $item['etiqueta'] }}</h3>
    <div class="muted" style="font-size:12px;line-height:1.4">
        @if($sinDatos)
            Sin datos<br>
        @else
            Oportunos: {{ $item['oportunos'] }} · Fuera: {{ $item['fuera_plazo'] }}<br>
            Meta {{ rtrim(rtrim(number_format($meta, 2, '.', ''), '0'), '.') }}%:
            {{ $cumplimiento === 'cumple' ? 'cumple' : 'no cumple' }}<br>
        @endif
        Respondidos: {{ $item['respondidos'] }} · Eval.: {{ $item['evaluables'] }}<br>
        Plazo: {{ $item['plazo'] }}
    </div>
</article>
