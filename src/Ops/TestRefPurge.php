<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\Config;
use CW\CwException;
use CW\Db;
use CW\OpResult;
use CW\Reservations;
use DateTimeImmutable;

/**
 * Removes the reservations a test left on a STAGING channel, by order_ref prefix (D47; bin/purge_test_refs.php).
 *
 *  1. Neutralise, through the normal Reservations paths, so the ledger records it: a held reservation is
 *     released (its current attempt), the open units of a committed one are cancelled, restockable (test goods
 *     never left). The calls run as Caller::channelJob(<channel>, 'purge_test_refs'): the channel's scope,
 *     actor system:purge_test_refs, keys purge:release:<ref>:<attempt> / purge:cancel:<ref>:<hash of the ids>,
 *     so a re-run replays them.
 *  2. Delete a reservation and its units only when nothing else refers to them: no stock_ledger row of that
 *     order or of its units (an unlinked unit never has one) and no oversell_event. Otherwise both stay and are
 *     reported (`kept`). One transaction per reservation, locked first (lock order), audited
 *     `reservation.purged`.
 *  3. Delete the idempotency rows of the refs that have no reservation left (deleted by this run or an earlier
 *     one, or never created, e.g. a refused reserve): the channel-scope keys that audit_log names for a
 *     `reservation` entity with the prefix (every reservation call is audited with its key and ref, the purge's
 *     own included). The keys of a KEPT reservation stay (`kept_keys`): a late retry under one of them must
 *     still replay its answer, not run again on the released/cancelled reservation (review fix).
 *  4. With $heartbeatsUntil: ALL of the channel's channel_health rows received at or before it, and ALL its
 *     heartbeat idempotency rows (/v1/heartbeat) created at or before it, whatever the prefix (heartbeats name
 *     no order; the dry run shows their time range, addresses and connector versions).
 *  5. One `purge.test_refs` audit row with the counts.
 * Never deletes stock_ledger, stock_value_ledger or audit_log rows (the app login cannot), nor listings.
 * Runs only where app.env says environment=staging AND the schema is cw_staging or cw_test_* (stagingRefusal),
 * and never on a channel in mode `live` (plan() refuses: `channel_live`).
 */
final class TestRefPurge
{
    public const JOB = 'purge_test_refs';
    /** app.env key that marks a staging server (raw file value; no CW_* environment override). */
    public const ENV_KEY = 'environment';
    public const MIN_PREFIX = 4;
    public const MAX_PREFIX = 32;
    private const CHUNK = 500;

    public function __construct(private readonly Db $db, private readonly Reservations $res)
    {
    }

    /** Why this database must not be purged, or null when app.env says staging and the schema is a staging one. */
    public static function stagingRefusal(Config $config, string $schema): ?string
    {
        $env = $config->appFile(self::ENV_KEY);
        if ($env === null || strtolower(trim($env)) !== 'staging') {
            return "{$config->appEnvPath} does not say " . self::ENV_KEY . '=staging (it says ' . ($env === null ? 'nothing' : "'{$env}'")
                . '): this tool runs on staging only';
        }
        if ($schema !== 'cw_staging' && preg_match('/^cw_test_[a-z0-9_]+$/', $schema) !== 1) {
            return "schema {$schema} is not a staging schema (cw_staging or cw_test_*)";
        }
        return null;
    }

    /** Why $prefix is not acceptable, or null: 4-32 of A-Z a-z 0-9 . : - with at least one letter (a bare number would match real orders). */
    public static function prefixProblem(string $prefix): ?string
    {
        if (strlen($prefix) < self::MIN_PREFIX || strlen($prefix) > self::MAX_PREFIX) {
            return 'the prefix must be ' . self::MIN_PREFIX . '-' . self::MAX_PREFIX . ' characters';
        }
        if (preg_match('/^[A-Za-z0-9.:-]+$/', $prefix) !== 1) {
            return 'the prefix may hold only A-Z a-z 0-9 . : -';
        }
        if (preg_match('/[A-Za-z]/', $prefix) !== 1) {
            return 'the prefix needs a letter: a bare number would match real order ids';
        }
        return null;
    }

