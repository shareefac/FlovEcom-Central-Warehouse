<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Clock;
use CW\Db;
use CW\Settings;
use CW\Ui\Words;

/**
 * Sends what Alerts found to a person (go-live plan part 6 "Alerts to a person"; docs/decisions.md PF10), keeping alert_state (0036):
 *
 *  - where: alerts.webhook_url (an https address: a JSON POST {text, alerts, cleared, system}, the `text` a chat channel shows) and
 *    alerts.email_to (a plain-text e-mail). Both EMPTY by default: then nothing is sent and no transport is called (the run still
 *    records what it saw, so the first run after an address is set sends what is open);
 *  - once per window: a problem is sent when it is new, when it came back after it cleared, or when it was last sent
 *    alerts.repeat_minutes ago or more; otherwise it is only recorded (last seen, its words);
 *  - a problem that has gone is marked cleared, and a "back to normal" line is sent for it when it had been sent; one that could not
 *    be sent (every destination failed, or none was set) is owed and sent on a later run, once (clear_sent_at; review finding 8);
 *  - one message per run with every line; a destination that fails is reported (never its address: a web address can hold a token),
 *    and the problems count as sent when at least one destination took the message (else they are tried again next run).
 * One run at a time (bin/health_alert.php takes the job lock).
 */
