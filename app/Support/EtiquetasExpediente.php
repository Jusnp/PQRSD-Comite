<?php

namespace App\Support;

use App\Models\ActuacionPqrsd;
use App\Models\HistorialEstadoPqrsd;
use App\Models\Pqrsd;
use Illuminate\Support\Collection;

/**
 * Etiquetas de presentación del expediente.
 * No altera valores persistidos ni reglas de negocio.
 */
final class EtiquetasExpediente
{
    public static function tipoGestion(?string $tipo): string
    {
        return match ($tipo) {
            'radicacion' => 'PQRSD registrada',
            'clasificacion_sla' => 'Clasificación de riesgo',
            'actuacion_operativa' => 'Gestión operativa',
            'soporte_interno' => 'Soporte adjunto',
            'respuesta', 'respuesta_fondo' => 'Respuesta de PQRS',
            'cierre' => 'PQRSD finalizada',
            'remision_area' => 'Solicitud a otra área',
            'respuesta_area' => 'Respuesta de área interna',
            'correo_entrada' => 'Correo recibido',
            'correo_asociado' => 'Correo asociado al expediente',
            'correo_saliente' => 'Correo enviado',
            'reasignacion' => 'Cambio de seguimiento (histórico)',
            'cambio_estado' => 'Actualización de estado',
            default => $tipo ? str_replace('_', ' ', $tipo) : 'Gestión',
        };
    }

    public static function estadoOperativo(?string $estado): string
    {
        return match ($estado) {
            'pendiente_clasificacion' => 'Pendiente de clasificación',
            'radicado' => 'Radicada',
            'asignado' => 'En seguimiento',
            'en_gestion' => 'En gestión',
            'respondido' => 'Respondida',
            'cerrado' => 'Finalizada',
            default => $estado ? ucfirst(str_replace('_', ' ', $estado)) : '—',
        };
    }

    public static function tituloGestion(ActuacionPqrsd $actuacion): string
    {
        $titulo = trim((string) $actuacion->titulo);
        if ($titulo !== '') {
            return match (true) {
                str_contains(mb_strtolower($titulo), 'radicación') => 'PQRSD registrada',
                str_contains(mb_strtolower($titulo), 'clasificación') => 'PQRSD clasificada',
                str_contains(mb_strtolower($titulo), 'documento de soporte') => 'Soporte adjunto',
                str_contains(mb_strtolower($titulo), 'cierre') => 'PQRSD finalizada',
                default => $titulo,
            };
        }

        return self::tipoGestion($actuacion->tipo);
    }

    /**
     * Hitos simples del expediente a partir del historial real (sin inventar eventos).
     *
     * @return Collection<int, array{etiqueta:string, fecha:?string, usuario:?string, detalle:?string}>
     */
    public static function hitosEstado(Pqrsd $pqrsd): Collection
    {
        $hitos = collect();
        $vistos = [];

        $historial = $pqrsd->historialEstados
            ->sortBy(fn (HistorialEstadoPqrsd $h) => $h->created_at?->timestamp ?? $h->id)
            ->values();

        foreach ($historial as $hist) {
            $etiqueta = null;
            $detalle = $hist->motivo;

            if ($hist->campo === 'estado_operativo') {
                $nuevo = (string) $hist->valor_nuevo;
                if ($hist->valor_anterior === null || str_contains(mb_strtolower((string) $hist->motivo), 'radic')) {
                    $etiqueta = 'PQRSD registrada';
                } elseif ($nuevo === 'respondido') {
                    $etiqueta = 'Respuesta comunicada (oportunidad)';
                } elseif ($nuevo === 'cerrado') {
                    $etiqueta = 'PQRSD finalizada';
                }
            }

            if ($hist->campo === 'nivel_riesgo'
                && filled($hist->valor_nuevo)
                && $hist->valor_nuevo !== 'pendiente'
                && $hist->valor_nuevo !== $hist->valor_anterior) {
                $etiqueta = 'PQRSD clasificada';
                $detalle = trim(
                    'Riesgo: '.str_replace('_', ' ', (string) $hist->valor_nuevo)
                    .($hist->motivo ? ' · '.$hist->motivo : '')
                );
            }

            if ($hist->campo === 'respuesta_estado') {
                $etiqueta = match ((string) $hist->valor_nuevo) {
                    'elaborada', 'aprobada' => 'Respuesta elaborada',
                    'puesta_disposicion' => 'Respuesta puesta a disposición',
                    'enviada' => 'Respuesta comunicada por canal verificable',
                    'consultada' => 'Respuesta consultada por el ciudadano',
                    default => null,
                };
                $detalle = $hist->motivo;
            }

            if ($hist->campo === 'estado_cumplimiento'
                && in_array($hist->valor_nuevo, ['resuelto_oportuno', 'resuelto_extemporaneo'], true)) {
                $etiqueta = 'Oportunidad calculada';
                $detalle = $hist->valor_nuevo === 'resuelto_oportuno'
                    ? 'Comunicada dentro del plazo'
                    : 'Comunicada fuera del plazo';
            }

            if ($etiqueta === null) {
                continue;
            }

            // Una entrada por tipo de hito (la primera ocurrencia cronológica).
            if (isset($vistos[$etiqueta])) {
                continue;
            }
            $vistos[$etiqueta] = true;

            $hitos->push([
                'etiqueta' => $etiqueta,
                'fecha' => $hist->created_at?->timezone('America/Bogota')->format('d/m/Y H:i'),
                'usuario' => $hist->usuario?->name ?? 'Sistema',
                'detalle' => $detalle,
            ]);
        }

        return $hitos->values();
    }
}
