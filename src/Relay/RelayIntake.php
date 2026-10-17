<?php

declare(strict_types=1);

namespace CW\Relay;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Movements;
use CW\OpResult;
use CW\Receiving\SellingModes;
use CW\Settings;
use CW\SiteWriter\SiteModes;

/**
 * The relay's two queue routes on CW's side (plan §9; go-live plan part 6 "The relay"; docs/decisions.md PF2, PF3). The three stock
 * kinds stay on POST /v1/movements (Movements::record, booked once per document + line since PF1).
 *
 *   POST /v1/relay/reconciliations  ERPNext's "set to this number" corrections (stock_reconcillation.php, about 131 a month). Each
 *       line goes to the count-review queue (count_review, source erp_reconciliation): a person confirms it or dismisses it on
 *       Approvals › From the stores; nothing is booked here ("ERPNext's pushes never become counts automatically"). A line naming a
 *       variant or ERPNext item CW cannot place goes to goods-in suspense (movement type erp_reconciliation), where a person picks the
 *       item, which then opens the correction.
 *       {doc_ref, posted_at?, warehouse?, lines: [{line_index?, variant_id | erp_item_code (+ variant_id as its fallback), qty (the
 *       figure ERPNext set, in the variant's units, 0 or more), description?}]}
 *   POST /v1/relay/modes  ERPNext's selling-mode changes (update_product.php, about 4,000 a month). A change that changes nothing is
 *       recorded and not shown to a person (the go-live plan's queue filter, D14): the store says the mode and low-stock level were
 *       already so (previous_mode, previous_low_stock), or CW already has that mode for the item on this store (a legacy item), or
 *       the stock rule it proposes is the item's already (a protected item). The others go to the policy-review queue (policy_review,
 *       source erp_update_product); a newer change of the same variant replaces an older one still open (dismissed, `superseded`).
 *       A variant CW does not know, or that is not matched, is recorded as skipped: there is no item to apply it to.
 *       {doc_ref (the store's message id), changed_at?, lines: [{line_index?, variant_id | erp_item_code, mode (In-Stock,
 *       From-Warehouse, Out-Of-Stock), low_stock?, previous_mode?, previous_low_stock?}]}
 *
 * Who may send them (R17, PF4): a store whose channel is granted `erp_reconciliation` / `erp_mode` in channel.movement_types (none by
 * default; bin/channel_set.php --relay); 403 relay_not_allowed otherwise, not stored. Both are refused while the store is off (the
 * kernel's 409 channel_off: the store keeps its messages and sends them later). Idempotent per Idempotency-Key AND per document + line
 * (relay_line): the same line sent again under another key answers `already_recorded` (or `conflict` with other content) and adds
 * no second queue row.
 */
final class RelayIntake
{
    public const KIND_RECONCILIATION = 'erp_reconciliation';
    public const KIND_MODE = 'erp_mode';
    public const SOURCE_RECONCILIATION = 'erp_reconciliation';
    public const SOURCE_MODE = 'erp_update_product';
    public const MAX_LINES = 2000;
    /** How old a relayed time may be (a store that was off for a while sends its messages later; Movements' backdating limit). */
    public const MAX_AGE_SEC = Movements::BACKDATED_MAX_AGE_SEC;

    private readonly Idempotency $idem;

    public function __construct(private readonly Db $db)
    {
        $this->idem = new Idempotency($db);
    }

