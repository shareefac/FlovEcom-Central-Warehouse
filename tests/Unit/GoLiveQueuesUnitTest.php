<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\ChannelAdmin;
use CW\Ops\AlertDispatch;
use CW\Relay\RelayLines;
use CW\Settings;
use CW\Ui\Sections;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * Pack F without a database (docs/decisions.md PF1-PF14): the schedule of the two jobs, the settings' floors, who may do what, the
 * tab's place, and the alert lines in words.
 */
final class GoLiveQueuesUnitTest extends TestCase
{
    private const DIR = __DIR__ . '/../../deploy/staging';

    public function testTheAlertsAndTheCleanUpAreScheduledAndSmokeTestedWithoutEffect(): void
    {
        $cron = (string) file_get_contents(self::DIR . '/cw-staging.cron');
        self::assertMatchesRegularExpression('#^\*/2 \* \* \* \*  root  cd /opt/cw-staging && php bin/health_alert\.php --db=cw_staging >> /var/log/cw/health_alert\.log 2>&1$#m', $cron);
        self::assertMatchesRegularExpression('#^37 3 \* \* \*  root  cd /opt/cw-staging && php bin/housekeeping\.php --db=cw_staging >> /var/log/cw/housekeeping\.log 2>&1$#m', $cron);
        self::assertDoesNotMatchRegularExpression('#^[^\#\n]*housekeeping\.php[^\n]*--admin#m', $cron, 'the cron never removes audit rows: the app login cannot');
        self::assertSame(1, preg_match_all('#^[^\#\n]*health_alert\.php#m', $cron), 'one line');
        $install = (string) file_get_contents(self::DIR . '/install_cron.sh');
        self::assertMatchesRegularExpression('#^for job in [^\n]*"health_alert\.php --no-send"[^\n]*"housekeeping\.php --dry-run"[^\n]*; do$#m', $install,
            'the installer\'s smoke run records, sends and removes nothing');
        foreach (['health_alert', 'housekeeping'] as $tool) {
            self::assertFileExists(dirname(self::DIR, 2) . "/bin/{$tool}.php");
        }
    }

    public function testTheSettingsKeepTheirFloorsAndTheAddressesStartEmpty(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0036_golive_queues_alerts.sql');
        self::assertMatchesRegularExpression("/\\('alerts\\.webhook_url','string','\"\"'/", $sql, 'empty by default');
        self::assertMatchesRegularExpression("/\\('alerts\\.email_to','string','\"\"'/", $sql, 'empty by default');
        preg_match_all("/^ \\('([a-z_]+\\.[a-z_.]+)','/m", $sql, $m);
        self::assertCount(21, $m[1]);
        foreach ($m[1] as $key) {
            self::assertStringContainsString("'{$key}'", substr($sql, (int) strpos($sql, 'INSERT INTO config_change')), "{$key} has its baseline");
            if ($key !== 'relay.correction_is_for_sale') { // a switch: on or off is its whole rule
                self::assertArrayHasKey($key, Settings::RULES, "{$key} has its rule");
            }
        }
        self::assertSame(30, Settings::RULES['housekeeping.idempotency_keep_days']['min'], 'D14: at least 30 days');
        self::assertSame(7, Settings::RULES['housekeeping.audit_keep_years']['min'], 'records are kept year end + 6 years');
        self::assertSame(1, preg_match(Settings::RULES['alerts.webhook_url']['pattern'], 'https://hooks.example.com/services/T0/B0/abc'));
        self::assertSame(0, preg_match(Settings::RULES['alerts.webhook_url']['pattern'], 'http://hooks.example.com/x'), 'https only');
        foreach (['alerts', 'housekeeping', 'relay'] as $topic) {
            self::assertNotSame('Other', Words::settingTopic($topic . '.x'));
        }
    }

    public function testWhoMayAnswerTheQueues(): void
    {
        foreach (['queues.suspense', 'queues.corrections', 'queues.modes', 'queues.oversell'] as $perm) {
            self::assertFalse(Permissions::can(['admin'], $perm), "{$perm}: never admin (I12)");
            self::assertFalse(Permissions::can(['auditor'], $perm), "{$perm}: an auditor only looks");
            self::assertTrue(Permissions::can(['stock_controller'], $perm), $perm);
            self::assertArrayHasKey($perm, Words::PERMISSION);
        }
        self::assertTrue(Permissions::can(['auditor'], 'queues.view'));
        self::assertFalse(Permissions::can(['admin'], 'queues.view'));
        self::assertFalse(Permissions::can(['purchasing_desk'], 'queues.corrections'), 'a correction moves stock without a count: the stock controller\'s');
        $tab = Sections::MAP['approvals']['tabs']['store_queues'];
        self::assertSame(['queue_suspense', 'queue_corrections', 'queue_modes', 'queue_oversold'], array_column($tab['pages'], 'key'));
        self::assertSame(['approvals', 'store_queues', 'queue_modes'], array_values(Sections::locate('/ui/queues/modes')));
        self::assertSame('Approvals › From the stores › Oversold', Sections::where('/ui/queues/oversold'));
    }

