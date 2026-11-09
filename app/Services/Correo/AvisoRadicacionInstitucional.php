<?php

namespace App\Services\Correo;

use App\Jobs\ProcesarSalidaCorreoJob;
use App\Models\CorreoSalida;
use App\Models\CuentaCorreo;
use App\Models\Pqrsd;
use Illuminate\Support\Facades\Schema;

/**
 * Encola el aviso de una radicación web para la bandeja institucional.
 * El texto no incluye relato clínico, documentos ni datos de contacto.
 */
class AvisoRadicacionInstitucional
{
    public function encolar(Pqrsd $caso): ?CorreoSalida
    {
        if (! Schema::hasTable('correo_salidas')) {
            return null;
        }

        $seleccion = app(SeleccionBuzonCorreo::class)->paraRadicacionWeb($caso);
        $salida = CorreoSalida::query()->firstOrCreate(
            ['idempotency_key' => 'radicacion-web:'.$caso->id],
            [
                'pqrsd_id' => $caso->id,
                'cuenta_correo_id' => $seleccion['cuenta']?->id,
                'tipo' => 'radicacion_web',
                'estado' => $seleccion['cuenta'] ? 'pendiente' : 'pendiente_regla',
                'payload' => $this->payload($caso),
                'ultimo_error' => $seleccion['motivo'],
            ]
        );

        if ($salida->wasRecentlyCreated && $seleccion['cuenta']) {
            ProcesarSalidaCorreoJob::dispatch($salida->id)->afterCommit();
        }

        return $salida;
    }

    /**
     * @return array<string, string>
     */
    public function payload(Pqrsd $caso): array
    {
        $caso->loadMissing('tipo');

        return [
            'radicado' => (string) $caso->radicado,
            'tipo' => (string) ($caso->tipo?->nombre ?: 'Pendiente de clasificación'),
            'recibida_en' => $caso->fecha_recepcion?->timezone('America/Bogota')->format('Y-m-d H:i')
                ?? now('America/Bogota')->format('Y-m-d H:i'),
            'enlace_expediente' => route('pqrsd.show', $caso),
            'origen' => 'radicacion-web',
        ];
    }

    public function rfc822(CorreoSalida $salida, CuentaCorreo $cuenta): string
    {
        $datos = $salida->payload;
        $visible = $cuenta->direccion_visible ?: $cuenta->direccion;
        $destino = $cuenta->buzon_canonico ?: $cuenta->direccion;
        $messageId = $salida->rfc_message_id ?: $this->messageId($salida);
        $texto = implode("\r\n", [
            'Radicación recibida por el formulario público.',
            'Radicado: '.($datos['radicado'] ?? ''),
            'Tipo: '.($datos['tipo'] ?? ''),
            'Recibida: '.($datos['recibida_en'] ?? ''),
            'El relato y los soportes permanecen en el expediente.',
            'Expediente: '.($datos['enlace_expediente'] ?? ''),
        ]);

        $asunto = 'PQRSD '.$datos['radicado'].' — radicación web';

        return implode("\r\n", [
            'From: '.$visible,
            'To: '.$destino,
            'Subject: '.$asunto,
            'Date: '.now('America/Bogota')->toRfc7231String(),
            'Message-ID: '.$messageId,
            'X-PQRSD-Origen: radicacion-web',
            'X-PQRSD-Radicado: '.($datos['radicado'] ?? ''),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            $texto,
        ]);
    }

    public function messageId(CorreoSalida $salida): string
    {
        $radicado = (string) ($salida->payload['radicado'] ?? $salida->id);

        return '<pqrsd-'.substr(hash('sha256', 'radicacion-web:'.$radicado), 0, 24).'@pqrsd.local>';
    }
}