    /** @param array<string, mixed> $req */
    public function reconciliations(Caller $caller, array $req, string $idemKey): OpResult
    {
        $this->checkGranted($caller, self::KIND_RECONCILIATION);
        $docRef = self::docRef($req['doc_ref'] ?? null);
        $postedAt = isset($req['posted_at']) ? Clock::parse($req['posted_at'], 'posted_at') : null;
        $whCode = self::optString($req['warehouse'] ?? null, 'warehouse', 32);
        $lines = self::lines($req['lines'] ?? null, function (array $line, int $i): array {
            $qty = $line['qty'] ?? null;
            if (!is_int($qty) || $qty < 0 || $qty > 10_000_000) {
                throw new CwException('bad_lines', "lines[{$i}].qty (the figure ERPNext set) must be a whole number from 0 to 10000000", 400);
            }
            return ['qty' => $qty];
        });
        $request = ['doc_ref' => $docRef, 'posted_at' => $postedAt === null ? null : Clock::db($postedAt), 'warehouse' => $whCode, 'lines' => $lines];
        return $this->idem->run($caller, $idemKey, 'relay.reconciliation', '/v1/relay/reconciliations', $request, 'relay', $docRef,
            function (Db $db) use ($caller, $docRef, $postedAt, $whCode, $lines, $idemKey): OpResult {
                $now = Clock::now();
                $at = $postedAt ?? $now;
                Clock::checkWindow($at, 'posted_at', $now, self::MAX_AGE_SEC);
                $wh = $this->warehouse($db, $caller, $whCode);
                $relay = new RelayLines($db);
                $out = [];
                foreach ($lines as $line) {
                    $idx = (int) $line['line_index'];
                    $seen = $relay->claim($caller, self::KIND_RECONCILIATION, $docRef, $line, $idemKey);
                    if ($seen !== null) {
                        $out[] = $seen;
                        continue;
                    }
                    $r = $this->resolve($db, $caller, $line);
                    if (isset($r['reason'])) {
                        $id = $this->suspend($db, $caller, $docRef, $line, $r['reason'], ['posted_at' => Clock::db($at), 'warehouse_id' => $wh]);
                        $relay->settle($caller, self::KIND_RECONCILIATION, $docRef, $idx, 'suspense', null, null, $id, $r['reason']);
                        $out[] = ['line_index' => $idx, 'result' => 'suspense', 'reason' => $r['reason']];
                        continue;
                    }
                    $units = $line['qty'] * $r['u'];
                    $id = self::openCorrection($db, $caller, $wh, $r['sku_id'], $units, $docRef, $idx, [
                        'qty' => $line['qty'], 'u' => $r['u'], 'variant_id' => $line['variant_id'] ?? null, 'erp_item_code' => $line['erp_item_code'] ?? null,
                        'description' => $line['description'] ?? null, 'posted_at' => Clock::db($at),
                    ]);
                    $relay->settle($caller, self::KIND_RECONCILIATION, $docRef, $idx, 'queued', $r['sku_id'], $units, $id);
                    $out[] = ['line_index' => $idx, 'result' => 'queued', 'sku_code' => $r['code'], 'units' => $units];
                }
                return OpResult::of(200, ['result' => 'recorded', 'doc_ref' => $docRef, 'lines' => $out]);
            });
    }

