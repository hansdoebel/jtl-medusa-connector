<?php
declare(strict_types=1);

use Jtl\Connector\Core\Application\Application;
use Jtl\Connector\Core\Config\{ArrayConfig, ConfigSchema};
use JtlMedusa\{Connector, Settings};

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('display_errors', '0');
$root = dirname(__DIR__);
try {
    $settings = Settings::fromEnvironment();
    foreach (['var', 'var/log', 'var/cache'] as $directory) {
        if (!is_dir($root . '/' . $directory) && !mkdir($root . '/' . $directory, 0700, true)) {
            throw new RuntimeException('Cannot create connector state directory.');
        }
    }
    $config = new ArrayConfig(['main_language' => $settings->language, 'log.level' => 'error', 'debug' => false]);
    $application = new Application($root, $config, new ConfigSchema());
    $connector = new Connector($settings, $root . '/var');
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Connector initialization failed. Check server configuration and writable state storage.']);
    exit;
}
// Core owns runtime errors and RPC responses. Do not append a second JSON response on failure.
$application->run($connector);
