<?php

namespace App\Services;

use App\Models\NotificacionPqrsd;
use App\Models\Pqrsd;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SlaNotificationService
{
    /**
     * Aviso interno de revisión prioritaria. No envía correo ni fija el SLA.
     */
    public function alertaVitalPreventiva(Pqrsd $caso): void
    {
        if (! $caso->alerta_vital_preventiva) {
            return;
        }

        if (NotificacionPqrsd::query()
            ->where('pqrsd_id', $caso->id)
            ->where('tipo', 'alerta_vital_preventiva')
            ->exists()) {
            return;
        }

        NotificacionPqrsd::create([
            'pqrsd_id' => $caso->id,
            'tipo' => 'alerta_vital_preventiva',
            'canal' => 'interno',
            'asunto' => 'Revisión prioritaria '.$caso->radicado,
            'mensaje' => 'Revisión prioritaria de un expediente sin clasificación formal. No es un diagnóstico ni fija el plazo. La recepción original se conserva.',
            'estado' => 'omitida',
            'umbral_clave' => 'alerta_vital_preventiva',
            'metadatos' => ['prioridad' => 'revision'],
        ]);
    }

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
        $ids = $destinatarios->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $correoHabilitado = (bool) config('pqrsd_sla.correo_alertas_habilitado', false);
        $principal = null;
        if ($correoHabilitado) {
            $primero = $destinatarios->first(fn (User $usuario) => filled($usuario->email) && mb_strlen((string) $usuario->email) <= 255);
            if ($primero) {
                $principal = (string) $primero->email;
            }
        }

        try {
            $notif = NotificacionPqrsd::create([
                'pqrsd_id' => $caso->id,
                'user_id' => $ids[0] ?? null,
                'tipo' => $tipo,
                'canal' => $correoHabilitado ? 'correo' : 'interno',
                'destinatario' => $principal,
                'asunto' => '[PQRSD '.$tipo.'] '.$caso->radicado,
                'mensaje' => $mensaje,
                'estado' => ($correoHabilitado && $principal) ? 'pendiente' : 'omitida',
                'umbral_clave' => $umbralClave,
                'metadatos' => array_merge($metadatos, [
                    'destinatario_ids' => $ids,
                    'destinatarios_cantidad' => count($ids),
                    'correo_habilitado' => $correoHabilitado,
                ]),
                'error' => $ids === []
                    ? 'Sin destinatarios configurados'
                    : ($correoHabilitado ? null : 'Correo de alertas deshabilitado'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        } catch (\Throwable) {
            Log::warning('Alerta SLA no persistida', [
                'pqrsd_id' => $caso->id,
                'tipo' => $tipo,
                'umbral' => $umbralClave,
            ]);

            return;
        }

        if (! $correoHabilitado || $ids === []) {
            return;
        }

        $enviados = 0;
        $fallos = 0;
        foreach ($destinatarios as $usuario) {
            $email = (string) $usuario->email;
            if ($email === '' || mb_strlen($email) > 255) {
                $fallos++;

                continue;
            }
            try {
                Mail::raw($mensaje, function ($mail) use ($email, $caso, $tipo) {
                    $mail->to($email)->subject('[PQRSD '.$tipo.'] '.$caso->radicado);
                });
                $enviados++;
            } catch (\Throwable) {
                $fallos++;
                Log::warning('Fallo notificación PQRSD', [
                    'pqrsd_id' => $caso->id,
                    'user_id' => $usuario->id,
                ]);
            }
        }

        $notif->update([
            'estado' => $enviados > 0 ? 'enviada' : 'fallida',
            'enviada_en' => now('America/Bogota'),
            'error' => $fallos > 0 ? 'No se pudo entregar a '.$fallos.' destinatario(s).' : null,
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
