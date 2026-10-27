<?php

namespace App\Support;

use App\Models\Pqrsd;

/**
 * Etiquetas mínimas para consulta ciudadana solo con radicado.
 * No expone datos personales ni clínicos.
 */
final class EstadoPublicoPqrsd
{
    public static function etiqueta(Pqrsd $caso): string
    {
        return match ($caso->estado_operativo) {
            'cerrado' => 'Finalizada',
            'pendiente_clasificacion', 'radicado' => 'Recibida',
            default => 'En trámite',
        };
    }

    public static function mensaje(Pqrsd $caso): string
    {
        return match (self::etiqueta($caso)) {
            'Recibida' => 'Su solicitud fue recibida y será revisada por el personal autorizado del Comité de Estudios Médicos.',
            'Finalizada' => 'La atención de esta PQRSD ha finalizado. La respuesta estará disponible por el medio que usted indicó al radicar, cuando corresponda.',
            default => 'Su solicitud se encuentra en trámite. El personal autorizado continúa la gestión según los plazos institucionales.',
        };
    }
}
