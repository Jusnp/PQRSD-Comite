<?php

namespace App\Services;

use App\Models\NotificacionPqrsd;
use App\Models\Pqrsd;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SlaNotificationService
{
    public function notificarVitalInmediato(Pqrsd $caso): void
    {
        if (! config('pqrsd_sla.notificar_vital_inmediato', true)) {
            return;
        }

        if (! in_array($caso->nivel_riesgo, ['vital_menor', 'vital_general'], true)) {
            return;
        }

        $mensaje = sprintf(
            'ALERTA VITAL %s — %s. Recepción %s. Límite %s. Activar protocolo institucional inmediato (no esperar vencimiento).',
            strtoupper($caso->nivel_riesgo),
            $caso->radicado,
            optional($caso->fecha_recepcion)->timezone('America/Bogota')->format('d/m/Y H:i'),
            optional($caso->fecha_limite)->timezone('America/Bogota')->format('d/m/Y H:i')
        );

        $this->emitir($caso, 'vital_inmediato', 'vital_inmediato', $mensaje, [
            'prioridad' => 'critica',
        ]);
    }

    public function evaluarRecordatorios(Pqrsd $caso): void
    {
        if (! $caso->fecha_limite || ! $caso->fecha_inicio_conteo) {
            return;
        }

        if (in_array($caso->estado_cumplimiento, ['resuelto_oportuno', 'resuelto_extemporaneo'], true)) {
            return;
        }

        $ahora = now('America/Bogota');
        $limite = $caso->fecha_limite->timezone('America/Bogota');

        if ($ahora->greaterThan($limite)) {
            $this->emitir(
                $caso,
                'vencido',
                'vencido',
                sprintf('Caso %s VENCIDO. Límite era %s.', $caso->radicado, $limite->format('d/m/Y H:i')),
                ['estado' => 'vencido']
            );

            return;
        }

        $umbrales = config('pqrsd_sla.recordatorios_minutos_antes.'.$caso->nivel_riesgo, []);
        $restanteMin = $ahora->diffInMinutes($limite);

        foreach ($umbrales as $minutosAntes) {
            if ($restanteMin <= $minutosAntes) {
                $clave = 'recordatorio_'.$minutosAntes.'m';
                $this->emitir(
                    $caso,
                    'recordatorio',
                    $clave,
                    sprintf(
                        'Recordatorio %s: restan ~%d min para vencer (límite %s).',
                        $caso->radicado,
                        $restanteMin,
                        $limite->format('d/m/Y H:i')
                    ),
                    ['minutos_antes' => $minutosAntes, 'restante_min' => $restanteMin]
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $metadatos
     */
    private function emitir(Pqrsd $caso, string $tipo, string $umbralClave, string $mensaje, array $metadatos = []): void
    {
        if (NotificacionPqrsd::query()
            ->where('pqrsd_id', $caso->id)
            ->where('tipo', $tipo)
            ->where('umbral_clave', $umbralClave)
            ->exists()) {
            return;
        }

        $destinatarios = $this->destinatarios($caso);
        $emails = $destinatarios->pluck('email')->filter()->values()->all();

        $notif = NotificacionPqrsd::create([
            'pqrsd_id' => $caso->id,
            'user_id' => $destinatarios->first()?->id,
            'tipo' => $tipo,
            'canal' => 'correo',
            'destinatario' => implode(', ', $emails) ?: null,
            'asunto' => '[PQRSD '.$tipo.'] '.$caso->radicado,
            'mensaje' => $mensaje,
            'estado' => empty($emails) ? 'omitida' : 'pendiente',
            'umbral_clave' => $umbralClave,
            'metadatos' => array_merge($metadatos, ['destinatarios' => $emails]),
            'error' => empty($emails) ? 'Sin destinatarios configurados' : null,
        ]);

        if (empty($emails)) {
            return;
        }

        $fallos = [];
        foreach ($emails as $email) {
            try {
                Mail::raw($mensaje, function ($mail) use ($email, $caso, $tipo) {
                    $mail->to($email)->subject('[PQRSD '.$tipo.'] '.$caso->radicado);
                });
            } catch (\Throwable $e) {
                $fallos[] = $email.': '.$e->getMessage();
                Log::warning('Fallo notificación PQRSD', [
                    'pqrsd' => $caso->radicado,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $notif->update([
            'estado' => empty($fallos) ? 'enviada' : (count($fallos) < count($emails) ? 'enviada' : 'fallida'),
            'enviada_en' => now('America/Bogota'),
            'error' => $fallos ? implode('; ', $fallos) : null,
        ]);
    }

    private function destinatarios(Pqrsd $caso)
    {
        $users = collect();

        if ($caso->responsable_id) {
            $responsable = User::find($caso->responsable_id);
            if ($responsable) {
                $users->push($responsable);
            }
        }

        foreach (config('pqrsd_sla.destinatarios_urgencia_roles', []) as $role) {
            $users = $users->merge(User::role($role)->where('activo', true)->get());
        }

        return $users->unique('id')->values();
    }
}
