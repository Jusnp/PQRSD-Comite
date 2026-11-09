<?php

namespace App\Jobs;

use App\Mail\Gmail\GmailApiClient;
use App\Mail\Gmail\GmailApiException;
use App\Mail\Gmail\GmailCredentialResolver;
use App\Mail\Providers\GoogleGmailMailProvider;
use App\Models\CorreoSalida;
use App\Models\CuentaCorreo;
use App\Models\MensajeCorreo;
use App\Services\Correo\AvisoRadicacionInstitucional;
use App\Services\Correo\SeleccionBuzonCorreo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcesarSalidaCorreoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $salidaId) {}

    public function handle(AvisoRadicacionInstitucional $avisos, GmailCredentialResolver $credenciales): void
    {
        $salida = CorreoSalida::query()->find($this->salidaId);
        if (! $salida || $salida->estado === 'enviado' || filled($salida->gmail_message_id)) {
            return;
        }

        if ($salida->proveedor_invocado) {
            $salida->update([
                'estado' => 'requiere_revision',
                'ultimo_error' => 'La inserción pudo completarse sin guardar el identificador. No se repite.',
            ]);

            return;
        }

        $cuenta = $salida->cuenta_correo_id
            ? CuentaCorreo::query()->find($salida->cuenta_correo_id)
            : ($salida->pqrsd
                ? app(SeleccionBuzonCorreo::class)->paraRadicacionWeb($salida->pqrsd)['cuenta']
                : null);
        if (! $cuenta || ! $cuenta->activo || ! app(SeleccionBuzonCorreo::class)->admiteInsercion($cuenta)) {
            $salida->update([
                'estado' => 'pendiente_regla',
                'ultimo_error' => 'No hay un buzón activo y verificable para esta radicación. No se contactó ninguna API.',
            ]);

            return;
        }

        $cliente = app()->bound(GmailApiClient::class) ? app(GmailApiClient::class) : null;
        if (! $cliente && blank($cuenta->credential_ref) && ! $cuenta->oauthToken()->exists()) {
            $salida->update([
                'cuenta_correo_id' => $cuenta->id,
                'estado' => 'pendiente_conexion',
                'ultimo_error' => 'La cuenta institucional no tiene credencial. No se contactó ninguna API.',
            ]);

            return;
        }

        $rfcId = $salida->rfc_message_id ?: $avisos->messageId($salida);
        $salida->update([
            'cuenta_correo_id' => $cuenta->id,
            'rfc_message_id' => $rfcId,
            'proveedor_invocado' => true,
            'estado' => 'enviando',
        ]);
        $raw = $avisos->rfc822($salida->fresh(), $cuenta);
        $provider = new GoogleGmailMailProvider($credenciales, $cliente);

        try {
            $resultado = $provider->insertarEnBandeja($cuenta, $raw, ['INBOX', 'UNREAD']);
        } catch (GmailApiException $e) {
            $autenticacion = $e->statusCode === 401;
            $salida->update([
                'cuenta_correo_id' => $cuenta->id,
                'estado' => $autenticacion ? 'pendiente_conexion' : 'requiere_revision',
                'proveedor_invocado' => ! $autenticacion,
                'intentos' => $salida->intentos + 1,
                'ultimo_error' => $e->getMessage(),
            ]);

            return;
        }

        if (! ($resultado['ok'] ?? false) || blank($resultado['id'] ?? null)) {
            $error = (string) ($resultado['error'] ?? 'inserción rechazada');
            $autenticacion = str_contains(strtolower($error), 'autentic') || str_contains($error, '401');
            $salida->update([
                'cuenta_correo_id' => $cuenta->id,
                'estado' => $autenticacion ? 'pendiente_conexion' : 'requiere_revision',
                'proveedor_invocado' => ! $autenticacion,
                'intentos' => $salida->intentos + 1,
                'ultimo_error' => $error,
            ]);

            return;
        }

        DB::transaction(function () use ($salida, $cuenta, $rfcId, $resultado, $raw) {
            $salida->update([
                'cuenta_correo_id' => $cuenta->id,
                'estado' => 'enviado',
                'rfc_message_id' => $rfcId,
                'gmail_message_id' => $resultado['id'],
                'gmail_thread_id' => $resultado['threadId'] ?? null,
                'enviado_en' => now('America/Bogota'),
                'ultimo_error' => null,
            ]);

            MensajeCorreo::query()->firstOrCreate(
                [
                    'cuenta_correo_id' => $cuenta->id,
                    'message_id' => $rfcId,
                ],
                [
                    'pqrsd_id' => $salida->pqrsd_id,
                    'gmail_message_id' => $resultado['id'],
                    'gmail_thread_id' => $resultado['threadId'] ?? null,
                    'remitente' => $cuenta->direccion,
                    'destinatario' => $cuenta->direccion,
                    'asunto' => 'PQRSD '.($salida->payload['radicado'] ?? '').' — radicación web',
                    'cuerpo_texto' => $this->cuerpoVisible($raw),
                    'fecha_mensaje' => now('America/Bogota'),
                    'fecha_importacion' => now('America/Bogota'),
                    'direccion' => 'entrante',
                    'clasificacion' => 'respuesta_caso',
                    'estado_procesamiento' => 'procesado',
                    'radicado_detectado' => $salida->payload['radicado'] ?? null,
                    'motivo_clasificacion' => 'Aviso insertado en la bandeja institucional',
                    'metadatos' => [
                        'origen' => 'radicacion-web',
                        'gmail_message_id' => $resultado['id'],
                        'gmail_thread_id' => $resultado['threadId'] ?? null,
                    ],
                ]
            );
        });
    }

    private function cuerpoVisible(string $raw): string
    {
        $partes = preg_split("/\r\n\r\n/", $raw, 2);

        return $partes[1] ?? $raw;
    }
}
