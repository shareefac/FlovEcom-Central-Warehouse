<?php

// Sends ONE test message to alerts.webhook_url through CW's own sender (CW\Ops\AlertSender, the payload health_alert.php posts;
// docs/ops.md "The production server", "Alerts"). Never prints the address (a secret setting): only its server. Run as root on
// cw-prod-lon1-01, in /opt/cw-prod: php deploy/production/alert_test.php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = CW\Db::connect(CW\Config::loadApp()->dbApp());
$s = new CW\Settings($db);
$url = $s->has('alerts.webhook_url') ? trim((string) $s->get('alerts.webhook_url')) : '';
if ($url === '') {
    fwrite(STDERR, "alerts.webhook_url is empty: set it on Settings > System > Settings, topic Alerts\n");
    exit(1);
}
$where = 'https://' . parse_url($url, PHP_URL_HOST) . '/...' . (str_ends_with($url, '/slack') ? '/slack' : ' (no /slack at the end)');
$text = 'CW production (cw_prod): a test message from the alert setup, ' . gmdate('Y-m-d H:i') . ' UTC. Nothing is wrong.';
try {
    (new CW\Ops\AlertSender())->webhook($url, ['text' => $text, 'system' => 'cw_prod', 'alerts' => [], 'cleared' => []], 10);
    echo "sent to {$where}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "NOT sent to {$where}: " . $e->getMessage() . "\n");
    exit(1);
}
