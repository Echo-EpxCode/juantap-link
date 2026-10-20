<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/business.php';
require_once __DIR__ . '/stamps.php';

function app_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $configPath = dirname(__DIR__) . '/private/config.local.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Private application configuration is missing.');
    }

    if (!defined('JUANTAP_APP_CONFIG_INCLUDE')) {
        define('JUANTAP_APP_CONFIG_INCLUDE', true);
    }

    $loadedConfig = require $configPath;
    if (!is_array($loadedConfig)) {
        throw new RuntimeException('Private application configuration is invalid.');
    }

    $config = $loadedConfig;
    return $config;
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_exception_handler('app_handle_exception');
