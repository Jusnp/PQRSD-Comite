@extends('layouts.publico')
@section('title', 'Confirmación')
@section('heading', 'Solicitud recibida')
@section('subheading', 'Conserve su número de radicado')

@section('content')
<div class="card card-pad" id="confirmacion-exito" data-confirmacion="1">
    <div class="alert alert-success" role="status">Su PQRSD fue radicada correctamente.</div>
    <p><strong>Número de radicado:</strong>
        <code id="radicado-valor" style="font-size:1.2em">{{ $radicado }}</code>
        <button type="button" class="btn btn-secondary" id="btn-copiar-radicado" style="margin-left:8px">Copiar radicado</button>
    </p>
    @if($caso?->fecha_recepcion)
        <p><strong>Fecha y hora de recepción:</strong>
            {{ $caso->fecha_recepcion->timezone('America/Bogota')->format('d/m/Y H:i') }} (hora Colombia)
        </p>
    @endif
    @if($caso?->tipo)
        <p><strong>Tipo de solicitud:</strong> {{ $caso->tipo->nombre }}</p>
    @endif
    @if($caso?->medio_respuesta)
        <p><strong>Medio de respuesta elegido:</strong>
            {{ \App\Support\MedioRespuesta::etiqueta($caso->medio_respuesta) }}
        </p>
    @endif
    @if($caso?->es_anonimo)
        <p class="muted">Solicitud presentada sin revelar identidad.</p>
    @endif

    <p class="muted">
        Con el número de radicado puede consultar el <strong>estado general</strong> (Recibida, En trámite o Finalizada)
        en cualquier momento. El radicado no prueba identidad ni abre la respuesta completa.
    </p>

    @if($requiereCodigoPrivado ?? false)
        <details class="exp-audit-details" style="margin-top:12px" open>
            <summary style="cursor:pointer;font-weight:700">Código privado para la respuesta completa (consulta segura)</summary>
            <p class="muted">Conserve este código aparte. Solo es necesario para ver el texto de la respuesta cuando esté disponible. No lo use para el estado general.</p>
            <p><code id="token-valor" style="word-break:break-all">{{ $token }}</code>
                <button type="button" class="btn btn-secondary" id="btn-copiar-token" style="margin-left:8px">Copiar código</button>
            </p>
        </details>
    @else
        <p class="muted">La respuesta se entregará por el medio que usted eligió. El estado general se consulta solo con el número de radicado.</p>
    @endif

    <p class="muted">{{ config('identidad.textos.personal_revision') }}</p>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;align-items:center">
        <a class="btn btn-green" href="{{ route('publico.consulta') }}">Consultar estado</a>
        <a class="btn btn-secondary" href="{{ route('publico.formulario') }}">Nueva solicitud</a>
        <button type="button" class="btn btn-secondary" id="btn-reproducir-sonido" hidden>Escuchar confirmación</button>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    async function copiar(texto, okMsg) {
        try {
            await navigator.clipboard.writeText(texto);
            window.pqrsdEstado?.(okMsg);
        } catch (e) {
            window.pqrsdAviso?.({
                titulo: 'No se pudo copiar automáticamente',
                mensaje: 'Seleccione el valor y cópielo manualmente.',
                valor: texto,
                variante: 'info',
            });
        }
    }
    document.getElementById('btn-copiar-radicado')?.addEventListener('click', () => {
        copiar(document.getElementById('radicado-valor')?.textContent?.trim() || '', 'Radicado copiado.');
    });
    document.getElementById('btn-copiar-token')?.addEventListener('click', () => {
        copiar(document.getElementById('token-valor')?.textContent?.trim() || '', 'Código de consulta copiado.');
    });

    function tonoConfirmacion() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return false;
            const ctx = new Ctx();
            const now = ctx.currentTime;
            const gain = ctx.createGain();
            gain.connect(ctx.destination);
            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(0.08, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.45);

            const o1 = ctx.createOscillator();
            o1.type = 'sine';
            o1.frequency.setValueAtTime(523.25, now);
            o1.connect(gain);
            o1.start(now);
            o1.stop(now + 0.18);

            const o2 = ctx.createOscillator();
            o2.type = 'sine';
            o2.frequency.setValueAtTime(659.25, now + 0.14);
            o2.connect(gain);
            o2.start(now + 0.14);
            o2.stop(now + 0.42);

            setTimeout(() => ctx.close().catch(() => {}), 800);
            return true;
        } catch (e) {
            return false;
        }
    }

    const btnSonido = document.getElementById('btn-reproducir-sonido');
    let yaReproducido = false;
    function intentarSonido(desdeUsuario) {
        if (yaReproducido && !desdeUsuario) return;
        const ok = tonoConfirmacion();
        if (ok) {
            yaReproducido = true;
            if (btnSonido) btnSonido.hidden = true;
        } else if (btnSonido) {
            btnSonido.hidden = false;
        }
    }
    btnSonido?.addEventListener('click', () => intentarSonido(true));
    // Intento único al cargar la confirmación exitosa (puede bloquearse sin gesto del usuario).
    if (document.getElementById('confirmacion-exito')) {
        intentarSonido(false);
        if (btnSonido && !yaReproducido) btnSonido.hidden = false;
    }
})();
</script>
@endpush
