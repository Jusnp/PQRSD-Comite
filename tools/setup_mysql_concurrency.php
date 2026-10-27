<?php

/**
 * Crea/reinicia únicamente la base aislada pqrsd_concurrency_test.
 * No toca pqrsd_menteplena ni otras bases.
 */

declare(strict_types=1);

$dbName = 'pqrsd_concurrency_test';
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USERNAME') !== false && getenv('DB_USERNAME') !== '' ? getenv('DB_USERNAME') : 'root';
$pass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';

$pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "OK database ready: {$dbName}".PHP_EOL;
