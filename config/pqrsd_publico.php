<?php

return [

    /*
    |--------------------------------------------------------------------------
    | URL pública definitiva (producción)
    |--------------------------------------------------------------------------
    |
    | Solo HTTPS institucional. Si está definida, tiene prioridad absoluta
    | sobre APP_URL y PQRSD_DEV_URL para el formulario / código QR.
    |
    */
    'base_url' => env('PQRSD_PUBLIC_URL'),

    /*
    |--------------------------------------------------------------------------
    | URL de desarrollo / laboratorio
    |--------------------------------------------------------------------------
    |
    | Usada cuando NO hay PQRSD_PUBLIC_URL y el entorno no es production.
    | Debe apuntar a un host/puerto realmente escuchando (p. ej. artisan serve).
    | No invente IPs: configure aquí la dirección verificada en su red.
    |
    */
    'dev_url' => env('PQRSD_DEV_URL'),

    /*
    |--------------------------------------------------------------------------
    | Hosts permitidos para URLs de desarrollo del QR
    |--------------------------------------------------------------------------
    |
    | Lista separada por comas. Evita que un Host header malicioso o una
    | configuración errónea genere enlaces públicos no autorizados.
    |
    */
    'allowed_dev_hosts' => array_values(array_filter(array_map(
        static fn (string $h): string => strtolower(trim($h)),
        explode(',', (string) env('PQRSD_ALLOWED_DEV_HOSTS', '127.0.0.1,localhost,::1'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Anonimato — tipos con respaldo habitual (pendiente validación Calidad/Jurídica)
    |--------------------------------------------------------------------------
    |
    | QUEJA y DENUNCIA se admiten de forma prioritaria. Los demás tipos pueden
    | radicarse anónimos pero quedan marcados para revisión jurídica/institucional.
    | No se bloquea la radicación solo por falta de contacto.
    |
    */
    'anonimo_tipos_prioritarios' => ['QUEJA', 'DENUNCIA'],

    'medios_respuesta' => [
        'email' => 'Correo electrónico',
        'correspondencia' => 'Correspondencia física',
        'consulta_segura' => 'Consulta segura en el portal',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tipos de documento de identidad (códigos normalizados)
    |--------------------------------------------------------------------------
    */
    'tipos_documento' => [
        'CC' => 'Cédula de ciudadanía (CC)',
        'TI' => 'Tarjeta de identidad (TI)',
        'RC' => 'Registro civil (RC)',
        'CE' => 'Cédula de extranjería (CE)',
        'PA' => 'Pasaporte (PA)',
        'PPT' => 'Permiso por Protección Temporal (PPT)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Consulta pública de estado (solo radicado)
    |--------------------------------------------------------------------------
    */
    'consulta_estado' => [
        'max_intentos' => 12,
        'ventana_segundos' => 300,
    ],

    'adjuntos' => [
        'max_files' => (int) env('PQRSD_PUBLICO_MAX_FILES', 5),
        'max_kb' => (int) env('PQRSD_PUBLICO_MAX_KB', 10240), // 10 MB
        'extensions' => ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'],
        'mimes' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
    ],

];
