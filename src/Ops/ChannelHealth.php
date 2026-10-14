<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Clock;
use CW\Db;
use DateTimeImmutable;

/**
 * Reads channel_health (one row per site heartbeat, D18) and lists what needs attention
 * (plan §6.3 "CW also alerts centrally from channel_health", §6.2 "a mismatch alerts"). bin/health_alert.php prints them, and since
 * pack F (docs/decisions.md PF10) CW\Ops\Alerts adds CW's own checks and AlertDispatch sends them to a person.
 *
 * Only channels in `shadow` or `live` are expected to report; `off` channels are skipped.
 *   stale          no heartbeat at all, or the newest is older than the threshold (the site
 *                  worker sends one every 60 s, so the default of 180 s = three missed beats)
 *   dead_letters   the newest heartbeat reports more dead-lettered outbox rows than allowed (default: any)
 *   mode_mismatch  the site's own CW_MODE differs from channel.mode (effective = the lower)
 * With $limits (pack F; each a setting alerts.*, checked only when given):
 *   outbox_deep    more messages waiting in the site's outbox than `outbox_depth`
 *   outbox_old     the oldest waiting message is older than `outbox_age` seconds
 *   feed_lag       at its newest heartbeat the site was `feed_lag` seconds or more behind CW's change feed: the time from the first
 *                  change after the last seq it applied to the heartbeat (a change made after the heartbeat is not counted)
 */
final class ChannelHealth
{
    public const STALE_AFTER_SEC = 180;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array{outbox_depth?: int, outbox_age?: int, dead_letters?: int, feed_lag?: int} $limits
     * @return list<array{channel: string, channel_id: int, name: string, mode: string, problem: string, detail: string, data: array<string, int|string|null>}>
     */
    public function problems(?DateTimeImmutable $now = null, int $staleAfterSec = self::STALE_AFTER_SEC, array $limits = []): array
    {
        $now ??= Clock::now();
        $rows = $this->db->all(
            'SELECT c.id, c.code, c.name, c.mode, h.received_at, h.site_mode, h.dead_letters, h.outbox_depth, h.outbox_oldest_age_sec, h.last_seq, '
            . '(SELECT sc.created_at FROM stock_change sc WHERE sc.seq > h.last_seq ORDER BY sc.seq LIMIT 1) AS first_unapplied_at '
            . 'FROM channel c LEFT JOIN channel_health h ON h.id = (SELECT MAX(h2.id) FROM channel_health h2 WHERE h2.channel_id = c.id) '
            . "WHERE c.mode <> 'off' ORDER BY c.code",
        );
        $out = [];
        foreach ($rows as $r) {
            $code = (string) $r['code'];
            $mode = (string) $r['mode'];
            $base = ['channel' => $code, 'channel_id' => (int) $r['id'], 'name' => (string) $r['name'], 'mode' => $mode];
            if ($r['received_at'] === null) {
                $out[] = $base + ['problem' => 'stale', 'detail' => 'no heartbeat ever received', 'data' => ['age' => null, 'limit' => $staleAfterSec]];
                continue;
            }
            $age = (int) floor(Clock::diff(Clock::fromDb((string) $r['received_at']), $now));
            if ($age > $staleAfterSec) {
                $out[] = $base + ['problem' => 'stale',
                    'detail' => sprintf('last heartbeat %s (%d s ago; threshold %d s)', Clock::iso((string) $r['received_at']), $age, $staleAfterSec),
                    'data' => ['age' => $age, 'limit' => $staleAfterSec]];
            }
            $dead = (int) ($r['dead_letters'] ?? 0);
            $deadMax = $limits['dead_letters'] ?? 0;
            if ($dead > $deadMax) {
                $out[] = $base + ['problem' => 'dead_letters',
                    'detail' => sprintf('%d dead-lettered outbox rows (outbox depth %s, oldest %s s)', $dead, $r['outbox_depth'] ?? '?', $r['outbox_oldest_age_sec'] ?? '?'),
                    'data' => ['dead' => $dead, 'limit' => $deadMax]];
            }
            if (isset($limits['outbox_depth']) && $r['outbox_depth'] !== null && (int) $r['outbox_depth'] > $limits['outbox_depth']) {
                $out[] = $base + ['problem' => 'outbox_deep', 'detail' => sprintf('%d messages waiting in the outbox (limit %d)', (int) $r['outbox_depth'], $limits['outbox_depth']),
                    'data' => ['depth' => (int) $r['outbox_depth'], 'limit' => $limits['outbox_depth']]];
            }
            if (isset($limits['outbox_age']) && $r['outbox_oldest_age_sec'] !== null && (int) $r['outbox_oldest_age_sec'] > $limits['outbox_age']) {
                $out[] = $base + ['problem' => 'outbox_old',
                    'detail' => sprintf('the oldest outbox message is %d s old (limit %d s)', (int) $r['outbox_oldest_age_sec'], $limits['outbox_age']),
                    'data' => ['age' => (int) $r['outbox_oldest_age_sec'], 'limit' => $limits['outbox_age']]];
            }
            if (isset($limits['feed_lag']) && $r['first_unapplied_at'] !== null && (string) $r['first_unapplied_at'] <= (string) $r['received_at']) {
                $lag = (int) floor(Clock::diff(Clock::fromDb((string) $r['first_unapplied_at']), Clock::fromDb((string) $r['received_at'])));
                if ($lag >= $limits['feed_lag']) {
                    $out[] = $base + ['problem' => 'feed_lag', 'detail' => sprintf('%d s behind the change feed at its last heartbeat (limit %d s)', $lag, $limits['feed_lag']),
                        'data' => ['lag' => $lag, 'limit' => $limits['feed_lag']]];
                }
            }
            if ($r['site_mode'] !== null && (string) $r['site_mode'] !== $mode) {
                $out[] = $base + ['problem' => 'mode_mismatch',
                    'detail' => "site reports CW_MODE={$r['site_mode']}, CW has {$mode} (the lower one applies)",
                    'data' => ['site_mode' => (string) $r['site_mode']]];
            }
        }
        return $out;
    }
}
