<?php
declare(strict_types=1);

if (!defined('JUANTAP_APP_CONFIG_INCLUDE')) {
    http_response_code(404);
    exit;
}

/*
 * Copy this file to config.local.php on the hosting account.
 * Replace every placeholder before serving the application.
 * Never put real credentials or keys in this example file.
 */
return [
    'app' => [
        'environment' => 'development',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'terra_mango_local',
        'username' => 'root',
        'password' => 'REPLACE_WITH_DATABASE_PASSWORD',
    ],
    'keys' => [
        'contact_lookup' => 'REPLACE_WITH_RANDOM_SECRET',
        'contact_encryption' => 'REPLACE_WITH_RANDOM_SECRET',
        'auth_rate_limit' => 'REPLACE_WITH_RANDOM_SECRET',
    ],
];