    /** The queue pages take every word from Words (no word typed in a template), and every POST form carries the token. */
    public function testTheQueuePagesTakeEveryWordFromWords(): void
    {
        foreach (['queue_filter', 'queue_suspense', 'queue_corrections', 'queue_modes', 'queue_oversold'] as $name) {
            $src = (string) file_get_contents(\CW\Ui\View::defaultDir() . "/{$name}.php");
            $text = (string) preg_replace(['/<\?.*?\?>/s', '/<[^>]*>/', '/&[a-z]+;/'], ' ', $src);
            self::assertSame([], preg_match_all('/[A-Za-z]{2,}/', $text, $m) > 0 ? $m[0] : [], "{$name}: a word typed in the template instead of taken from Words");
            preg_match_all('#<form\b([^>]*)>(.*?)</form>#is', $src, $forms, PREG_SET_ORDER);
            foreach ($forms as $f) {
                if (preg_match('/method="post"/i', $f[1]) === 1) {
                    self::assertMatchesRegularExpression('#<input type="hidden" name="csrf" value="<\?= \$e\(\$csrf\) \?>">#', $f[2], $name);
                }
            }
            foreach (['(UTC)', '$dt(', '<svg', '<img', 'data:', 'style='] as $old) {
                self::assertStringNotContainsString($old, $src, "{$name}: {$old}");
            }
        }
    }

    /**
     * Review finding 7 (Codex, 14 Oct 2026): the alerts' web address must lead to a public server: every address its name resolves to
     * is checked (private, loopback, link-local, reserved are refused), and the request is pinned to the address checked.
     */
    public function testTheWebAddressMustLeadToAPublicServer(): void
    {
        $resolve = static fn (array $ips): \Closure => static fn (string $host): array => $ips;
        $ok = \CW\Ops\AlertSender::destination('https://hooks.example.com/services/x', $resolve(['93.184.216.34']));
        self::assertSame(['host' => 'hooks.example.com', 'port' => 443, 'ip' => '93.184.216.34'], $ok);
        self::assertSame(8443, \CW\Ops\AlertSender::destination('https://hooks.example.com:8443/x', $resolve(['93.184.216.34']))['port']);
        foreach ([['10.0.0.5'], ['172.16.3.4'], ['192.168.1.1'], ['127.0.0.1'], ['169.254.169.254'], ['100.64.0.1'], ['0.0.0.0'], ['::1'], ['fd00::1'],
            ['fe80::1'], ['93.184.216.34', '10.1.1.1'], []] as $ips) {
            try {
                \CW\Ops\AlertSender::destination('https://internal.example.com/x', $resolve($ips));
                self::fail('refused: ' . implode(',', $ips));
            } catch (\RuntimeException $e) {
                self::assertStringNotContainsString('internal.example.com/x', $e->getMessage(), 'never the address itself');
            }
        }
        foreach (['http://hooks.example.com/x', 'https://127.0.0.1/x', 'https://[::1]/x', 'ftp://x.example.com/'] as $url) {
            try {
                \CW\Ops\AlertSender::destination($url, $resolve(['93.184.216.34']));
                self::fail("refused: {$url}");
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
        }
        $sender = new \CW\Ops\AlertSender($resolve(['10.0.0.9']));
        try {
            $sender->webhook('https://hooks.example.com/x', ['text' => 'x'], 1);
            self::fail('a private server is never called');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('not a public', $e->getMessage());
        }
    }

    public function testTheRelayGrantsAndTheAlertLinesInWords(): void
    {
        self::assertSame(RelayLines::KINDS, ChannelAdmin::RELAY_GRANTS, 'every kind a store relays is one grant');
        self::assertSame(['erp_reconciliation', 'erp_sale', 'goods_in'], ChannelAdmin::movementTypes(['goods_in', 'erp_sale', 'erp_reconciliation', 'goods_in']));
        $line = static fn (string $kind, array $data, ?string $name = 'Vape and Go', string $mode = 'live'): string => AlertDispatch::line(['kind' => $kind, 'data' => $data,
            'name' => $name, 'channel' => 'vpg', 'mode' => $mode]);
        self::assertSame('Vape and Go has not reported for 7 minutes (the limit is 180 seconds).', $line('stale', ['age' => 420, 'limit' => 180]));
        self::assertSame('Vape and Go is switched on but has never reported.', $line('stale', ['age' => null, 'limit' => 180]));
        self::assertSame('Vape and Go has 1,200 messages waiting to reach the warehouse system (the limit is 500).', $line('outbox_deep', ['depth' => 1200, 'limit' => 500]));
        self::assertSame('Vape and Go has 3 messages that failed for good (the limit is 0).', $line('dead_letters', ['dead' => 3, 'limit' => 0]));
        self::assertSame('Vape and Go is 3 hours behind the warehouse system\'s stock changes (the limit is 60 seconds).', $line('feed_lag', ['lag' => 10800, 'limit' => 60]));
        self::assertSame('Vape and Go runs as Watching only, but the warehouse system has it as Live. The lower one applies.',
            $line('mode_mismatch', ['site_mode' => 'shadow']));
        self::assertSame('The last nightly safety check found 2 problems.', $line('invariants_failed', ['problems' => 2], null, ''));
        self::assertSame('Warehouse system: back to normal (cw_staging)', Words::say('ALERT', 'subject_clear', 0, 'cw_staging'));
    }
}
