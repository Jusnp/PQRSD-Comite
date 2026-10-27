<?php

return [

    /*
    | Umbrales de semáforo (fracción del plazo total restante).
    | "próximo a vencer" cuando el tiempo restante <= max(este % del plazo, minutos_minimos).
    */
    'proximo_vencer_fraccion' => (float) env('PQRSD_SLA_PROXIMO_FRACCION', 0.25),

    'proximo_vencer_minutos_minimos' => [
        'vital_menor' => 60,       // 1h
        'vital_general' => 180,    // 3h
        'priorizado' => 360,       // 6h
        'simple' => 720,           // 12h
        'ordinario' => 1440,       // 1 día
    ],

    /*
    | Recordatorios configurables (minutos antes del vencimiento).
    | Vitales: notificación inmediata al clasificar + recordatorio en estos umbrales.
    */
    'recordatorios_minutos_antes' => [
        'vital_menor' => [240, 60],
        'vital_general' => [360, 60],
        'priorizado' => [720, 120],
        'simple' => [1440, 360],
        'ordinario' => [2880, 1440],
    ],

    'notificar_vital_inmediato' => true,

    /*
    | Destinatarios de alertas vitales / recordatorios SLA.
    | Debe alinearse con roles operativos reales (admin + auxiliar_pqrsd).
    | Roles legacy se conservan por compatibilidad si aún existen usuarios activos.
    */
    'destinatarios_urgencia_roles' => [
        'admin',
        'auxiliar_pqrsd',
        'lider_pqrsd',
        'superadministrador',
    ],

    /*
    | Política ordinaria: 10 días hábiles — pendiente validación jurídica.
    | El valor operativo vive en reglas_sla; este flag documenta el estado.
    */
    'ordinario_validacion_juridica_pendiente' => true,

    /*
    | Denuncias: 10 días hábiles (parámetro institucional de trabajo en desarrollo).
    | Validación jurídica / go-live producción: pendiente de aprobación.
    */
    'denuncia_dias_habiles' => 10,
    'denuncia_unidad_conteo_pendiente_confirmacion' => false,
    'denuncia_aprobacion_juridica_produccion_pendiente' => true,

    /*
    | Meta institucional de % de oportunidad (única fuente de verdad).
    | Criterio: >= meta cumple; < meta no cumple; sin evaluables = sin datos.
    | La comparación usa el porcentaje preciso (sin redondear antes).
    */
    'meta_oportunidad_pct' => (float) env('PQRSD_META_OPORTUNIDAD', 95),

    /*
    | Evento que alimenta fecha_respuesta / oportunidad / cierre operativo.
    | Operativo: comunicación válida (puesta a disposición, envío evidenciado o consulta).
    | Una respuesta solo elaborada NO marca oportunidad ni habilita cierre.
    | Pendiente confirmación formal de Calidad/Jurídica sobre excepciones.
    | No recalcula expedientes históricos ya persistidos.
    */
    'oportunidad_exige_comunicacion' => (bool) env('PQRSD_OPORTUNIDAD_EXIGE_COMUNICACION', true),
    'cierre_exige_comunicacion' => (bool) env('PQRSD_CIERRE_EXIGE_COMUNICACION', true),
    'cierre_exige_clasificacion' => (bool) env('PQRSD_CIERRE_EXIGE_CLASIFICACION', true),
];
