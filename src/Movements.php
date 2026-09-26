<?php

declare(strict_types=1);

namespace CW;

/**
 * Stock movements that are not sales (plan §3 POST /v1/movements, §8.2, §9):
 *
 *   goods_in, transfer_in                        on_hand + |qty| x u
 *   supplier_return, erp_sale, write_off,
 *   transfer_out                                 on_hand - |qty| x u   (the type sets the sign)
 *   adjustment                                   on_hand + qty x u     (signed, non-zero)
 *   count (counted_at required)                  on_hand = counted - net ships already applied
 *                                                that were dispatched after counted_at (§8.2)
 *
 * A line names its item by exactly one of: sku_id, sku_code, erp_item_code (the sku_erp_item
 * override, falling back to variant_id when given too) or variant_id (the calling channel's
 * listing link x u). goods_in / supplier_return / erp_sale lines that do not resolve land in
 * goods_in_suspense (deduplicated by document + line index); for the other types an
 * unresolved line refuses the whole call (422, nothing booked).
 *
 * Idempotent per (channel or source, Idempotency-Key) through Idempotency.
 *
 * Who may send what (R17): a site (channel caller) may send only the ERP-relay types
 * (CHANNEL_TYPES) that its channel.movement_types grants (none by default): 403 otherwise.
 * Counts, adjustments, write-offs and transfers are staff-only (/ui, session + TOTP).
 * counted_at must be plausible (R5): not after CW's clock + Clock::MAX_AHEAD_SEC and at most
 * COUNT_MAX_AGE_SEC old; staff may send `backdated: true` for a count up to BACKDATED_MAX_AGE_SEC old.
 */
final class Movements
{
    public const TYPES = ['goods_in', 'supplier_return', 'erp_sale', 'adjustment', 'count', 'write_off', 'transfer_out', 'transfer_in'];
    private const PLUS = ['goods_in', 'transfer_in'];
    private const MINUS = ['supplier_return', 'erp_sale', 'write_off', 'transfer_out'];
    private const SUSPENSE = ['goods_in', 'supplier_return', 'erp_sale'];
    /** The only types a site may be granted (the ERP relay, §9). */
    public const CHANNEL_TYPES = ['goods_in', 'supplier_return', 'erp_sale'];
    /** A count is submitted right after the counter finished the item (R5). */
    public const COUNT_MAX_AGE_SEC = 86_400;
    /** Staff override (`backdated: true`) for a count typed in later from paper. */
    public const BACKDATED_MAX_AGE_SEC = 90 * 86_400;
    /** line_index is INT UNSIGNED. */
    public const MAX_LINE_INDEX = 4_294_967_295;
    /** Overlapping rows listed in a count_after_movements review (R13). */
    private const REVIEW_ROWS = 50;
    private const DOC_TYPES = ['goods_in' => 'purchase_invoice', 'supplier_return' => 'debit_note', 'erp_sale' => 'sales_invoice'];
    public const MAX_LINES = 2000;

