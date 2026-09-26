<?php

declare(strict_types=1);

/**
 * Gives a channel a NEW API key and prints it once; the old key stops working immediately.
 * Run ON THE CW SERVER as root (reads /etc/cw/app.env; connects as the app login, cw_app):
 *
 *   php bin/rotate_key.php --code=vpg [--db=<schema>]
 *
 * STDOUT gets the key and nothing else; messages go to STDERR. Only its sha256 is stored.
 */

use CW\ChannelAdmin;
use CW\Config;
use CW\CwException;
use CW\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['code:', 'db:', 'help']);
if (isset($opts['help']) || !is_string($opts['code'] ?? null)) {
    fwrite(STDERR, "usage: php bin/rotate_key.php --code=<code> [--db=<schema>]\n");
    exit(isset($opts['help']) ? 0 : 2);
}

try {
    $settings = Config::load()->dbApp();
    if (is_string($opts['db'] ?? null)) {
        $settings = $settings->withDatabase($opts['db']);
    }
    $key = (new ChannelAdmin(Db::connect($settings)))->rotateKey($opts['code']);
    fwrite(STDERR, "channel {$opts['code']}: new API key follows on stdout and is shown ONLY now; the old key no longer works.\n");
    fwrite(STDOUT, $key . "\n");
    exit(0);
} catch (CwException $e) {
    fwrite(STDERR, "rotate_key: {$e->getMessage()}\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'rotate_key: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
