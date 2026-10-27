<?php

/**
 * Identidad institucional visible del Sistema PQRSD.
 * No altera persona jurídica, correos técnicos ni datos históricos.
 */
return [

    'nombre_institucional' => env('IDENTIDAD_NOMBRE', 'Comité de Estudios Médicos'),

    'nombre_sistema' => env('IDENTIDAD_SISTEMA', 'Sistema PQRSD — Comité de Estudios Médicos'),

    'nombre_corto' => env('IDENTIDAD_CORTO', 'Comité de Estudios Médicos'),

    /**
     * Rutas relativas bajo public/.
     * Logo único vigente: PNG blanco con tipografía azul oscuro (tres líneas).
     * Las tres claves apuntan al mismo archivo para evitar variantes oscuras obsoletas.
     */
    'logo_principal' => env('IDENTIDAD_LOGO', 'images/logo-comite-estudios-medicos.png'),

    'logo_header' => env('IDENTIDAD_LOGO_HEADER', 'images/logo-comite-estudios-medicos.png'),

    'logo_compacto' => env('IDENTIDAD_LOGO_COMPACTO', 'images/logo-comite-estudios-medicos.png'),

    'favicon' => 'favicon.svg',

    'descripcion' => env(
        'IDENTIDAD_DESCRIPCION',
        'Sistema PQRSD — Comité de Estudios Médicos — gestión de peticiones, quejas, reclamos, sugerencias y denuncias'
    ),

    'login_titulo' => 'Acceso PQRSD',

    'login_subtitulo' => 'Personal autorizado — Comité de Estudios Médicos',

    'publico_encabezado' => 'Formulario público PQRSD',

    'qr_titulo' => 'Formulario PQRSD — Comité de Estudios Médicos',

    /**
     * Textos reutilizables. El aviso de tratamiento de datos requiere
     * validación jurídica institucional (responsable del tratamiento).
     */
    'textos' => [
        'personal_revision' => 'El personal del Comité de Estudios Médicos revisará y clasificará su solicitud. Este canal no reemplaza urgencias.',
        'tratamiento_datos' => 'Autorizo el tratamiento de los datos aportados en esta PQRSD conforme a la Ley 1581 de 2012 y la política institucional de tratamiento de datos personales.',
        'qr_explicacion' => 'Este código QR permite registrar solicitudes PQRSD dirigidas al Comité de Estudios Médicos: peticiones, quejas, reclamos, sugerencias, denuncias y felicitaciones.',
    ],
];