    private readonly Stock $stock;
    private readonly Idempotency $idem;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock (tests pin it) */
    public function __construct(private readonly Db $db, ?Stock $stock = null, ?\Closure $clock = null)
    {
        $this->stock = $stock ?? new Stock($db);
        $this->idem = new Idempotency($db);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock)()->setTimezone(Clock::utc());
    }

    /**
     * @param array{type?: mixed, doc_ref?: mixed, doc_type?: mixed, warehouse?: mixed, counted_at?: mixed, backdated?: mixed, note?: mixed, lines?: mixed} $req
     */
    public function record(Caller $caller, array $req, string $idemKey): OpResult
    {
        $type = $req['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw new CwException('bad_type', 'type must be one of ' . implode(', ', self::TYPES), 400);
        }
        $this->checkAllowed($caller, $type);
        $docRef = self::optString($req['doc_ref'] ?? null, 'doc_ref', 191);
        if ($docRef === null && in_array($type, self::SUSPENSE, true)) {
            throw new CwException('doc_ref_required', "{$type} needs doc_ref (the document name)", 400);
        }
        $docType = self::optString($req['doc_type'] ?? null, 'doc_type', 32) ?? (self::DOC_TYPES[$type] ?? null);
        $note = self::optString($req['note'] ?? null, 'note', 255);
        $whCode = self::optString($req['warehouse'] ?? null, 'warehouse', 32);
        $countedAt = null;
        if ($type === 'count') {
            if (!isset($req['counted_at'])) {
                throw new CwException('counted_at_required', 'a count needs counted_at (when the counter started the item)', 400);
            }
            $countedAt = Clock::parse($req['counted_at'], 'counted_at');
        }
        $backdated = $req['backdated'] ?? false;
        if (!is_bool($backdated) || ($backdated && ($type !== 'count' || $caller->isChannel()))) {
            throw new CwException('bad_field', 'backdated (true/false) is a staff option of a count', 400, ['field' => 'backdated']);
        }
        $lines = self::normaliseLines($type, $req['lines'] ?? null);

        $request = ['type' => $type, 'doc_ref' => $docRef, 'doc_type' => $docType, 'warehouse' => $whCode,
            'counted_at' => $countedAt === null ? null : Clock::db($countedAt), 'note' => $note, 'lines' => $lines]
            + ($backdated ? ['backdated' => true] : []);
        return $this->idem->run(
            $caller, $idemKey, 'movement.' . $type, '/v1/movements', $request, 'movement', $docRef ?? $type,
            function (Db $db) use ($caller, $type, $docRef, $docType, $whCode, $countedAt, $backdated, $note, $lines, $idemKey): OpResult {
                if ($countedAt !== null) {
                    // After the idempotency lookup, so a stored answer is still replayed (R5).
                    Clock::checkWindow($countedAt, 'counted_at', $this->now(), $backdated ? self::BACKDATED_MAX_AGE_SEC : self::COUNT_MAX_AGE_SEC);
                }
                return $this->apply($caller, $type, $docRef, $docType, $whCode, $countedAt, $note, $lines, $idemKey);
            },
        );
    }

    /** R17: a site may send only the relay types its channel is granted. */
    private function checkAllowed(Caller $caller, string $type): void
    {
        if (!$caller->isChannel()) {
            return;
        }
        $granted = $this->db->value('SELECT movement_types FROM channel WHERE id = ?', [$caller->channelId]);
        $granted = $granted === null ? [] : json_decode((string) $granted, true);
        if (!in_array($type, self::CHANNEL_TYPES, true) || !is_array($granted) || !in_array($type, $granted, true)) {
            throw new CwException('movement_not_allowed', "this site may not send {$type} movements"
                . (in_array($type, self::CHANNEL_TYPES, true) ? ' (not granted to its channel)' : ' (staff only)'), 403, ['type' => $type]);
        }
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function apply(Caller $caller, string $type, ?string $docRef, ?string $docType, ?string $whCode,
        ?\DateTimeImmutable $countedAt, ?string $note, array $lines, string $idemKey): OpResult
    {
        $defaultWh = $whCode !== null ? $this->stock->warehouseId($whCode) : $this->defaultWarehouse($caller);

        $resolved = [];
        $unresolved = [];
        foreach ($lines as $i => $line) {
            $wh = isset($line['warehouse']) ? $this->stock->warehouseId($line['warehouse']) : $defaultWh;
            $r = $this->resolve($caller, $line);
            if (isset($r['reason'])) {
                $unresolved[$i] = $r['reason'];
            } else {
                $resolved[$i] = ['warehouse_id' => $wh, 'sku_id' => $r['sku_id'], 'code' => $r['code'], 'u' => $r['u']];
            }
        }
        if ($unresolved !== [] && !in_array($type, self::SUSPENSE, true)) {
            $bad = [];
            foreach ($unresolved as $i => $reason) {
                $bad[] = ['line_index' => $lines[$i]['line_index'], 'reason' => $reason];
            }
            return OpResult::of(422, ['error' => 'unresolved_line', 'type' => $type, 'lines' => $bad]);
        }

        $this->stock->lock(array_map(static fn (array $r): array => [$r['warehouse_id'], $r['sku_id']], $resolved));
        $out = [];
        $now = Clock::db($this->now());
        $m = ['type' => $type, 'actor' => $caller->actor, 'channel_id' => $caller->channelId, 'doc_ref' => $docRef,
            'idem_key' => $idemKey, 'effective_at' => $countedAt === null ? $now : Clock::db($countedAt), 'note' => $note];

        if ($type === 'count') {
            assert($countedAt !== null);
            // Lines of one item at one location are summed (several shelf spots).
            $counted = [];
            foreach ($resolved as $i => $r) {
                $k = Stock::key($r['warehouse_id'], $r['sku_id']);
                $counted[$k] = ($counted[$k] ?? 0) + $lines[$i]['qty'] * $r['u'];
            }
            $results = [];
            foreach ($counted as $k => $qty) {
                [$wh, $sku] = array_map('intval', explode(':', $k));
                $results[$k] = $this->count($wh, $sku, $qty, $countedAt, $m, $docRef);
            }
            foreach ($resolved as $i => $r) {
                $out[] = ['line_index' => $lines[$i]['line_index'], 'sku_code' => $r['code']]
                    + $results[Stock::key($r['warehouse_id'], $r['sku_id'])];
            }
        } else {
            foreach ($resolved as $i => $r) {
                $q = $lines[$i]['qty'] * $r['u'];
                $delta = match (true) {
                    in_array($type, self::PLUS, true) => abs($q),
                    in_array($type, self::MINUS, true) => -abs($q),
                    default => $q, // adjustment: signed
                };
                $after = $this->stock->apply($r['warehouse_id'], $r['sku_id'], 'on_hand', $delta,
                    ['note' => self::lineNote($note, $lines[$i]['line_index'])] + $m);
                $out[] = ['line_index' => $lines[$i]['line_index'], 'result' => 'booked', 'sku_code' => $r['code'],
                    'delta' => $delta, 'on_hand' => $after];
            }
        }

        foreach ($unresolved as $i => $reason) {
            $line = $lines[$i];
            $sign = in_array($type, self::PLUS, true) ? 1 : -1;
            $this->db->exec(
                'INSERT INTO goods_in_suspense (source, channel_id, doc_type, doc_ref, line_index, movement_type, qty, external_variant_id, '
                . 'erp_item_code, barcode, description, reason, payload, dedupe_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE id = id',
                [$caller->isChannel() ? 'erp_relay' : 'cw_screen', $caller->channelId, $docType ?? $type, $docRef, $line['line_index'], $type,
                    $sign * abs($line['qty']), $line['variant_id'] ?? null, $line['erp_item_code'] ?? null, $line['barcode'] ?? null,
                    $line['description'] ?? null, $reason, Idempotency::json($line),
                    mb_strcut("{$type}:{$docRef}#{$line['line_index']}", 0, 191, 'UTF-8')],
            );
            $out[] = ['line_index' => $line['line_index'], 'result' => 'suspense', 'reason' => $reason];
        }
        usort($out, static fn (array $a, array $b): int => $a['line_index'] <=> $b['line_index']);
        $this->stock->flush();
        return OpResult::of(200, ['result' => 'recorded', 'type' => $type, 'doc_ref' => $docRef, 'lines' => $out]);
    }

    /**
     * §8.2: on_hand = counted − (net on_hand of ships/unships already applied whose effective
     * time is after counted_at). A count older than the location's last count is ignored.
     *
     * @param array<string, mixed> $m
     * @return array{result: string, delta?: int, on_hand: int, counted?: int}
     */
    private function count(int $wh, int $sku, int $counted, \DateTimeImmutable $countedAt, array $m, ?string $docRef): array
    {
        $row = $this->stock->row($wh, $sku);
        $at = Clock::db($countedAt);
        if ($row['counted_at'] !== null && $row['counted_at'] > $at) {
            return ['result' => 'stale_count', 'on_hand' => $row['on_hand'], 'last_counted_at' => Clock::iso($row['counted_at'])];
        }
        $shipsAfter = $this->stock->shipsAppliedAfter($wh, $sku, $countedAt); // <= 0 normally
        $target = $counted + $shipsAfter;
        $delta = $target - $row['on_hand'];
        $this->stock->apply($wh, $sku, 'on_hand', $delta,
            ['note' => "counted={$counted} ships_after={$shipsAfter}"] + $m, true);
        $this->stock->setCountedAt($wh, $sku, $countedAt);

        // R13: other on_hand movements booked after counted_at (a goods-in, a return, a write-off,
        // a cancel moved to VERIFY, ...) were probably not seen by the counter, and the count has
        // just overwritten them. The count rule adjusts for ships only (§8.2), so a person decides.
        $later = $this->stock->otherMovementsAfter($wh, $sku, $countedAt, self::REVIEW_ROWS);
        if ($later !== []) {
            $this->stock->openCountReview($wh, $sku, 'count_after_movements', $counted, $docRef,
                ['counted_at' => $at, 'on_hand_set' => $target, 'net' => array_sum(array_column($later, 'qty_delta')), 'rows' => $later],
                "count_after_movements:{$wh}:{$sku}:{$at}");
        }
        $near = $this->stock->shipsNear($wh, $sku, $countedAt);
        if ($near !== []) {
            $this->stock->openCountReview($wh, $sku, 'count_near_ship', $counted, $docRef,
                ['counted_at' => $at, 'units' => array_map(static fn (array $r): array => [
                    'channel_id' => $r['channel_id'], 'order_ref' => $r['order_ref'], 'unit_id' => $r['unit_id'], 'dispatched_at' => $r['effective_at'],
                ], $near)],
                "count_near_ship:{$wh}:{$sku}:{$at}");
        }
        return ['result' => 'counted', 'counted' => $counted, 'delta' => $delta, 'on_hand' => $target];
    }

    /**
     * @param array<string, mixed> $line
     * @return array{sku_id: int, code: string, u: int}|array{reason: string}
     */
    private function resolve(Caller $caller, array $line): array
    {
        if (isset($line['sku_id'])) {
            $code = $this->db->value('SELECT code FROM sku WHERE id = ?', [$line['sku_id']]);
            return $code === null ? ['reason' => 'unknown_sku'] : ['sku_id' => (int) $line['sku_id'], 'code' => (string) $code, 'u' => 1];
        }
        if (isset($line['sku_code'])) {
            $id = $this->db->value('SELECT id FROM sku WHERE code = ?', [$line['sku_code']]);
            return $id === null ? ['reason' => 'unknown_sku'] : ['sku_id' => (int) $id, 'code' => (string) $line['sku_code'], 'u' => 1];
        }
        if (isset($line['erp_item_code'])) {
            $r = $this->db->one(
                'SELECT e.sku_id, e.units_per_item, s.code FROM sku_erp_item e JOIN sku s ON s.id = e.sku_id WHERE e.item_code = ?',
                [$line['erp_item_code']],
            );
            if ($r !== null) {
                return ['sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'u' => (int) $r['units_per_item']];
            }
            if (!isset($line['variant_id'])) {
                return ['reason' => 'unknown_erp_item'];
            }
        }
        if (!$caller->isChannel()) {
            throw new CwException('variant_needs_channel', 'variant_id lines can only be sent by a site', 400);
        }
        $r = $this->db->one(
            'SELECT cl.sku_id, cl.units_per_item, cl.status, s.code FROM channel_listing cl LEFT JOIN sku s ON s.id = cl.sku_id '
            . 'WHERE cl.channel_id = ? AND cl.external_variant_id = ?',
            [$caller->channelId, $line['variant_id']],
        );
        if ($r === null) {
            return ['reason' => 'unknown_listing'];
        }
        if (!in_array($r['status'], ['mapped', 'quarantined'], true)) {
            return ['reason' => 'unlinked_listing'];
        }
        return ['sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'u' => (int) $r['units_per_item']];
    }

    private function defaultWarehouse(Caller $caller): int
    {
        if ($caller->isChannel()) {
            $wh = $this->db->value('SELECT warehouse_id FROM channel_warehouse WHERE channel_id = ? AND is_sellable = 1', [$caller->channelId]);
            if ($wh !== null) {
                return (int) $wh;
            }
        }
        return $this->stock->warehouseId('MAIN');
    }

    /** @return list<array<string, mixed>> */
    private static function normaliseLines(string $type, mixed $lines): array
    {
        if (!is_array($lines) || $lines === [] || !array_is_list($lines) || count($lines) > self::MAX_LINES) {
            throw new CwException('bad_lines', 'lines must be a non-empty list', 400);
        }
        $out = [];
        $indexes = [];
        foreach ($lines as $i => $line) {
            if (!is_array($line)) {
                throw new CwException('bad_lines', "lines[{$i}] must be an object", 400);
            }
            $n = [];
            $keys = 0;
            if (array_key_exists('sku_id', $line)) {
                if (!is_int($line['sku_id']) || $line['sku_id'] <= 0) {
                    throw new CwException('bad_lines', "lines[{$i}].sku_id must be a positive integer", 400);
                }
                $n['sku_id'] = $line['sku_id'];
                $keys++;
            }
            foreach (['sku_code' => 16, 'erp_item_code' => 140] as $k => $max) {
                if (array_key_exists($k, $line)) {
                    $n[$k] = self::optString($line[$k], "lines[{$i}].{$k}", $max) ?? throw new CwException('bad_lines', "lines[{$i}].{$k} is empty", 400);
                    $keys++;
                }
            }
            if (array_key_exists('variant_id', $line)) {
                $v = is_int($line['variant_id']) ? (string) $line['variant_id'] : $line['variant_id'];
                $n['variant_id'] = self::optString($v, "lines[{$i}].variant_id", 64) ?? throw new CwException('bad_lines', "lines[{$i}].variant_id is empty", 400);
                $keys += isset($n['erp_item_code']) ? 0 : 1; // erp_item_code + variant_id = override with fallback
            }
            if ($keys !== 1) {
                throw new CwException('bad_lines', "lines[{$i}] needs exactly one of sku_id, sku_code, erp_item_code, variant_id", 400);
            }
            $qty = $line['qty'] ?? null;
            if (!is_int($qty) || ($type === 'count' ? $qty < 0 : $qty === 0) || abs($qty) > 10_000_000) {
                throw new CwException('bad_lines', $type === 'count' ? "lines[{$i}].qty (counted) must be >= 0" : "lines[{$i}].qty must be a non-zero integer", 400);
            }
            $n['qty'] = $qty;
            $idx = $line['line_index'] ?? $i;
            if (!is_int($idx) || $idx < 0 || $idx > self::MAX_LINE_INDEX || isset($indexes[$idx])) {
                throw new CwException('bad_lines', "lines[{$i}].line_index must be a unique integer from 0 to " . self::MAX_LINE_INDEX, 400);
            }
            $indexes[$idx] = true;
            $n['line_index'] = $idx;
            if (isset($line['warehouse'])) {
                $n['warehouse'] = self::optString($line['warehouse'], "lines[{$i}].warehouse", 32);
            }
            foreach (['barcode' => 64, 'description' => 512] as $k => $max) {
                if (isset($line[$k])) {
                    $n[$k] = self::optString($line[$k], "lines[{$i}].{$k}", $max);
                }
            }
            ksort($n);
            $out[] = $n;
        }
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

    private static function lineNote(?string $note, int $lineIndex): string
    {
        return ($note === null ? '' : $note . ' ') . '#' . $lineIndex;
    }
}