    /** @param array<string, mixed> $req */
    public function modes(Caller $caller, array $req, string $idemKey): OpResult
    {
        $this->checkGranted($caller, self::KIND_MODE);
        $docRef = self::docRef($req['doc_ref'] ?? null);
        $changedAt = isset($req['changed_at']) ? Clock::parse($req['changed_at'], 'changed_at') : null;
        $lines = self::lines($req['lines'] ?? null, static function (array $line, int $i): array {
            $n = [];
            foreach (['mode' => true, 'previous_mode' => false] as $k => $required) {
                $v = $line[$k] ?? null;
                if ($v === null && !$required) {
                    continue;
                }
                if (!is_string($v) || !in_array($v, SellingModes::MODES, true)) {
                    throw new CwException('bad_lines', "lines[{$i}].{$k} must be one of " . implode(', ', SellingModes::MODES), 400);
                }
                $n[$k] = $v;
            }
            foreach (['low_stock', 'previous_low_stock'] as $k) {
                $v = $line[$k] ?? null;
                if ($v === null) {
                    continue;
                }
                if (!is_int($v) || $v < 0 || $v > SiteModes::THRESHOLD_MAX) {
                    throw new CwException('bad_lines', "lines[{$i}].{$k} must be a whole number from 0 to " . SiteModes::THRESHOLD_MAX, 400);
                }
                $n[$k] = $v;
            }
            return $n;
        });
        $request = ['doc_ref' => $docRef, 'changed_at' => $changedAt === null ? null : Clock::db($changedAt), 'lines' => $lines];
        return $this->idem->run($caller, $idemKey, 'relay.mode', '/v1/relay/modes', $request, 'relay', $docRef,
            function (Db $db) use ($caller, $docRef, $changedAt, $lines, $idemKey): OpResult {
                // A channel always exists, even before its first request. Take this guard before line claims and history locks.
                $db->one('SELECT id FROM channel WHERE id = ? FOR UPDATE', [$caller->channelId]);
                $now = Clock::now();
                $at = $changedAt ?? $now;
                Clock::checkWindow($at, 'changed_at', $now, self::MAX_AGE_SEC);
                $settings = new Settings($db);
                $relay = new RelayLines($db);
                $out = [];
                foreach ($lines as $line) {
                    $idx = (int) $line['line_index'];
                    $seen = $relay->claim($caller, self::KIND_MODE, $docRef, $line, $idemKey);
                    if ($seen !== null) {
                        $out[] = $seen;
                        continue;
                    }
                    $result = $this->modeLine($db, $settings, $caller, $docRef, $line, $at);
                    $relay->settle($caller, self::KIND_MODE, $docRef, $idx, $result['outcome'], $result['sku_id'] ?? null, null, $result['queue_id'] ?? null,
                        $result['reason'] ?? null);
                    $out[] = ['line_index' => $idx, 'result' => $result['outcome']] + (isset($result['reason']) ? ['reason' => $result['reason']] : [])
                        + (isset($result['proposed']) ? ['proposed' => $result['proposed']] : []);
                }
                return OpResult::of(200, ['result' => 'recorded', 'doc_ref' => $docRef, 'lines' => $out]);
            });
    }

