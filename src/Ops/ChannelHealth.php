<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Clock;
use CW\Db;
use DateTimeImmutable;

/**
 * Reads channel_health (one row per site heartbeat, D18) and lists what needs attention
 * (plan §6.3 "CW also alerts centrally from channel_health", §6.2 "a mismatch alerts").
 * A stub for bin/health_alert.php: it reports; wiring it to the webhook comes later.
 *
 * Only channels in `shadow` or `live` are expected to report; `off` channels are skipped.
 *   stale          no heartbeat at all, or the newest is older than the threshold (the site
 *                  worker sends one every 60 s, so the default of 180 s = three missed beats)
 *   dead_letters   the newest heartbeat reports dead-lettered outbox rows
 *   mode_mismatch  the site's own CW_MODE differs from channel.mode (effective = the lower)
 */
final class ChannelHealth
{
    public const STALE_AFTER_SEC = 180;

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array{channel: string, mode: string, problem: string, detail: string}> */
    public function problems(?DateTimeImmutable $now = null, int $staleAfterSec = self::STALE_AFTER_SEC): array
    {
        $now ??= Clock::now();
        $rows = $this->db->all(
            'SELECT c.code, c.mode, h.received_at, h.site_mode, h.dead_letters, h.outbox_depth, h.outbox_oldest_age_sec '
            . 'FROM channel c LEFT JOIN channel_health h ON h.id = (SELECT MAX(h2.id) FROM channel_health h2 WHERE h2.channel_id = c.id) '
            . "WHERE c.mode <> 'off' ORDER BY c.code",
        );
        $out = [];
        foreach ($rows as $r) {
            $code = (string) $r['code'];
            $mode = (string) $r['mode'];
            if ($r['received_at'] === null) {
                $out[] = ['channel' => $code, 'mode' => $mode, 'problem' => 'stale', 'detail' => 'no heartbeat ever received'];
                continue;
            }
            $age = (int) floor(Clock::diff(Clock::fromDb((string) $r['received_at']), $now));
            if ($age > $staleAfterSec) {
                $out[] = ['channel' => $code, 'mode' => $mode, 'problem' => 'stale',
                    'detail' => sprintf('last heartbeat %s (%d s ago; threshold %d s)', Clock::iso((string) $r['received_at']), $age, $staleAfterSec)];
            }
            if ((int) ($r['dead_letters'] ?? 0) > 0) {
                $out[] = ['channel' => $code, 'mode' => $mode, 'problem' => 'dead_letters',
                    'detail' => sprintf('%d dead-lettered outbox rows (outbox depth %s, oldest %s s)', (int) $r['dead_letters'],
                        $r['outbox_depth'] ?? '?', $r['outbox_oldest_age_sec'] ?? '?')];
            }
            if ($r['site_mode'] !== null && (string) $r['site_mode'] !== $mode) {
                $out[] = ['channel' => $code, 'mode' => $mode, 'problem' => 'mode_mismatch',
                    'detail' => "site reports CW_MODE={$r['site_mode']}, CW has {$mode} (the lower one applies)"];
            }
        }
        return $out;
    }
}
