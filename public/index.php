<?php

declare(strict_types=1);

/**
 * CW /v1 API front controller. Every request is routed here by the web server (see
 * deploy/staging/apache-cw-api.conf); nothing else under public/ is served.
 */

ini_set('display_errors', '0');
ob_start();

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

// Warnings and notices are bugs: turn them into exceptions so they end as a clean 500.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0 || (error_reporting() & $severity) === 0) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

$response = \CW\Api\Kernel::fromEnvironment()->handle(\CW\Api\Request::fromGlobals());
ob_end_clean();
$response->send();
