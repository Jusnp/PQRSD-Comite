<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Indicadores PQRSD</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #142033; }
        h1 { font-size: 16px; margin: 0 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #cbd5df; padding: 5px 6px; text-align: left; }
        th { background: #f4f7f6; }
        .muted { color: #58718c; }
    </style>
</head>
<body>
    <h1>Indicadores PQRSD — {{ config('identidad.nombre_corto') }}</h1>
    <p class="muted">Generado: {{ $generado->format('d/m/Y H:i') }} (America/Bogota). Cohorte: fecha de recepción.</p>
    <p class="muted">
        Recibidas: {{ $tablero['generales']['recibidas'] }} ·
        Respondidas: {{ $tablero['generales']['respondidas'] }} ·
        % Oportunidad: {{ $tablero['generales']['pct_oportunidad'] ?? 'Sin datos' }}
        (evaluables: {{ $tablero['generales']['evaluables'] }}) ·
        Meta: {{ $tablero['meta_oportunidad_pct'] }}% ·
        Cumplimiento: {{ $tablero['generales']['cumplimiento_meta'] ?? 'sin_datos' }}
    </p>
    <table>
        <thead>
        <tr>
            <th>Clasificación</th>
            <th>Recibidos</th>
            <th>Respondidos</th>
            <th>Pendientes</th>
            <th>Vencidos</th>
            <th>Oportunos</th>
            <th>Fuera</th>
            <th>%</th>
            <th>Meta</th>
            <th>Cumple</th>
            <th>Tiempo prom.</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row['Clasificación'] }}</td>
                <td>{{ $row['Recibidos'] }}</td>
                <td>{{ $row['Respondidos'] }}</td>
                <td>{{ $row['Pendientes'] }}</td>
                <td>{{ $row['Vencidos'] }}</td>
                <td>{{ $row['Oportunos'] }}</td>
                <td>{{ $row['Fuera de plazo'] }}</td>
                <td>{{ $row['% Oportunidad'] }}</td>
                <td>{{ $row['Meta %'] ?? $tablero['meta_oportunidad_pct'] }}</td>
                <td>{{ $row['Cumple meta'] ?? '—' }}</td>
                <td>{{ $row['Tiempo promedio'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
