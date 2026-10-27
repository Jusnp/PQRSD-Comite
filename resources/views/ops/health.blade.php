@extends('layouts.pqrsd')

@section('title', 'Salud operativa')
@section('heading', 'Salud operativa PQRSD')
@section('subheading', 'Gmail, scheduler, worker, colas, SLA y respaldos — sin datos clínicos')

@section('content')
@php $h = $snap['healthy'] ?? false; @endphp
<div class="alert {{ $h ? 'alert-success' : 'alert-warning' }}" role="status">
    Estado general: {{ $h ? 'saludable (checks locales)' : 'requiere atención' }} · generado {{ $snap['generated_at'] }}
</div>

<div class="grid-stats">
    <div class="card stat-card"><div class="label">Gmail OK</div><div class="value">{{ $snap['gmail']['ok'] }}/{{ $snap['gmail']['cuentas_google_api'] }}</div></div>
    <div class="card stat-card"><div class="label">Gmail error</div><div class="value">{{ $snap['gmail']['error'] }}</div></div>
    <div class="card stat-card"><div class="label">Jobs pendientes</div><div class="value">{{ $snap['queue']['pending'] }}</div></div>
    <div class="card stat-card"><div class="label">Jobs fallidos</div><div class="value">{{ $snap['queue']['failed'] }}</div></div>
    <div class="card stat-card"><div class="label">Worker activo</div><div class="value" style="font-size:18px">{{ $snap['queue']['worker_active'] ? 'Sí' : 'No' }}</div></div>
    <div class="card stat-card"><div class="label">Cron fresco (SLA&lt;5m)</div><div class="value" style="font-size:18px">{{ $snap['scheduler']['cron_parece_activo'] ? 'Sí' : 'No' }}</div></div>
</div>

<div class="form-grid" style="margin-top:18px">
    <div class="card card-pad">
        <h2 style="margin-top:0">Gmail / Workspace</h2>
        <p><strong>OAuth configurado:</strong> {{ $snap['gmail']['oauth_configured'] ? 'Sí' : 'No' }}</p>
        <p><strong>Piloto:</strong> {{ $snap['gmail']['pilot_mailbox'] ?: '— (definir GMAIL_PILOT_MAILBOX)' }}</p>
        <p><strong>Pendiente credenciales:</strong> {{ $snap['gmail']['pendiente_credenciales'] }}</p>
        <p><strong>Última sync:</strong> {{ $snap['gmail']['ultima_sync_global'] ?? '—' }}</p>
        <p class="muted">Una cuenta desconectada debe investigarse; no implica que el worker esté vivo.</p>
    </div>
    <div class="card card-pad">
        <h2 style="margin-top:0">Scheduler</h2>
        <p><strong>Último SLA:</strong> {{ $snap['scheduler']['last_sla']['estado'] ?? '—' }}
            @if(!empty($snap['scheduler']['last_sla']['iniciada_en']))
                · {{ \Illuminate\Support\Carbon::parse($snap['scheduler']['last_sla']['iniciada_en'])->timezone('America/Bogota')->format('d/m/Y H:i') }}
            @endif
        </p>
        <p><strong>Último sync correos:</strong> {{ $snap['scheduler']['last_correo_sync']['estado'] ?? '—' }}</p>
    </div>
    <div class="card card-pad">
        <h2 style="margin-top:0">Colas</h2>
        <p><strong>Conexión:</strong> {{ $snap['queue']['connection'] }}</p>
        <p><strong>Edad job más antiguo:</strong> {{ $snap['queue']['oldest_job_age_seconds'] ?? '—' }} s</p>
        <p><strong>Heartbeat worker:</strong> {{ $snap['queue']['worker_heartbeat_age_seconds'] ?? '—' }} s</p>
    </div>
    <div class="card card-pad">
        <h2 style="margin-top:0">Respaldos</h2>
        <p><strong>Último OK:</strong> #{{ $snap['backup']['last_ok_id'] ?? '—' }} · {{ $snap['backup']['last_ok_at'] ?? '—' }}</p>
        <p><strong>Cifrado:</strong> {{ $snap['backup']['cifrado'] ? 'Sí' : 'No' }}</p>
        <p><strong>Transferencia:</strong> {{ $snap['backup']['transferencia'] ?? '—' }}</p>
        <p><strong>Destino:</strong> {{ $snap['backup']['destino'] ?? '—' }}</p>
        <p class="muted">RPO/RTO pendientes de aprobación SSI:
            {{ $snap['backup']['rpo_hours_pending_approval'] ?? 'n/d' }} h /
            {{ $snap['backup']['rto_hours_pending_approval'] ?? 'n/d' }} h
        </p>
    </div>
</div>

<p class="muted" style="margin-top:18px">Alertas SLA (24h, conteo metadatos): {{ $snap['sla_alerts_24h'] }}</p>
@endsection
