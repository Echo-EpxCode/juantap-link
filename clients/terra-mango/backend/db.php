<?php
declare(strict_types=1);

function app_database(): mysqli
{
    static $connection = null;

    if ($connection instanceof mysqli) {
        return $connection;
    }

    $config = app_config();
    $database = $config['database'] ?? null;
    if (!is_array($database)) {
        throw new RuntimeException('Database configuration is missing.');
    }

    foreach (['host', 'database', 'username'] as $field) {
        if (!isset($database[$field]) || !is_string($database[$field]) || $database[$field] === '') {
            throw new RuntimeException('Database configuration is incomplete.');
        }
        if (str_starts_with($database[$field], 'REPLACE_WITH_')) {
            throw new RuntimeException('Database configuration has not been completed.');
        }
    }

    $password = $database['password'] ?? null;
    if (!is_string($password) || str_starts_with($password, 'REPLACE_WITH_')) {
        throw new RuntimeException('Database configuration has not been completed.');
    }

    $host = strtolower(trim($database['host']));
    $appEnvironment = $config['app']['environment'] ?? null;
    if (
        $password === ''
        && ($appEnvironment !== 'development' || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true))
    ) {
        throw new RuntimeException('A database password is required outside local development.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $connection = new mysqli(
        $database['host'],
        $database['username'],
        $password,
        $database['database'],
        (int) ($database['port'] ?? 3306)
    );
    $connection->set_charset('utf8mb4');
    $connection->query("SET time_zone = '+00:00'");

    return $connection;
}
