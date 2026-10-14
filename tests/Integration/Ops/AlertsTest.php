<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Ops;

use CW\Clock;
use CW\Ops\AlertDispatch;
use CW\Ops\Alerts;
use CW\Ops\AlertTransport;
use CW\Ops\IntegrityRuns;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use DateTimeImmutable;

/**
 * The alerts to a person (pack F; docs/decisions.md PF10): each check reports its problem with its limit from the settings; an
 * alert is sent once per window, again when it comes back, with a "back to normal" line when it clears; nothing at all is sent
 * while both addresses are empty; a failed destination is tried again on the next run.
 */
final class AlertsTest extends IntegrationTestCase
{
    /** @var array<string, string> setting => value_json as the test found it */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        $this->saved = [];
    }

    public function testEachCheckReportsItsProblemAgainstItsSetting(): void
    {
        $vpg = self::makeChannel('vpg', 'live');
        $alt = self::makeChannel('alt', 'shadow');
        self::makeChannel('off1', 'off');
        $now = Clock::now();
        // vpg reported 20 s ago: 600 waiting, the oldest 400 s old, 2 failed for good, and 90 s behind the feed.
        self::$db->insert('INSERT INTO stock_change (reason, created_at) VALUES (?, ?)', ['stock', Clock::db($now->modify('-130 seconds'))]);
        $seq = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');
        self::$db->insert('INSERT INTO stock_change (reason, created_at) VALUES (?, ?)', ['stock', Clock::db($now->modify('-110 seconds'))]);
        self::$db->exec('INSERT INTO channel_health (channel_id, received_at, site_mode, outbox_depth, outbox_oldest_age_sec, dead_letters, last_seq) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$vpg, Clock::db($now->modify('-20 seconds')), 'live', 600, 400, 2, $seq]);
        // alt reported 10 minutes ago.
        self::$db->exec('INSERT INTO channel_health (channel_id, received_at, site_mode, outbox_depth, outbox_oldest_age_sec, dead_letters, last_seq) VALUES (?, ?, ?, 0, 0, 0, NULL)',
            [$alt, Clock::db($now->modify('-10 minutes')), 'shadow']);
        (new IntegrityRuns(self::$db))->record(Clock::db($now->modify('-1 hour')), ['C1 something is off'], ['ms' => 1], 'system:test');

        $slow = new Alerts(self::$db, static fn (): float => 2000.0);
        $kinds = array_column($slow->problems(new Settings(self::$db)), 'kind', 'key');
        self::assertSame(['dead_letters:vpg' => 'dead_letters', 'outbox_deep:vpg' => 'outbox_deep', 'outbox_old:vpg' => 'outbox_old', 'feed_lag:vpg' => 'feed_lag',
            'stale:alt' => 'stale', 'answer_slow' => 'answer_slow', 'invariants_failed' => 'invariants_failed'], self::sorted($kinds, ['dead_letters:vpg', 'outbox_deep:vpg',
            'outbox_old:vpg', 'feed_lag:vpg', 'stale:alt', 'answer_slow', 'invariants_failed']));

        // Every limit is a setting: loosened, the store's figures pass; the answer time passes under its limit.
        $this->set('alerts.outbox_max_depth', '1000');
        $this->set('alerts.outbox_max_age_seconds', '600');
        $this->set('alerts.dead_letters_max', '5');
        $this->set('alerts.feed_lag_max_seconds', '120');
        $this->set('alerts.heartbeat_late_seconds', '900');
        $fast = new Alerts(self::$db, static fn (): float => 5.0);
        self::assertSame(['invariants_failed'], array_column($fast->problems(new Settings(self::$db)), 'key'));
        // The nightly check: a good run clears it; no run for too long is a problem; no run at all is quiet (a new system).
        (new IntegrityRuns(self::$db))->record(Clock::db($now->modify('-40 hours')), [], ['ms' => 1], 'system:test');
        self::$db->exec('UPDATE integrity_run SET finished_at = ?, started_at = ? WHERE ok = 1', [Clock::db($now->modify('-40 hours')), Clock::db($now->modify('-40 hours'))]);
        self::$db->exec('DELETE FROM integrity_run WHERE ok = 0');
        $p = $fast->problems(new Settings(self::$db));
        self::assertSame(['invariants_stale'], array_column($p, 'key'));
        self::assertSame('No nightly safety check has finished for 40 hours.', AlertDispatch::line($p[0]));
        self::$db->exec('DELETE FROM integrity_run');
        self::assertSame([], $fast->problems(new Settings(self::$db)));
        // A probe that cannot connect is its own alert.
        $down = new Alerts(self::$db, static function (): float {
            throw new \RuntimeException('refused');
        });
        self::assertSame(['answer_failed'], array_column($down->problems(new Settings(self::$db)), 'key'));
    }

    public function testAnAlertIsSentOncePerWindowAgainWhenItComesBackAndClears(): void
    {
        $this->set('alerts.webhook_url', '"https://hooks.example.com/services/T0/B0/x"');
        $this->set('alerts.email_to', '"oncall@example.com"');
        $this->set('alerts.repeat_minutes', '60');
        $sent = new RecordingTransport();
        $t = new DateTimeImmutable('2026-10-14 10:00:00', Clock::utc());
        $clock = static function () use (&$t): DateTimeImmutable {
            return $t;
        };
        $run = fn (array $problems): array => (new AlertDispatch(self::$db, $sent, $clock))->run($problems, new Settings(self::$db), 'cw_test');
        $late = ['key' => 'stale:vpg', 'kind' => 'stale', 'channel' => 'vpg', 'channel_id' => null, 'name' => 'Vape and Go', 'mode' => 'live', 'detail' => 'x',
            'data' => ['age' => 400, 'limit' => 180]];

        self::assertSame(['stale:vpg'], $run([$late])['sent']);
        self::assertCount(1, $sent->webhooks);
        self::assertCount(1, $sent->mails);
        self::assertStringContainsString('Vape and Go has not reported for 6 minutes (the limit is 180 seconds).', $sent->webhooks[0]['payload']['text']);
        self::assertSame('oncall@example.com', $sent->mails[0]['to']);
        // Inside the window: recorded, not sent.
        $t = $t->modify('+30 minutes');
        $r = $run([$late]);
        self::assertSame([[], ['stale:vpg']], [$r['sent'], $r['held']]);
        self::assertCount(1, $sent->webhooks);
        // The window has passed: sent again.
        $t = $t->modify('+31 minutes');
        self::assertSame(['stale:vpg'], $run([$late])['sent']);
        self::assertCount(2, $sent->webhooks);
        self::assertSame(2, (int) self::$db->value("SELECT sends FROM alert_state WHERE alert_key = 'stale:vpg'"));
        // It clears: one "back to normal" line, once.
        $t = $t->modify('+2 minutes');
        $r = $run([]);
        self::assertSame(['stale:vpg'], $r['cleared']);
        self::assertCount(3, $sent->webhooks);
        self::assertStringContainsString('Back to normal: Vape and Go has not reported', $sent->webhooks[2]['payload']['text']);
        $run([]);
        self::assertCount(3, $sent->webhooks, 'cleared once');
        // It comes back a minute later: a new episode, sent at once.
        $t = $t->modify('+1 minute');
        self::assertSame(['stale:vpg'], $run([$late])['sent']);
        self::assertCount(4, $sent->webhooks);
    }

    public function testNothingIsSentWhileBothAddressesAreEmptyAndAFailedSendIsTriedAgain(): void
    {
        $this->set('alerts.webhook_url', '""');
        $this->set('alerts.email_to', '""');
        $sent = new RecordingTransport();
        $p = ['key' => 'answer_slow', 'kind' => 'answer_slow', 'channel' => null, 'channel_id' => null, 'name' => null, 'mode' => null, 'detail' => 'x',
            'data' => ['ms' => 2500, 'probes' => 3, 'limit' => 1500]];
        $r = (new AlertDispatch(self::$db, $sent))->run([$p], new Settings(self::$db), 'cw_test');
        self::assertSame([0, [], ['answer_slow']], [$r['destinations'], $r['sent'], $r['held']]);
        self::assertSame([[], []], [$sent->webhooks, $sent->mails], 'silent: no transport is called');
        self::assertNull(self::$db->value("SELECT last_sent_at FROM alert_state WHERE alert_key = 'answer_slow'"), 'recorded, not sent');

        // An address is set: the open problem is sent on the next run, even inside the window it was first seen in.
        $this->set('alerts.webhook_url', '"https://hooks.example.com/x"');
        $sent->fail = true;
        $r = (new AlertDispatch(self::$db, $sent))->run([$p], new Settings(self::$db), 'cw_test');
        self::assertSame([[], ['web address: the web address answered HTTP 500']], [$r['sent'], $r['failed']]);
        self::assertNull(self::$db->value("SELECT last_sent_at FROM alert_state WHERE alert_key = 'answer_slow'"), 'a failed send is tried again');
        $sent->fail = false;
        $r = (new AlertDispatch(self::$db, $sent))->run([$p], new Settings(self::$db), 'cw_test');
        self::assertSame(['answer_slow'], $r['sent']);
        self::assertStringContainsString('The warehouse system took 2,500 milliseconds to answer (the middle of 3 tries; the limit is 1,500).',
            $sent->webhooks[0]['payload']['text']);
        self::assertStringNotContainsString('hooks.example.com', implode(' ', $r['failed']));
    }

    /** Review finding 8 (Codex, 14 Oct 2026): a "back to normal" that could not be sent is sent on the next run, once. */
    public function testABackToNormalThatFailedIsSentOnTheNextRun(): void
    {
        $this->set('alerts.webhook_url', '"https://hooks.example.com/x"');
        $sent = new RecordingTransport();
        $late = ['key' => 'stale:vpg', 'kind' => 'stale', 'channel' => 'vpg', 'channel_id' => null, 'name' => 'Vape and Go', 'mode' => 'live', 'detail' => 'x',
            'data' => ['age' => 400, 'limit' => 180]];
        $run = fn (array $p): array => (new AlertDispatch(self::$db, $sent))->run($p, new Settings(self::$db), 'cw_test');
        self::assertSame(['stale:vpg'], $run([$late])['sent']);
        $sent->fail = true;
        $r = $run([]);
        self::assertSame(['stale:vpg'], $r['cleared']);
        self::assertNotSame([], $r['failed']);
        $sent->fail = false;
        $run([]);
        self::assertCount(2, $sent->webhooks, 'the alert, then its "back to normal" on the next run');
        self::assertStringContainsString('Back to normal: Vape and Go', $sent->webhooks[1]['payload']['text']);
        $run([]);
        self::assertCount(2, $sent->webhooks, 'sent once');
    }

    /** Review finding 6: the web address (it can hold a token) is never shown in full, nor kept in the history or the audit log. */
    public function testTheWebAddressIsNeverShownOrKeptInHistory(): void
    {
        $saved = self::$db->one("SELECT CAST(value_json AS CHAR) AS v, updated_actor, updated_at FROM app_setting WHERE setting_key = 'alerts.webhook_url'");
        try {
            $settings = new Settings(self::$db);
            $settings->set(\CW\Caller::system('alert_test'), 'alerts.webhook_url', 'https://hooks.example.com/services/T0/B0/SECRET123', 'the on-call channel');
            $fresh = new Settings(self::$db);
            self::assertSame('https://hooks.example.com/services/T0/B0/SECRET123', $fresh->get('alerts.webhook_url'), 'the sender reads it whole');
            $row = array_values(array_filter($fresh->all(), static fn (array $r): bool => $r['key'] === 'alerts.webhook_url'))[0];
            self::assertStringNotContainsString('SECRET123', (string) $row['value'] . (string) $row['display']);
            self::assertStringContainsString('hooks.example.com', (string) $row['display'], 'which server it goes to stays readable');
            self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_key = 'alerts.webhook_url' "
                . "AND (CAST(state AS CHAR) LIKE '%SECRET123%' OR CAST(before_state AS CHAR) LIKE '%SECRET123%')"));
            self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE CAST(detail AS CHAR) LIKE '%SECRET123%'"));
            self::assertSame([], \CW\Admin\ConfigInvariants::check(self::$db), 'the history still matches the setting');
            // Saving the form as it was drawn (the masked address) keeps the address.
            $again = (new Settings(self::$db))->set(\CW\Caller::system('alert_test'), 'alerts.webhook_url', (string) $row['value'], 'unchanged');
            self::assertFalse($again['changed']);
            self::assertSame('https://hooks.example.com/services/T0/B0/SECRET123', (new Settings(self::$db))->get('alerts.webhook_url'));
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON), updated_actor = ?, updated_at = ? WHERE setting_key = 'alerts.webhook_url'",
                [$saved['v'], $saved['updated_actor'], $saved['updated_at']]);
        }
    }

    /** @param array<string, string> $a @param list<string> $order @return array<string, string> */
    private static function sorted(array $a, array $order): array
    {
        $out = [];
        foreach ($order as $k) {
            if (isset($a[$k])) {
                $out[$k] = $a[$k];
            }
        }
        return count($out) === count($a) ? $out : $a;
    }

    private function set(string $key, string $json): void
    {
        $this->saved[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }
}

/** An AlertTransport that keeps what it was given (and fails on demand). */
final class RecordingTransport implements AlertTransport
{
    /** @var list<array{url: string, payload: array<string, mixed>, timeout: int}> */
    public array $webhooks = [];
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $mails = [];
    public bool $fail = false;

    public function webhook(string $url, array $payload, int $timeoutSec): void
    {
        if ($this->fail) {
            throw new \RuntimeException('the web address answered HTTP 500');
        }
        $this->webhooks[] = ['url' => $url, 'payload' => $payload, 'timeout' => $timeoutSec];
    }

    public function email(string $to, string $subject, string $body): void
    {
        $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }
}
