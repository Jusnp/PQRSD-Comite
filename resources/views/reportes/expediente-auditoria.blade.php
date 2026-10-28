<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe {{ $caso->radicado }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 0; }
        h2 { font-size: 14px; margin-top: 18px; }
        .aviso { background: #f3f4f6; padding: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d1d5db; padding: 4px 6px; vertical-align: top; text-align: left; }
    </style>
</head>
<body>
    <h1>{{ config('identidad.nombre_sistema') }}</h1>
    <p>Informe de expediente para auditoría interna. Generado el {{ $generado->format('d/m/Y H:i') }}.</p>
    <p class="aviso">Este PDF no tiene firma digital certificada. La integridad del paquete, cuando se descarga en ZIP, se verifica con los hashes del manifiesto.</p>

    <h2>1. Radicado</h2>
    <p>{{ $caso->radicado }}</p>

    <h2>2. Solicitante</h2>
    <p>{{ $caso->es_anonimo ? 'Radicación anónima. No se imprime identidad.' : trim(($caso->solicitante_tipo_documento.' '.$caso->solicitante_documento.' '.$caso->solicitante_nombre)) }}</p>
    @unless($caso->es_anonimo)
        <p>Teléfono: {{ $caso->solicitante_telefono ?: '—' }}</p>
    @endunless

    <h2>3. Recepción</h2>
    <p>{{ $caso->fecha_recepcion?->timezone('America/Bogota')->format('d/m/Y H:i') }} · Canal {{ $caso->canal?->nombre }} · Sede {{ $caso->sede?->nombre ?? '—' }} · EPS {{ $caso->aseguradora?->nombre ?? '—' }}</p>

    <h2>4. Motivo</h2>
    <p>{{ $caso->motivo?->nombre ?? 'Sin motivo' }}</p>
    <p>{{ $caso->descripcion }}</p>

    <h2>5. Clasificación y riesgo</h2>
    <p>Tipo: {{ $caso->tipo?->nombre ?? 'Pendiente de clasificación' }}</p>
    <p>Categoría: {{ $caso->categoria?->nombre ?? '—' }} / {{ $caso->subcategoria?->nombre ?? '—' }}</p>
    <p>Riesgo: {{ str_replace('_', ' ', $caso->nivel_riesgo) }}</p>
    @if($caso->alerta_vital_preventiva)
        <p>Alerta preventiva de posible riesgo vital, previa a la clasificación formal.</p>
    @endif

    <h2>6. Fecha límite</h2>
    <p>{{ $caso->fecha_limite?->timezone('America/Bogota')->format('d/m/Y H:i') ?? 'Aún sin plazo formal. Se calcula desde la recepción original al clasificar.' }}</p>
    <p>Regla: {{ $caso->reglaSla?->codigo ?? '—' }}</p>

    <h2>7. Actuaciones</h2>
    <table>
        <thead><tr><th>Fecha</th><th>Funcionario</th><th>Gestión</th></tr></thead>
        <tbody>
        @forelse($caso->actuaciones->sortBy('created_at') as $act)
            <tr>
                <td>{{ $act->created_at?->timezone('America/Bogota')->format('d/m/Y H:i') }}</td>
                <td>{{ $act->usuario?->name ?? 'Sistema' }}</td>
                <td>{{ $act->titulo }}</td>
            </tr>
        @empty
            <tr><td colspan="3">Sin actuaciones.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>8. Cambios de clasificación</h2>
    <table>
        <thead><tr><th>Fecha</th><th>Quién</th><th>Campo</th><th>Anterior</th><th>Nuevo</th><th>Motivo</th></tr></thead>
        <tbody>
        @forelse($caso->historialEstados->sortBy('created_at') as $hist)
            <tr>
                <td>{{ $hist->created_at?->timezone('America/Bogota')->format('d/m/Y H:i') }}</td>
                <td>{{ $hist->usuario?->name ?? 'Sistema' }}</td>
                <td>{{ $hist->campo }}</td>
                <td>{{ $hist->valor_anterior }}</td>
                <td>{{ $hist->valor_nuevo }}</td>
                <td>{{ $hist->motivo }}</td>
            </tr>
        @empty
            <tr><td colspan="6">Sin cambios registrados.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>9. Respuesta</h2>
    <p>{{ $caso->respuesta_consulta_texto ?: 'Sin respuesta publicada.' }}</p>
    <p>Fecha de respuesta: {{ $caso->fecha_respuesta?->timezone('America/Bogota')->format('d/m/Y H:i') ?? '—' }}</p>

    <h2>10. Evidencia de comunicación</h2>
    <p>{{ $caso->respuesta_evidencia_comunicacion ?: 'Sin evidencia registrada.' }}</p>
    <p>Canal: {{ $caso->respuesta_canal_comunicacion ?: '—' }}</p>

    <h2>11. Cierre</h2>
    <p>{{ $caso->fecha_cierre?->timezone('America/Bogota')->format('d/m/Y H:i') ?? 'Abierto' }}</p>

    <h2>12. Cumplimiento</h2>
    <p>{{ str_replace('_', ' ', $caso->cumplimientoVigente()) }}</p>

    <h2>13. Encuesta</h2>
    @if($caso->encuesta)
        <p>Puntuación {{ $caso->encuesta->puntuacion }}/5. Atención: {{ $caso->encuesta->atendida }}. {{ $caso->encuesta->comentario }}</p>
    @else
        <p>Sin encuesta. La ausencia de respuesta no se interpreta como satisfacción.</p>
    @endif

    <h2>14. Soportes</h2>
    <ul>
        @forelse($caso->adjuntos as $adjunto)
            <li>{{ $adjunto->nombre_original }} ({{ $adjunto->tipo }})</li>
        @empty
            <li>Sin archivos.</li>
        @endforelse
    </ul>

    <h2>15. Generación</h2>
    <p>{{ $generado->timezone('America/Bogota')->format('d/m/Y H:i') }}</p>
</body>
</html>
