<?php

require __DIR__.'/../vendor/autoload.php';

$database = 'pqrsd_postgres_test';
$username = (string) (getenv('PQRSD_TEST_DB_USERNAME') ?: '');
$password = (string) (getenv('PQRSD_TEST_DB_PASSWORD') ?: '');

if ($username === '' || $username === 'pqrsd' || $password === '') {
    fwrite(STDERR, "La suite PostgreSQL exige el usuario pqrsd_test y su clave local.\n");
    exit(1);
}

$variables = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'pgsql',
    'DB_HOST' => 'postgres',
    'DB_PORT' => '5432',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => $username,
    'DB_PASSWORD' => $password,
    'DB_URL' => '',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
    'POLITICA_TRATAMIENTO_URL' => '',
    'POLITICA_TRATAMIENTO_VERSION' => '',
    'POLITICA_TRATAMIENTO_CODIGO' => '',
    'POLITICA_TRATAMIENTO_FECHA' => '',
    'PQRSD_SLA_CORREO_ALERTAS' => 'false',
];

foreach ($variables as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

if (getenv('DB_CONNECTION') !== 'pgsql'
    || getenv('DB_DATABASE') !== $database
    || getenv('DB_USERNAME') !== $username
    || getenv('DB_USERNAME') === 'pqrsd') {
    fwrite(STDERR, "La suite PostgreSQL solo puede usar {$database} con el usuario de pruebas.\n");
    exit(1);
}
