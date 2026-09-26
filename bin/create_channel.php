<?php

declare(strict_types=1);

/**
 * Creates a site channel + its sellable warehouse assignment and prints its NEW API key once.
 * Run ON THE CW SERVER as root (reads /etc/cw/app.env; connects as the app login, cw_app):
 *
 *   php bin/create_channel.php --code=vpg --name="Vape and Go" [--warehouse=MAIN]
 *       [--ips=203.0.113.7,198.51.100.0/24] [--mode=off|shadow|live] [--ttl=2400] [--db=<schema>]
 *       [--movement-types=goods_in,supplier_return,erp_sale]   (the ERP-relaying site only; default none)
 *
 * STDOUT gets the key and nothing else (KEY=$(php bin/create_channel.php ...)); messages go to
 * STDERR. The key is not stored anywhere (only its sha256): put it in the site's config and the
 * team password manager now. Defaults fail closed: mode off, and with no --ips every call is
 * refused until the site's egress address is added.
 */

use CW\ChannelAdmin;
use CW\Config;
use CW\CwException;
use CW\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['code:', 'name:', 'warehouse:', 'ips:', 'mode:', 'ttl:', 'db:', 'movement-types:', 'help']);
if (isset($opts['help']) || !is_string($opts['code'] ?? null) || !is_string($opts['name'] ?? null)) {
    fwrite(STDERR, "usage: php bin/create_channel.php --code=<code> --name=<name> [--warehouse=MAIN] [--ips=a,b/24] [--mode=off] [--ttl=2400] [--db=<schema>] [--movement-types=goods_in,supplier_return,erp_sale]\n");
    exit(isset($opts['help']) ? 0 : 2);
}
$ttl = $opts['ttl'] ?? '2400';
if (!is_string($ttl) || !ctype_digit($ttl)) {
    fwrite(STDERR, "create_channel: --ttl must be a number of seconds\n");
    exit(2);
}

try {
    $settings = Config::load()->dbApp();
    if (is_string($opts['db'] ?? null)) {
        $settings = $settings->withDatabase($opts['db']);
    }
    $db = Db::connect($settings);
    $ips = is_string($opts['ips'] ?? null) ? explode(',', $opts['ips']) : [];
    $made = (new ChannelAdmin($db))->create(
        $opts['code'],
        $opts['name'],
        is_string($opts['warehouse'] ?? null) ? $opts['warehouse'] : 'MAIN',
        $ips,
        is_string($opts['mode'] ?? null) ? $opts['mode'] : 'off',
        (int) $ttl,
        is_string($opts['movement-types'] ?? null) ? explode(',', $opts['movement-types']) : [],
    );
    $ipCount = count(ChannelAdmin::ips($ips));
    fwrite(STDERR, "channel {$opts['code']} created (id {$made['id']}); its API key follows on stdout and is shown ONLY now.\n");
    if ($ipCount === 0) {
        fwrite(STDERR, "warning: no --ips given: every call is refused until the site's address is on the allowlist.\n");
    }
    fwrite(STDOUT, $made['key'] . "\n");
    exit(0);
} catch (CwException $e) {
    fwrite(STDERR, "create_channel: {$e->getMessage()}\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'create_channel: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
