<?php

declare(strict_types=1);

/**
 * CW front controller: the /v1 API and the /ui staff screens. Every request is routed here by the
 * web server (see deploy/staging/apache-cw-api.conf, apache-cw-ui.conf); nothing else under
 * public/ is served (the two UI assets go through \CW\Ui\Assets).
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

$path = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
if (is_string($path) && ($path === '/ui' || str_starts_with($path, '/ui/'))) {
    $ui = \CW\Ui\UiRequest::fromGlobals();
    $response = str_starts_with($ui->path, '/ui/assets/')
        ? \CW\Ui\Assets::serve($ui)
        : \CW\Ui\Kernel::fromEnvironment()->handle($ui);
} else {
    $response = \CW\Api\Kernel::fromEnvironment()->handle(\CW\Api\Request::fromGlobals());
}
ob_end_clean();
$response->send();