    /**
     * Opens one correction in the count-review queue (or finds the one this line opened: its dedupe key). Used by the intake and by
     * goods-in suspense, when a person picks the item of a relayed correction (Queues\Suspense). No stock lock: count_review's foreign
     * key to sku takes the item's row, so nothing here is called after a stock lock (0029's note).
     *
     * @param array<string, mixed> $detail
     */
    public static function openCorrection(Db $db, Caller $caller, int $warehouseId, int $skuId, int $units, string $docRef, int $lineIndex, array $detail): int
    {
        $channel = (string) ($caller->channelId ?? (int) ($detail['channel_id'] ?? 0));
        $key = Movements::queueKey(self::SOURCE_RECONCILIATION . ":{$channel}:{$docRef}#{$lineIndex}", self::SOURCE_RECONCILIATION . ":{$channel}:#",
            [self::SOURCE_RECONCILIATION, $channel, $docRef, (string) $lineIndex]);
        $db->exec(
            'INSERT INTO count_review (warehouse_id, sku_id, source, proposed_qty, ref, detail, dedupe_key) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [$warehouseId, $skuId, self::SOURCE_RECONCILIATION, $units, mb_strcut($docRef, 0, 191, 'UTF-8'),
                Idempotency::json(['channel_id' => $caller->channelId ?? ($detail['channel_id'] ?? null), 'line_index' => $lineIndex] + $detail), $key],
        );
        return (int) $db->value('SELECT id FROM count_review WHERE dedupe_key = ?', [$key]);
    }

    /**
     * One mode line: what it changes, and its queue row when it changes something.
     *
     * @param array<string, mixed> $line
     * @return array{outcome: string, reason?: string, sku_id?: int, queue_id?: int, proposed?: string}
     */
    private function modeLine(Db $db, Settings $settings, Caller $caller, string $docRef, array $line, \DateTimeImmutable $at): array
    {
        $skuId = null;
        $listing = isset($line['variant_id'])
            ? $db->one('SELECT id, sku_id, status FROM channel_listing WHERE channel_id = ? AND external_variant_id = ?', [$caller->channelId, $line['variant_id']])
            : null;
        if (isset($line['erp_item_code'])) {
            $sku = $db->value('SELECT sku_id FROM sku_erp_item WHERE item_code = ?', [$line['erp_item_code']]);
            $skuId = $sku === null ? null : (int) $sku;
            if ($skuId === null && !isset($line['variant_id'])) {
                return ['outcome' => 'skipped', 'reason' => 'unknown_erp_item'];
            }
        }
        if ($skuId === null) {
            if ($listing === null) {
                return ['outcome' => 'skipped', 'reason' => 'unknown_listing'];
            }
            if (!in_array((string) $listing['status'], ['mapped', 'quarantined'], true) || $listing['sku_id'] === null) {
                return ['outcome' => 'skipped', 'reason' => 'unlinked_listing'];
            }
            $skuId = (int) $listing['sku_id'];
        }
        $mode = (string) $line['mode'];
        $low = $line['low_stock'] ?? null;
        $listingId = $listing === null ? null : (int) $listing['id'];
        $when = Clock::db($at);
        // The change time decides, not the arrival order (review finding 4 of 14 Oct 2026): this store's earlier requests for the same
        // variant (or, named by its ERPNext item alone, the same item on this store: finding 5), with when each was made.
        $earlier = $this->requestsFor($db, (int) $caller->channelId, $skuId, $listingId);
        $subject = $listingId === null ? 'sku' : 'listing';
        $subjectId = $listingId ?? $skuId;
        $known = $db->value('SELECT changed_at FROM relay_mode_seen WHERE channel_id = ? AND subject_kind = ? AND subject_id = ?',
            [$caller->channelId, $subject, $subjectId]);
        if ($known !== null && (string) $known > $when) {
            return ['outcome' => 'no_change', 'reason' => 'older_than_known', 'sku_id' => $skuId];
        }
        foreach ($earlier as $r) {
            if ($r['changed_at'] > $when) {
                // A newer change of this variant is known already (waiting, answered or replaced): this one is out of date.
                return ['outcome' => 'no_change', 'reason' => 'older_than_known', 'sku_id' => $skuId];
            }
        }
        $item = $db->one('SELECT sell_policy FROM sku WHERE id = ?', [$skuId]) ?? throw new \RuntimeException("item {$skuId} vanished");
        $policy = (string) $item['sell_policy'];
        $proposed = self::proposedPolicy($settings, $mode);
        $cw = $db->one('SELECT mode, low_stock_threshold FROM item_channel_mode WHERE sku_id = ? AND channel_id = ?', [$skuId, $caller->channelId]);
        $cwMode = $cw === null ? ((new SellingModes($db))->current([$skuId])[$skuId]['mode'] ?? null) : (string) $cw['mode'];
        $cwLow = $cw === null || $cw['low_stock_threshold'] === null ? null : (int) $cw['low_stock_threshold'];
        $same = match (true) {
            // The store says nothing changed (the mode and, when sent, the low-stock level were already so).
            isset($line['previous_mode']) && $line['previous_mode'] === $mode && ($low === null || ($line['previous_low_stock'] ?? null) === $low) => 'same_on_store',
            $policy !== 'legacy' && $proposed === $policy => 'same_rule',
            $policy === 'legacy' && $cwMode === $mode && ($low === null || $cwLow === $low) => 'same_in_cw',
            default => null,
        };
        // Keep the latest event time even when the message needs no review. The channel guard serialises this watermark.
        $db->exec('INSERT INTO relay_mode_seen (channel_id, subject_kind, subject_id, changed_at) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE changed_at = GREATEST(changed_at, ?)', [$caller->channelId, $subject, $subjectId, $when, $when]);
        if ($same !== null) {
            // Nothing to ask a person, but the store's latest state is this one: an older request still open is out of date (finding 3).
            $this->supersede($db, $earlier, null);
            return ['outcome' => 'no_change', 'reason' => $same, 'sku_id' => $skuId];
        }
        $key = Movements::queueKey(self::KIND_MODE . ':' . $caller->channelId . ":{$docRef}#{$line['line_index']}", self::KIND_MODE . ':' . $caller->channelId . ':#',
            [self::KIND_MODE, (string) $caller->channelId, $docRef, (string) $line['line_index']]);
        $db->exec(
            'INSERT INTO policy_review (sku_id, listing_id, source, site_mode, current_policy, proposed_policy, detail, dedupe_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE id = id',
            [$skuId, $listingId, self::SOURCE_MODE, $mode, $policy, $proposed, Idempotency::json([
                'channel_id' => $caller->channelId, 'doc_ref' => $docRef, 'line_index' => (int) $line['line_index'], 'variant_id' => $line['variant_id'] ?? null,
                'erp_item_code' => $line['erp_item_code'] ?? null, 'low_stock' => $low, 'previous_mode' => $line['previous_mode'] ?? null,
                'previous_low_stock' => $line['previous_low_stock'] ?? null, 'cw_mode' => $cwMode, 'cw_low_stock' => $cwLow, 'changed_at' => $when,
            ]), $key],
        );
        $id = (int) $db->value('SELECT id FROM policy_review WHERE dedupe_key = ?', [$key]);
        // This change replaces the older ones of the same variant on this store that still wait: settled, never removed.
        $this->supersede($db, $earlier, $id);
        return ['outcome' => 'queued', 'sku_id' => $skuId, 'queue_id' => $id, 'proposed' => $proposed];
    }

    /**
     * This store's mode requests for one variant (by its listing), or for one item named by its ERPNext item alone (no listing): id,
     * status, and when the change was made (the relayed changed_at, else when CW received it). The caller holds the channel guard
     * before reading this history, including when no request exists yet; FOR UPDATE also serialises it with staff answers.
     *
     * @return list<array{id: int, status: string, changed_at: string}>
     */
    private function requestsFor(Db $db, int $channelId, int $skuId, ?int $listingId): array
    {
        $rows = $listingId !== null
            ? $db->all('SELECT id, CAST(status AS CHAR) AS status, CAST(detail AS CHAR) AS detail, created_at FROM policy_review WHERE listing_id = ? AND source = ? '
                . 'ORDER BY id FOR UPDATE', [$listingId, self::SOURCE_MODE])
            : $db->all('SELECT id, CAST(status AS CHAR) AS status, CAST(detail AS CHAR) AS detail, created_at FROM policy_review WHERE sku_id = ? AND listing_id IS NULL '
                . 'AND source = ? ORDER BY id FOR UPDATE', [$skuId, self::SOURCE_MODE]);
        $out = [];
        foreach ($rows as $r) {
            $d = json_decode((string) $r['detail'], true);
            $d = is_array($d) ? $d : [];
            if ($listingId === null && (int) ($d['channel_id'] ?? 0) !== $channelId) {
                continue; // another store's request for the same item: its own (finding 5)
            }
            $out[] = ['id' => (int) $r['id'], 'status' => (string) $r['status'],
                'changed_at' => is_string($d['changed_at'] ?? null) ? (string) $d['changed_at'] : (string) $r['created_at']];
        }
        return $out;
    }

    /** @param list<array{id: int, status: string, changed_at: string}> $earlier settles the open ones (not $keep) as superseded */
    private function supersede(Db $db, array $earlier, ?int $keep): void
    {
        $ids = array_values(array_map(static fn (array $r): int => $r['id'], array_filter($earlier, static fn (array $r): bool => $r['status'] === 'open' && $r['id'] !== $keep)));
        if ($ids === []) {
            return;
        }
        $db->exec("UPDATE policy_review SET status = 'dismissed', resolution = 'superseded', resolved_at = ? WHERE status = 'open' AND id IN ("
            . implode(', ', array_fill(0, count($ids), '?')) . ')', [Clock::db(Clock::now()), ...$ids]);
    }

    /** The stock rule an ERPNext mode proposes for a protected item (the settings relay.mode_*, plan §9). */
    public static function proposedPolicy(Settings $settings, string $mode): string
    {
        $key = match ($mode) {
            'In-Stock' => 'relay.mode_in_stock',
            'From-Warehouse' => 'relay.mode_from_warehouse',
            default => 'relay.mode_out_of_stock',
        };
        $v = $settings->has($key) ? (string) $settings->get($key) : '';
        return in_array($v, ['strict', 'backorder', 'stopped'], true) ? $v : match ($mode) {
            'In-Stock' => 'backorder',
            'From-Warehouse' => 'strict',
            default => 'stopped',
        };
    }

    /** R17 / PF4: a store sends a queue kind only when its channel is granted it. */
    private function checkGranted(Caller $caller, string $kind): void
    {
        if (!$caller->isChannel()) {
            throw new CwException('channel_required', 'the relay comes from a store', 403);
        }
        $granted = $this->db->value('SELECT movement_types FROM channel WHERE id = ?', [$caller->channelId]);
        $granted = $granted === null ? [] : json_decode((string) $granted, true);
        if (!is_array($granted) || !in_array($kind, $granted, true)) {
            throw new CwException('relay_not_allowed', "this site may not send {$kind} messages (not granted to its channel)", 403, ['kind' => $kind]);
        }
    }

    /**
     * @param array<string, mixed> $line
     * @return array{sku_id: int, code: string, u: int}|array{reason: string}
     */
    private function resolve(Db $db, Caller $caller, array $line): array
    {
        if (isset($line['erp_item_code'])) {
            $r = $db->one('SELECT e.sku_id, e.units_per_item, s.code FROM sku_erp_item e JOIN sku s ON s.id = e.sku_id WHERE e.item_code = ?', [$line['erp_item_code']]);
            if ($r !== null) {
                return ['sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'u' => (int) $r['units_per_item']];
            }
            if (!isset($line['variant_id'])) {
                return ['reason' => 'unknown_erp_item'];
            }
        }
        $r = $db->one('SELECT cl.sku_id, cl.units_per_item, cl.status, s.code FROM channel_listing cl LEFT JOIN sku s ON s.id = cl.sku_id '
            . 'WHERE cl.channel_id = ? AND cl.external_variant_id = ?', [$caller->channelId, $line['variant_id']]);
        if ($r === null) {
            return ['reason' => 'unknown_listing'];
        }
        if (!in_array((string) $r['status'], ['mapped', 'quarantined'], true) || $r['sku_id'] === null) {
            return ['reason' => 'unlinked_listing'];
        }
        return ['sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'u' => (int) $r['units_per_item']];
    }

    /**
     * Parks a correction line CW cannot place in goods-in suspense (Movements' key form: the document + the line).
     *
     * @param array<string, mixed> $line
     * @param array<string, mixed> $extra
     */
    private function suspend(Db $db, Caller $caller, string $docRef, array $line, string $reason, array $extra): int
    {
        $key = Movements::suspenseKey(self::KIND_RECONCILIATION, $docRef, (int) $line['line_index']);
        $db->exec(
            'INSERT INTO goods_in_suspense (source, channel_id, doc_type, doc_ref, line_index, movement_type, qty, external_variant_id, erp_item_code, '
            . "description, reason, payload, dedupe_key) VALUES ('erp_relay', ?, 'stock_reconciliation', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id",
            [$caller->channelId, $docRef, (int) $line['line_index'], self::KIND_RECONCILIATION, (int) $line['qty'], $line['variant_id'] ?? null,
                $line['erp_item_code'] ?? null, $line['description'] ?? null, $reason, Idempotency::json($line + $extra), $key],
        );
        return (int) $db->value('SELECT id FROM goods_in_suspense WHERE dedupe_key = ?', [$key]);
    }

    /** The store's sellable warehouse (or the one named), else MAIN. */
    private function warehouse(Db $db, Caller $caller, ?string $code): int
    {
        if ($code !== null) {
            return (int) ($db->value('SELECT id FROM warehouse WHERE code = ?', [$code])
                ?? throw new CwException('unknown_warehouse', 'no warehouse ' . mb_substr($code, 0, 32), 422));
        }
        $wh = $db->value('SELECT warehouse_id FROM channel_warehouse WHERE channel_id = ? AND is_sellable = 1', [$caller->channelId]);
        return $wh !== null ? (int) $wh : (int) $db->value("SELECT id FROM warehouse WHERE code = 'MAIN'");
    }

    private static function docRef(mixed $v): string
    {
        $s = self::optString($v, 'doc_ref', 191);
        if ($s === null) {
            throw new CwException('doc_ref_required', 'a relayed message needs doc_ref (the document name, or the store\'s message id)', 400);
        }
        return $s;
    }

    /**
     * The lines of a relayed message, each naming its item by erp_item_code and/or variant_id, with a unique line_index (default: its
     * place), plus what $more checks.
     *
     * @param \Closure(array<string, mixed>, int): array<string, mixed> $more
     * @return list<array<string, mixed>> sorted by line_index
     */
    private static function lines(mixed $lines, \Closure $more): array
    {
        if (!is_array($lines) || $lines === [] || !array_is_list($lines) || count($lines) > self::MAX_LINES) {
            throw new CwException('bad_lines', 'lines must be a non-empty list of at most ' . self::MAX_LINES, 400);
        }
        $out = [];
        $seen = [];
        foreach ($lines as $i => $line) {
            if (!is_array($line)) {
                throw new CwException('bad_lines', "lines[{$i}] must be an object", 400);
            }
            $n = [];
            if (array_key_exists('erp_item_code', $line)) {
                $n['erp_item_code'] = self::optString($line['erp_item_code'], "lines[{$i}].erp_item_code", 140)
                    ?? throw new CwException('bad_lines', "lines[{$i}].erp_item_code is empty", 400);
            }
            if (array_key_exists('variant_id', $line)) {
                $v = is_int($line['variant_id']) ? (string) $line['variant_id'] : $line['variant_id'];
                $n['variant_id'] = self::optString($v, "lines[{$i}].variant_id", 64) ?? throw new CwException('bad_lines', "lines[{$i}].variant_id is empty", 400);
            }
            if ($n === []) {
                throw new CwException('bad_lines', "lines[{$i}] needs variant_id or erp_item_code", 400);
            }
            $idx = $line['line_index'] ?? $i;
            if (!is_int($idx) || $idx < 0 || $idx > Movements::MAX_LINE_INDEX || isset($seen[$idx])) {
                throw new CwException('bad_lines', "lines[{$i}].line_index must be a unique integer from 0 to " . Movements::MAX_LINE_INDEX, 400);
            }
            $seen[$idx] = true;
            $n['line_index'] = $idx;
            if (isset($line['description'])) {
                $n['description'] = self::optString($line['description'], "lines[{$i}].description", 512);
            }
            $n += $more($line, $i);
            ksort($n);
            $out[] = $n;
        }
        usort($out, static fn (array $a, array $b): int => $a['line_index'] <=> $b['line_index']);
        return $out;
    }

    private static function optString(mixed $v, string $field, int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_string($v) || strlen($v) > $max) {
            throw new CwException('bad_field', "{$field} must be a string of at most {$max} bytes", 400, ['field' => $field]);
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
