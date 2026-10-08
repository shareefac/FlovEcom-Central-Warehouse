<?php

declare(strict_types=1);

namespace CW\Admin;

use CW\Db;
use CW\Ops\ChannelHealth;

/**
 * The Websites page (G05, docs/decisions.md Y30): each website (channel) as CW sees it now, read only. Its mode in CW and the
 * mode the site last reported, its last heartbeat, the warehouse it sells from, its queue (what the site has not sent yet, what
 * failed for good, and how far behind CW's stock changes it is), when CW's stock figures last reached it, and the stock-writer
 * switch. Changes stay commands on the server with the owner's sign-off (proto first): commands() gives the exact ones, which the
 * page shows only in its folded technical details. Keys are never shown or read.
 */
final class Sites
{
    /** A site in shadow or live should report every minute: older than this is "no contact" (ChannelHealth's threshold). */
    public const STALE_AFTER_SEC = ChannelHealth::STALE_AFTER_SEC;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Every website, by name: id, code, name, mode, site_mode (last reported), mismatch, heartbeat (time, seconds ago, stale),
     * connector, outbox (depth, oldest seconds, dead letters), feed (CW's head, the site's last applied seq, behind, the time of the
     * last change it applied), warehouses (selling, other), writer (switch, effective = switch on and live), key set, allowlist size.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $head = (int) ($this->db->value('SELECT MAX(seq) FROM stock_change') ?? 0);
        $now = (string) $this->db->value('SELECT NOW(6)');
        $wh = [];
        foreach ($this->db->all('SELECT cw.channel_id, cw.is_sellable, w.code, w.name FROM channel_warehouse cw JOIN warehouse w ON w.id = cw.warehouse_id ORDER BY w.sort_order, w.code') as $r) {
            $wh[(int) $r['channel_id']][(int) $r['is_sellable'] === 1 ? 'selling' : 'other'][] = ['code' => (string) $r['code'], 'name' => (string) $r['name']];
        }
        $out = [];
        foreach ($this->db->all(
            'SELECT c.id, c.code, c.name, CAST(c.mode AS CHAR) AS mode, c.site_writer, c.api_key_hash IS NOT NULL AS has_key, JSON_LENGTH(c.allowed_ips) AS ips, '
            . 'h.received_at, CAST(h.site_mode AS CHAR) AS site_mode, h.outbox_depth, h.outbox_oldest_age_sec, h.dead_letters, h.last_seq, h.connector_version, '
            . 'TIMESTAMPDIFF(SECOND, h.received_at, ?) AS age, sc.created_at AS applied_at '
            . 'FROM channel c LEFT JOIN channel_health h ON h.id = (SELECT MAX(h2.id) FROM channel_health h2 WHERE h2.channel_id = c.id) '
            . 'LEFT JOIN stock_change sc ON sc.seq = h.last_seq ORDER BY c.name, c.code',
            [$now],
        ) as $r) {
            $id = (int) $r['id'];
            $mode = (string) $r['mode'];
            $age = $r['age'] === null ? null : max(0, (int) $r['age']);
            $lastSeq = $r['last_seq'] === null ? null : (int) $r['last_seq'];
            $out[] = [
                'id' => $id, 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'mode' => $mode,
                'site_mode' => $r['site_mode'] === null ? null : (string) $r['site_mode'],
                'mismatch' => $r['site_mode'] !== null && (string) $r['site_mode'] !== $mode,
                'heartbeat' => $r['received_at'] === null ? null : (string) $r['received_at'], 'age' => $age,
                'stale' => $mode !== 'off' && ($age === null || $age > self::STALE_AFTER_SEC),
                'connector' => $r['connector_version'] === null ? null : (string) $r['connector_version'],
                'outbox' => $r['received_at'] === null ? null : ['depth' => $r['outbox_depth'] === null ? null : (int) $r['outbox_depth'],
                    'oldest' => $r['outbox_oldest_age_sec'] === null ? null : (int) $r['outbox_oldest_age_sec'],
                    'dead' => $r['dead_letters'] === null ? null : (int) $r['dead_letters']],
                'feed' => ['head' => $head, 'applied' => $lastSeq, 'behind' => $lastSeq === null ? null : max(0, $head - $lastSeq),
                    'applied_at' => $r['applied_at'] === null ? null : (string) $r['applied_at']],
                'warehouses' => ['selling' => $wh[$id]['selling'] ?? [], 'other' => $wh[$id]['other'] ?? []],
                'writer' => (int) $r['site_writer'] === 1, 'writer_live' => (int) $r['site_writer'] === 1 && $mode === 'live',
                'has_key' => (int) $r['has_key'] === 1, 'ips' => (int) ($r['ips'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * The exact server commands that change a website (run on the CW server, in /opt/cw-staging; each is a dry run until --apply,
     * audited): the next mode, the stock writer, the allowlist, the warehouse it sells from, a new key. For the folded technical
     * details of the page only (the screens never tell staff to run a command: writing rule 12; the owner's 8 Oct instruction for
     * this page). Pure.
     *
     * @param array{code: string, mode: string, writer: bool} $site
     * @return list<array{what: string, command: string}>
     */
    public static function commands(array $site): array
    {
        $code = $site['code'];
        $base = 'php bin/channel_set.php --code=' . $code;
        $next = match ($site['mode']) {
            'off' => 'shadow',
            'shadow' => 'live',
            default => 'shadow',
        };
        return [
            ['what' => 'mode', 'command' => "{$base} --mode={$next} --actor=\"<your name>\"   # dry run; add --apply to change it"],
            ['what' => 'writer', 'command' => "{$base} --writer=" . ($site['writer'] ? 'off' : 'on') . ' --actor="<your name>" --apply'],
            ['what' => 'ips', 'command' => "{$base} --ips=<address>[,<address>/24] --apply"],
            ['what' => 'warehouse', 'command' => "{$base} --warehouse=<WAREHOUSE CODE> --actor=\"<your name>\"   # dry run; add --apply to move it"],
            ['what' => 'key', 'command' => 'php bin/rotate_key.php --code=' . $code],
            ['what' => 'health', 'command' => 'php bin/health_alert.php'],
        ];
    }
}
