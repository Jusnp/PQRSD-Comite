<?php

return [

    'accepted' => 'Debe aceptar :attribute.',
    'required' => 'Complete el campo :attribute.',
    'email' => 'Ingrese un correo electrónico válido.',
    'date' => 'Ingrese una fecha válida en :attribute.',
    'before_or_equal' => 'La fecha de :attribute no puede ser posterior a hoy.',
    'exists' => 'La opción seleccionada en :attribute no es válida.',
    'in' => 'Seleccione una opción válida en :attribute.',
    'max' => [
        'string' => ':attribute no debe superar :max caracteres.',
        'file' => ':attribute no debe superar :max kilobytes.',
        'array' => ':attribute no debe tener más de :max elementos.',
    ],
    'min' => [
        'string' => ':attribute debe tener al menos :min caracteres.',
        'file' => ':attribute debe tener al menos :min kilobytes.',
        'array' => ':attribute debe tener al menos :min elementos.',
    ],
    'uuid' => 'Recargue el formulario e intente de nuevo.',
    'regex' => 'El formato de :attribute no es válido.',
    'boolean' => 'El valor de :attribute no es válido.',
    'file' => ':attribute debe ser un archivo válido.',

    'attributes' => [
        'tipo_pqrsd_id' => 'tipo de solicitud',
        'medio_respuesta' => 'medio de respuesta',
        'solicitante_nombre' => 'nombre completo',
        'solicitante_tipo_documento' => 'tipo de documento',
        'solicitante_documento' => 'número de documento',
        'solicitante_email' => 'correo electrónico',
        'solicitante_telefono' => 'teléfono',
        'solicitante_direccion' => 'dirección de correspondencia',
        'paciente_nombre' => 'nombre del paciente',
        'paciente_tipo_documento' => 'tipo de documento del paciente',
        'paciente_documento' => 'documento del paciente',
        'paciente_fecha_nacimiento' => 'fecha de nacimiento',
        'relacion_paciente' => 'relación con el paciente',
        'asunto' => 'asunto',
        'descripcion' => 'descripción',
        'radicado' => 'número de radicado',
        'token' => 'código de consulta',
        'quien_radica' => 'quién presenta la solicitud',
        'acepto_tratamiento_datos' => 'autorización de tratamiento de datos',
        'consentimiento_comunicacion_email' => 'autorización de comunicación por correo',
        'fecha_hechos' => 'fecha de los hechos',
    ],

    'custom' => [
        'medio_respuesta' => [
            'required' => 'Seleccione cómo desea recibir o consultar la respuesta.',
        ],
        'solicitante_tipo_documento' => [
            'required' => 'Seleccione el tipo de documento.',
            'in' => 'Seleccione el tipo de documento.',
        ],
        'paciente_tipo_documento' => [
            'required' => 'Seleccione el tipo de documento del paciente.',
            'in' => 'Seleccione el tipo de documento del paciente.',
        ],
        'solicitante_telefono' => [
            'regex' => 'Ingrese un teléfono válido.',
        ],
        'descripcion' => [
            'min' => 'La descripción debe tener al menos :min caracteres.',
        ],
        'asunto' => [
            'min' => 'El asunto debe tener al menos :min caracteres.',
        ],
        'quien_radica' => [
            'required' => 'Indique si usted es el paciente o presenta la solicitud en nombre de otra persona.',
        ],
        'radicado' => [
            'required' => 'Ingrese el número de radicado.',
            'regex' => 'El número de radicado no tiene un formato válido.',
        ],
    ],

];