    /**
     * What a purge would do (nothing is written).
     *
     * @return array<string, mixed>
     */
    public function plan(string $channelCode, string $prefix, ?DateTimeImmutable $heartbeatsUntil = null): array
    {
        $problem = self::prefixProblem($prefix);
        if ($problem !== null) {
            throw new CwException('bad_prefix', $problem, 400);
        }
        $ch = $this->db->one('SELECT id, code, mode FROM channel WHERE code = ?', [$channelCode]);
        if ($ch === null) {
            throw new CwException('unknown_channel', "no channel {$channelCode}", 404);
        }
        if ($ch['mode'] === 'live') {
            // The staging marker cannot tell a staging schema that carries live data apart (review fix): a live
            // channel's orders are real, whatever the prefix. Lower it first (bin/channel_set.php) if it is a test.
            throw new CwException('channel_live', "channel {$channelCode} is live: its orders are real; this tool purges test channels only "
                . '(set it to shadow or off with bin/channel_set.php first if it really is a test channel)', 409);
        }
        $chId = (int) $ch['id'];
        $like = $prefix . '%'; // the prefix holds no LIKE wildcard (prefixProblem)

        $res = $this->db->all('SELECT id, order_ref, status, attempt, origin, is_tombstone FROM reservation WHERE channel_id = ? AND order_ref LIKE ? ORDER BY order_ref',
            [$chId, $like]);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $res);
        $units = [];
        $oversell = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($this->db->all("SELECT reservation_id, unit_id, state, sku_id FROM reservation_unit WHERE reservation_id IN ({$in}) ORDER BY unit_id", $chunk) as $u) {
                $units[(int) $u['reservation_id']][] = $u;
            }
            foreach ($this->db->all("SELECT reservation_id, COUNT(*) n FROM oversell_event WHERE reservation_id IN ({$in}) GROUP BY reservation_id", $chunk) as $o) {
                $oversell[(int) $o['reservation_id']] = (int) $o['n'];
            }
        }
        $ledgerByRef = [];
        foreach ($this->db->all('SELECT order_ref, COUNT(*) n FROM stock_ledger WHERE channel_id = ? AND order_ref LIKE ? GROUP BY order_ref', [$chId, $like]) as $l) {
            $ledgerByRef[strtolower((string) $l['order_ref'])] = (int) $l['n'];
        }

        $out = [];
        $totals = ['reservations' => 0, 'to_release' => 0, 'to_cancel_units' => 0, 'to_delete_reservations' => 0, 'to_delete_units' => 0,
            'kept_reservations' => 0];
        foreach ($res as $r) {
            $id = (int) $r['id'];
            $us = $units[$id] ?? [];
            $byState = [];
            $linked = 0;
            $open = [];
            foreach ($us as $u) {
                $byState[(string) $u['state']] = ($byState[(string) $u['state']] ?? 0) + 1;
                if ($u['sku_id'] !== null) {
                    $linked++;
                }
                if (in_array($u['state'], ['held', 'allocated'], true)) {
                    $open[] = (string) $u['unit_id'];
                }
            }
            ksort($byState);
            $ledger = ($ledgerByRef[strtolower((string) $r['order_ref'])] ?? 0) + $this->unitLedgerRows($chId, $us, (string) $r['order_ref']);
            $neutralise = match (true) {
                $r['status'] === 'held' => 'release',
                $r['status'] === 'committed' && $open !== [] => 'cancel',
                default => null,
            };
            $keep = [];
            if ($ledger > 0 || $linked > 0) {
                $keep[] = 'ledger';
            }
            if (($oversell[$id] ?? 0) > 0) {
                $keep[] = 'oversell_event';
            }
            $out[] = ['order_ref' => (string) $r['order_ref'], 'id' => $id, 'status' => (string) $r['status'], 'attempt' => (int) $r['attempt'],
                'origin' => (string) $r['origin'], 'tombstone' => (int) $r['is_tombstone'] === 1, 'units' => count($us), 'linked_units' => $linked,
                'by_state' => $byState, 'open_units' => $open, 'ledger_rows' => $ledger, 'oversell_events' => $oversell[$id] ?? 0,
                'neutralise' => $neutralise, 'delete' => $keep === [], 'keep_why' => $keep];
            $totals['reservations']++;
            $totals['to_release'] += $neutralise === 'release' ? 1 : 0;
            $totals['to_cancel_units'] += $neutralise === 'cancel' ? count($open) : 0;
            if ($keep === []) {
                $totals['to_delete_reservations']++;
                $totals['to_delete_units'] += count($us);
            } else {
                $totals['kept_reservations']++;
            }
        }
        // Keys of refs that keep a reservation stay; the rest go (see the class comment, step 3).
        $staying = [];
        foreach ($out as $x) {
            if (!$x['delete']) {
                $staying[strtolower($x['order_ref'])] = true;
            }
        }
        [$gone, $kept] = $this->splitKeys($this->refKeys($chId, $like), $staying);
        $totals['idempotency_rows'] = $this->countKeys($chId, $gone);
        $totals['idempotency_rows_kept'] = $this->countKeys($chId, $kept);

        $hb = null;
        if ($heartbeatsUntil !== null) {
            $until = Clock::db($heartbeatsUntil);
            $h = $this->db->one('SELECT COUNT(*) n, MIN(received_at) first, MAX(received_at) last FROM channel_health WHERE channel_id = ? AND received_at <= ?',
                [$chId, $until]);
            $hb = ['until' => Clock::iso($until), 'rows' => (int) $h['n'], 'first' => Clock::iso($h['first'] === null ? null : (string) $h['first']),
                'last' => Clock::iso($h['last'] === null ? null : (string) $h['last']),
                'remote_ips' => array_map('strval', $this->db->column('SELECT DISTINCT IFNULL(remote_ip, \'-\') FROM channel_health WHERE channel_id = ? AND received_at <= ? ORDER BY 1', [$chId, $until])),
                'connector_versions' => array_map('strval', $this->db->column('SELECT DISTINCT IFNULL(connector_version, \'-\') FROM channel_health WHERE channel_id = ? AND received_at <= ? ORDER BY 1', [$chId, $until])),
                'idempotency_rows' => (int) $this->db->value("SELECT COUNT(*) FROM idempotency WHERE scope_channel_id = ? AND source = '' AND path = '/v1/heartbeat' AND created_at <= ?",
                    [$chId, $until])];
        }
        return ['channel' => (string) $ch['code'], 'channel_id' => $chId, 'mode' => (string) $ch['mode'], 'prefix' => $prefix,
            'reservations' => $out, 'totals' => $totals, 'heartbeats' => $hb];
    }

    /**
     * Neutralises, deletes and audits (see the class comment). A reservation whose neutralising call fails is
     * skipped and listed under `failed`; the rest go on.
     *
     * @return array<string, mixed> the plan it ran, plus `done`
     */
    public function apply(string $channelCode, string $prefix, ?DateTimeImmutable $heartbeatsUntil, string $by): array
    {
        $plan = $this->plan($channelCode, $prefix, $heartbeatsUntil);
        $chId = $plan['channel_id'];
        $caller = Caller::channelJob($chId, $plan['channel'], self::JOB);
        $done = ['released' => 0, 'cancelled_units' => 0, 'deleted_reservations' => 0, 'deleted_units' => 0, 'idempotency_rows' => 0,
            'idempotency_rows_kept' => 0, 'channel_health_rows' => 0, 'heartbeat_idempotency_rows' => 0, 'kept' => [], 'failed' => []];

        foreach ($plan['reservations'] as $r) {
            try {
                if ($r['neutralise'] === 'release') {
                    $this->must($this->res->release($caller, $r['order_ref'], $r['attempt'], "purge:release:{$r['order_ref']}:{$r['attempt']}"));
                    $done['released']++;
                } elseif ($r['neutralise'] === 'cancel') {
                    foreach (array_chunk($r['open_units'], Reservations::MAX_ORDER_UNITS) as $ids) {
                        $key = "purge:cancel:{$r['order_ref']}:" . substr(sha1(implode("\n", $ids)), 0, 16);
                        $this->must($this->res->cancel($caller, $r['order_ref'], $ids, true, $key));
                        $done['cancelled_units'] += count($ids);
                    }
                }
            } catch (\Throwable $e) {
                $done['failed'][] = ['order_ref' => $r['order_ref'], 'step' => (string) $r['neutralise'], 'error' => $e instanceof CwException ? $e->errorCode : $e::class,
                    'message' => mb_strcut($e->getMessage(), 0, 300, 'UTF-8')];
                continue;
            }
            if (!$r['delete']) {
                $done['kept'][] = ['order_ref' => $r['order_ref'], 'why' => $r['keep_why']];
                continue;
            }
            $gone = $this->db->transaction(function (Db $db) use ($r, $chId, $caller, $by, $prefix): array {
                $row = $db->one('SELECT id, status, origin, is_tombstone FROM reservation WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$chId, $r['order_ref']]);
                if ($row === null) {
                    return ['units' => null, 'why' => []]; // gone meanwhile
                }
                $us = $db->all('SELECT unit_id, state, sku_id FROM reservation_unit WHERE reservation_id = ? ORDER BY unit_id', [(int) $row['id']]);
                // Re-checked under the reservation's lock: every unit change locks its reservation first.
                $why = [];
                foreach ($us as $u) {
                    if (in_array($u['state'], ['held', 'allocated'], true)) {
                        $why['open_units'] = true;
                    }
                    if ($u['sku_id'] !== null) {
                        $why['ledger'] = true;
                    }
                }
                if ((int) $db->value('SELECT COUNT(*) FROM stock_ledger WHERE channel_id = ? AND order_ref = ?', [$chId, $r['order_ref']])
                    + $this->unitLedgerRows($chId, $us, $r['order_ref']) > 0) {
                    $why['ledger'] = true;
                }
                if ((int) $db->value('SELECT COUNT(*) FROM oversell_event WHERE reservation_id = ?', [(int) $row['id']]) > 0) {
                    $why['oversell_event'] = true;
                }
                if ($why !== []) {
                    return ['units' => null, 'why' => array_keys($why)];
                }
                $n = $db->exec('DELETE FROM reservation_unit WHERE reservation_id = ?', [(int) $row['id']]);
                $db->exec('DELETE FROM reservation WHERE id = ?', [(int) $row['id']]);
                Audit::write($db, $caller, 'reservation.purged', 'reservation', $r['order_ref'], null, ['prefix' => $prefix, 'by' => $by,
                    'status' => (string) $row['status'], 'origin' => (string) $row['origin'], 'tombstone' => (int) $row['is_tombstone'] === 1,
                    'units' => array_map(static fn (array $u): string => $u['unit_id'] . ':' . $u['state'], $us)]);
                return ['units' => $n, 'why' => []];
            });
            if ($gone['why'] !== []) {
                $done['kept'][] = ['order_ref' => $r['order_ref'], 'why' => $gone['why']];
            } elseif ($gone['units'] !== null) {
                $done['deleted_reservations']++;
                $done['deleted_units'] += $gone['units'];
            }
        }

        // Idempotency rows of the refs left without a reservation (the purge's own calls included), heartbeats, and
        // the summary audit row. Which refs still have one is read inside the transaction, after the deletes.
        $until = $heartbeatsUntil === null ? null : Clock::db($heartbeatsUntil);
        $code = $plan['channel'];
        $this->db->transaction(function (Db $db) use ($chId, $code, $until, $caller, $by, $prefix, &$done): void {
            $staying = [];
            foreach ($db->column('SELECT order_ref FROM reservation WHERE channel_id = ? AND order_ref LIKE ?', [$chId, $prefix . '%']) as $ref) {
                $staying[strtolower((string) $ref)] = true;
            }
            [$keys, $kept] = $this->splitKeys($this->refKeys($chId, $prefix . '%'), $staying);
            $done['idempotency_rows'] = 0;
            $done['idempotency_rows_kept'] = $this->countKeys($chId, $kept);
            foreach (array_chunk($keys, self::CHUNK) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $done['idempotency_rows'] += $db->exec("DELETE FROM idempotency WHERE scope_channel_id = ? AND source = '' AND idem_key IN ({$in})", [$chId, ...$chunk]);
            }
            if ($until !== null) {
                $done['channel_health_rows'] = $db->exec('DELETE FROM channel_health WHERE channel_id = ? AND received_at <= ?', [$chId, $until]);
                $done['heartbeat_idempotency_rows'] = $db->exec(
                    "DELETE FROM idempotency WHERE scope_channel_id = ? AND source = '' AND path = '/v1/heartbeat' AND created_at <= ?", [$chId, $until]);
            }
            Audit::write($db, $caller, 'purge.test_refs', 'channel', $code, null, ['prefix' => $prefix, 'by' => $by,
                'heartbeats_until' => Clock::iso($until)] + array_diff_key($done, ['kept' => 0, 'failed' => 0])
                + ['kept' => array_slice($done['kept'], 0, 50), 'failed' => array_slice($done['failed'], 0, 50)]);
        });
        return $plan + ['done' => $done];
    }

    private function must(OpResult $r): void
    {
        if (!$r->ok()) {
            throw new CwException((string) ($r->body['error'] ?? 'refused'), (string) ($r->body['message'] ?? json_encode($r->body)), $r->status);
        }
    }

    /**
     * Ledger rows of these units filed under another order_ref than their own (none today: every unit row carries its
     * order; checked anyway, a unit row must never be orphaned).
     *
     * @param list<array<string, mixed>> $units
     */
    private function unitLedgerRows(int $chId, array $units, string $orderRef): int
    {
        $ids = array_map(static fn (array $u): string => (string) $u['unit_id'], $units);
        $n = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $n += (int) $this->db->value("SELECT COUNT(*) FROM stock_ledger WHERE channel_id = ? AND unit_id IN ({$in}) AND (order_ref IS NULL OR order_ref <> ?)",
                [$chId, ...$chunk, $orderRef]);
        }
        return $n;
    }

    /** @return list<array{ref: string, key: string}> the channel-scope keys audit_log names for reservations with the prefix, with their ref */
    private function refKeys(int $chId, string $like): array
    {
        return array_map(static fn (array $r): array => ['ref' => (string) $r['entity_id'], 'key' => (string) $r['idem_key']], $this->db->all(
            "SELECT DISTINCT entity_id, idem_key FROM audit_log WHERE entity_type = 'reservation' AND entity_id LIKE ? AND channel_id = ? AND idem_key IS NOT NULL",
            [$like, $chId],
        ));
    }

    /**
     * [keys to delete, keys to keep]: a key named for a ref that keeps its reservation is kept (even if another ref
     * names it too).
     *
     * @param list<array{ref: string, key: string}> $refKeys
     * @param array<string, true> $staying lower-cased refs that still have a reservation
     * @return array{0: list<string>, 1: list<string>}
     */
    private function splitKeys(array $refKeys, array $staying): array
    {
        $keep = [];
        $all = [];
        foreach ($refKeys as $rk) {
            $all[$rk['key']] = true;
            if (isset($staying[strtolower($rk['ref'])])) {
                $keep[$rk['key']] = true;
            }
        }
        return [array_map('strval', array_keys(array_diff_key($all, $keep))), array_map('strval', array_keys($keep))];
    }

    /** @param list<string> $keys */
    private function countKeys(int $chId, array $keys): int
    {
        $n = 0;
        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $n += (int) $this->db->value("SELECT COUNT(*) FROM idempotency WHERE scope_channel_id = ? AND source = '' AND idem_key IN ({$in})", [$chId, ...$chunk]);
        }
        return $n;
    }
}
