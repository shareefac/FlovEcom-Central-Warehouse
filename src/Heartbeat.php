<?php

declare(strict_types=1);

namespace CW;

/**
 * POST /v1/heartbeat (plan §3, §14 shadow acceptance): the site worker reports its outbox
 * depth/age, dead letters, the last feed seq it applied, its own mode and connector version.
 * One `channel_health` row per heartbeat (D18). The answer carries the channel's mode in CW
 * (the site's effective mode is the lower of the two, §12) and the current feed head.
 * Idempotent like every POST (a retried heartbeat adds no second row).
 */
final class Heartbeat
{
    public const MAX_PAYLOAD_BYTES = 16384;
    private const MODES = ['off', 'shadow', 'live'];

    private readonly Idempotency $idem;

    public function __construct(private readonly Db $db)
    {
        $this->idem = new Idempotency($db);
    }

    /** @param array<string, mixed> $body */
    public function record(Caller $caller, array $body, string $idemKey): OpResult
    {
        if (!$caller->isChannel()) {
            throw new CwException('channel_required', 'heartbeats come from a site', 403);
        }
        $siteMode = $body['site_mode'] ?? null;
        if ($siteMode !== null && (!is_string($siteMode) || !in_array($siteMode, self::MODES, true))) {
            throw new CwException('bad_request', 'site_mode must be one of ' . implode(', ', self::MODES), 400, ['field' => 'site_mode']);
        }
        $n = [];
        foreach (['outbox_depth', 'outbox_oldest_age_sec', 'dead_letters'] as $k) {
            $n[$k] = self::count($body[$k] ?? null, $k, 4_294_967_295);
        }
        $n['last_seq'] = self::count($body['last_seq'] ?? null, 'last_seq', PHP_INT_MAX);
        $version = $body['connector_version'] ?? null;
        if ($version !== null && (!is_string($version) || strlen($version) > 32)) {
            throw new CwException('bad_request', 'connector_version must be a string of at most 32 characters', 400, ['field' => 'connector_version']);
        }
        $payload = Idempotency::json(Idempotency::canonical($body));
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new CwException('bad_request', 'a heartbeat must be at most ' . self::MAX_PAYLOAD_BYTES . ' bytes', 413);
        }

        return $this->idem->run(
            $caller, $idemKey, 'channel.heartbeat', '/v1/heartbeat', $body, 'channel', (string) $caller->channelId,
            function (Db $db) use ($caller, $siteMode, $n, $version, $payload): OpResult {
                $mode = (string) $db->value('SELECT mode FROM channel WHERE id = ?', [$caller->channelId]);
                $db->exec(
                    'INSERT INTO channel_health (channel_id, site_mode, outbox_depth, outbox_oldest_age_sec, dead_letters, last_seq, '
                    . 'connector_version, remote_ip, payload) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$caller->channelId, $siteMode, $n['outbox_depth'], $n['outbox_oldest_age_sec'], $n['dead_letters'], $n['last_seq'],
                        $version, $caller->ip, $payload],
                );
                $seq = (int) ($db->value('SELECT MAX(seq) FROM stock_change') ?? 0);
                return OpResult::of(200, ['result' => 'recorded', 'mode' => $mode, 'feed_seq' => $seq,
                    'received_at' => Clock::iso(Clock::db(Clock::now()))]);
            },
        );
    }

    private static function count(mixed $v, string $field, int $max): ?int
    {
        if ($v === null) {
            return null;
        }
        if (!is_int($v) || $v < 0 || $v > $max) {
            throw new CwException('bad_request', "{$field} must be an integer >= 0", 400, ['field' => $field]);
        }
        return $v;
    }
}
