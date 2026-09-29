<?php

declare(strict_types=1);

$dbHost = getenv('RR_DB_HOST') ?: 'localhost';
$dbPort = getenv('RR_DB_PORT') ?: '3307';
$dbName = getenv('RR_DB_NAME') ?: 'R&R Sweet Bites';
$dbUser = getenv('RR_DB_USER') ?: 'GianAdmin';
$dbPassword = getenv('RR_DB_PASSWORD') ?: 'password';

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $dbHost,
    $dbPort,
    $dbName
);

$pdo = new PDO($dsn, $dbUser, $dbPassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);