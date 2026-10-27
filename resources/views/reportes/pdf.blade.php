<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte PQRSD</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #142033; }
        h1 { font-size: 16px; margin: 0 0 6px; }
        .muted { color: #58718c; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5df; padding: 4px 6px; text-align: left; }
        th { background: #f4f7f6; }
        .stats td { border: 0; padding: 2px 8px 2px 0; }
    </style>
</head>
<body>
    <h1>Reporte PQRSD — {{ config('identidad.nombre_corto') }}</h1>
    <div class="muted">Generado: {{ $generado->format('d/m/Y H:i') }} (America/Bogota). Uso administrativo interno.</div>
    <table class="stats">
        <tr>
            <td><strong>Total:</strong> {{ $indicadores['total'] }}</td>
            <td><strong>Vencidos:</strong> {{ $indicadores['vencidos'] }}</td>
            <td><strong>Oportunos:</strong> {{ $indicadores['oportunos'] }}</td>
            <td><strong>Extemporáneos:</strong> {{ $indicadores['extemporaneos'] }}</td>
            <td><strong>% oportunidad:</strong> {{ $indicadores['pct_oportunidad'] !== null ? $indicadores['pct_oportunidad'].'%' : '—' }}</td>
            <td><strong>Tiempo prom. h:</strong> {{ $indicadores['tiempo_promedio_horas'] ?? '—' }}</td>
        </tr>
    </table>
    <br>
    <table>
        <thead>
            <tr>
                @foreach(array_keys($rows->first() ?? ['Radicado'=>null]) as $h)
                    <th>{{ $h }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