final class AlertDispatch
{
    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, private readonly AlertTransport $transport, private readonly ?\Closure $clock = null)
    {
    }

    /**
     * @param list<array<string, mixed>> $problems Alerts::problems()
     * @return array{sent: list<string>, held: list<string>, cleared: list<string>, destinations: int, failed: list<string>}
     *         sent: keys in this run's message; held: keys still inside their window (or no destination); cleared: keys that went
     */
    public function run(array $problems, Settings $s, string $system): array
    {
        $now = ($this->clock ?? static fn (): \DateTimeImmutable => Clock::now())();
        $at = Clock::db($now);
        $url = $s->has('alerts.webhook_url') ? trim((string) $s->get('alerts.webhook_url')) : '';
        $mail = $s->has('alerts.email_to') ? trim((string) $s->get('alerts.email_to')) : '';
        $destinations = ($url !== '' ? 1 : 0) + ($mail !== '' ? 1 : 0);
        $window = max(1, Alerts::int($s, 'alerts.repeat_minutes', 60)) * 60;
        $state = [];
        foreach ($this->db->all('SELECT alert_key, last_sent_at, cleared_at, clear_sent_at, detail FROM alert_state') as $r) {
            $state[(string) $r['alert_key']] = $r;
        }
        $send = [];
        $held = [];
        $current = [];
        foreach ($problems as $p) {
            $key = mb_strcut((string) $p['key'], 0, 191, 'UTF-8');
            $current[$key] = true;
            $words = self::line($p);
            $row = $state[$key] ?? null;
            $newEpisode = $row === null || $row['cleared_at'] !== null;
            if ($row === null) {
                $this->db->exec('INSERT INTO alert_state (alert_key, kind, channel_id, detail, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$key, (string) $p['kind'], $p['channel_id'] ?? null, mb_substr($words, 0, 1000), $at, $at]);
            } elseif ($newEpisode) {
                // It came back: a new episode (a "back to normal" still owed for the last one is moot now).
                $this->db->exec('UPDATE alert_state SET detail = ?, first_seen_at = ?, last_seen_at = ?, last_sent_at = NULL, cleared_at = NULL, clear_sent_at = NULL '
                    . 'WHERE alert_key = ?',
                    [mb_substr($words, 0, 1000), $at, $at, $key]);
            } else {
                $this->db->exec('UPDATE alert_state SET detail = ?, last_seen_at = ? WHERE alert_key = ?', [mb_substr($words, 0, 1000), $at, $key]);
            }
            $lastSent = $newEpisode || $row['last_sent_at'] === null ? null : Clock::fromDb((string) $row['last_sent_at']);
            $due = $lastSent === null || Clock::diff($lastSent, $now) >= $window;
            if ($due && $destinations > 0) {
                $send[$key] = $words;
            } else {
                $held[] = $key;
            }
        }
        $cleared = [];
        $clearedWords = [];
        foreach ($state as $key => $row) {
            if (isset($current[$key])) {
                continue;
            }
            if ($row['cleared_at'] === null) {
                $this->db->exec('UPDATE alert_state SET cleared_at = ? WHERE alert_key = ? AND cleared_at IS NULL', [$at, $key]);
                $cleared[] = (string) $key;
                $row['cleared_at'] = $at;
            }
            // A "back to normal" is owed for a cleared alert that had been sent, until one is sent.
            if ($row['last_sent_at'] !== null && $row['clear_sent_at'] === null && $destinations > 0) {
                $clearedWords[$key] = Words::say('ALERT', 'cleared', (string) $row['detail']);
            }
        }
        $failed = [];
        if ($destinations > 0 && ($send !== [] || $clearedWords !== [])) {
            $timeout = max(1, Alerts::int($s, 'alerts.send_timeout_seconds', 5));
            $subject = Words::say('ALERT', $send === [] ? 'subject_clear' : 'subject', count($send), $system);
            $text = implode("\n", [Words::say('ALERT', 'intro', $system), ...array_map(static fn (string $w): string => '- ' . $w, array_values($send)),
                ...array_map(static fn (string $w): string => '- ' . $w, array_values($clearedWords))]);
            $took = 0;
            if ($url !== '') {
                try {
                    $this->transport->webhook($url, ['text' => $text, 'system' => $system, 'alerts' => array_keys($send), 'cleared' => array_keys($clearedWords)], $timeout);
                    $took++;
                } catch (\Throwable $e) {
                    $failed[] = 'web address: ' . $e->getMessage();
                }
            }
            if ($mail !== '') {
                try {
                    $this->transport->email($mail, $subject, $text . "\n\n" . Words::ALERT['footer']);
                    $took++;
                } catch (\Throwable $e) {
                    $failed[] = 'e-mail: ' . $e->getMessage();
                }
            }
            if ($took > 0) {
                foreach (array_keys($send) as $key) {
                    $this->db->exec('UPDATE alert_state SET last_sent_at = ?, sends = sends + 1 WHERE alert_key = ?', [$at, $key]);
                }
                foreach (array_keys($clearedWords) as $key) {
                    $this->db->exec('UPDATE alert_state SET clear_sent_at = ? WHERE alert_key = ? AND cleared_at IS NOT NULL', [$at, $key]);
                }
            } else {
                $held = [...$held, ...array_keys($send)];
                $send = [];
            }
        }
        return ['sent' => array_keys($send), 'held' => $held, 'cleared' => $cleared, 'destinations' => $destinations, 'failed' => $failed];
    }

    /** One problem in words for a person (Words::ALERT). @param array<string, mixed> $p */
    public static function line(array $p): string
    {
        $d = is_array($p['data'] ?? null) ? $p['data'] : [];
        $store = (string) ($p['name'] ?? $p['channel'] ?? '');
        $n = static fn (string $k): int => (int) ($d[$k] ?? 0);
        return match ((string) $p['kind']) {
            'stale' => ($d['age'] ?? null) === null ? Words::say('ALERT', 'heartbeat_never', $store)
                : Words::say('ALERT', 'heartbeat_late', $store, self::duration($n('age')), $n('limit')),
            'outbox_deep' => Words::say('ALERT', 'outbox_deep', $store, $n('depth'), $n('limit')),
            'outbox_old' => Words::say('ALERT', 'outbox_old', $store, self::duration($n('age')), $n('limit')),
            'dead_letters' => Words::say('ALERT', 'dead_letters', $store, $n('dead'), $n('limit')),
            'feed_lag' => Words::say('ALERT', 'feed_lag', $store, self::duration($n('lag')), $n('limit')),
            'mode_mismatch' => Words::say('ALERT', 'mode_mismatch', $store, Words::of('MODE', (string) ($d['site_mode'] ?? '')), Words::of('MODE', (string) ($p['mode'] ?? ''))),
            'answer_slow' => Words::say('ALERT', 'answer_slow', $n('ms'), $n('probes'), $n('limit')),
            'answer_failed' => Words::ALERT['answer_failed'],
            'invariants_failed' => Words::say('ALERT', 'invariants_failed', $n('problems')),
            'invariants_stale' => Words::say('ALERT', 'invariants_stale', $n('hours')),
            default => (string) ($p['detail'] ?? $p['kind']),
        };
    }

    /** "3 minutes", "2 hours", "45 seconds". */
    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds >= 7200 => Words::say('ALERT', 'hours', intdiv($seconds, 3600)),
            $seconds >= 120 => Words::say('ALERT', 'minutes', intdiv($seconds, 60)),
            default => Words::say('ALERT', 'seconds', $seconds),
        };
    }
}
