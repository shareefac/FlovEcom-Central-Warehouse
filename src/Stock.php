<?php

declare(strict_types=1);

namespace CW;

use DateTimeImmutable;

/**
 * THE ONLY writer of stock_balance, stock_ledger and stock_change (plan §2).
 *
 * Bucket arithmetic (§2.3), in central (SKU) units:
 *   on_hand   units physically in the building (may go negative, D1: opens a count_review)
 *   allocated paid, not yet dispatched (never negative, CHECK)
 *   held      unpaid checkouts (never negative, CHECK)
 *   available = on_hand - allocated - held
 *
 * Usage inside ONE transaction per operation:
 *   $stock->lock($pairs)        lock every (warehouse, sku) balance the operation touches, in
 *                               (warehouse_id, sku_id) order (creates missing rows)
 *   $stock->apply(...)          change one bucket: UPDATE + one stock_ledger row
 *   $stock->flush()             numbers every on_hand ledger row of the operation in its item's
 *                               value sequence (I3), then writes one stock_change row per sku whose
 *                               availability at a sellable warehouse changed (the change feed, §3)
 * Lock order in every transaction (§3, D39, R1, I3): the idempotency claim (its FK check takes an S
 * lock on the calling site's channel row) -> channel_opening (opening orders only) -> reservation
 * rows -> channel_listing rows (FOR SHARE, sales only, R4) -> stock_balance rows (this class) ->
 * sku rows (callers lock sku rows only after lock(); a link decision reads them FOR SHARE before,
 * M4) -> the item value clocks (stock_value_clock rows, sku_id order, taken by flush() for the items
 * whose on_hand changed, I3) -> the feed clock (D39), taken by every stock_change insert and held to
 * commit, so `seq` is allocated in commit order and a listing's version never goes backwards.
 * Nothing is locked after the feed clock.
 *
 * A row of the operation that changes on_hand (any delta, a zero-delta journalled count too) may
 * carry a cost and a document link (unit_cost, cost_source, document_id, document_line: I1, I2);
 * the value of the stock is IM8's business (Valuation.php), not this class's.
 *
 * flush() also raises the oversell flags of non-sale paths (R6): a strict/stopped item whose
 * availability at a sellable warehouse fell in this operation and ended below zero.
 */
final class Stock
{
    public const BUCKETS = ['on_hand', 'allocated', 'held'];
    public const POLICIES = ['legacy', 'strict', 'backorder', 'stopped'];
    /** Ships dispatched within this many seconds of a count's counted_at go to count review (§8.2). */
    public const NEAR_COUNT_SEC = 600;
    /** Feed rows per multi-row INSERT in flush() (R2: one round trip per chunk, not per item). */
    public const FEED_CHUNK = 1000;
    /** Item clocks per multi-row INSERT in assignValueSeq() (one chunk = three round trips, I3). */
    public const VALUE_CHUNK = 1000;
    /** Movement types whose on_hand rows may carry a unit cost (I1; ck_stock_ledger_cost). */
    public const COST_TYPES = ['goods_in', 'supplier_return', 'adjustment', 'count', 'write_off'];
    /** Where a ledger row's cost came from (stock_ledger.cost_source). */
    public const COST_SOURCES = ['document', 'manual', 'estimate'];
    /** The only cost currency (I1: a foreign invoice is converted on its document). */
    public const CURRENCY = 'GBP';
    /** The INT range of the bucket columns (R16: refused with 422 instead of a strict-mode 500). */
    public const INT_MIN = -2_147_483_648;
    public const INT_MAX = 2_147_483_647;
    /**
     * Movement types whose availability fall is NOT flagged by flush() (R6): holds are not sales
     * (a live site's reserve is refused when short), and the commit and uncancel paths raise their
     * own oversell_event (Reservations::applyCommit, D33; Reservations::uncancel, D46).
     */
    private const UNFLAGGED = ['reserve', 'commit', 'commit_release', 'uncancel'];

    /** @var array<string, array{warehouse_id: int, sku_id: int, on_hand: int, allocated: int, held: int, counted_at: ?string}> */
    private array $rows = [];
    /** @var array<string, int> net availability change per balance since the last flush() */
    private array $availDelta = [];
    /** @var array<string, bool> balances that already got a negative_on_hand check in this operation */
    private array $negChecked = [];
    /** @var array<string, array<string, mixed>> per balance: the last movement that lowered availability (R6) */
    private array $lastFall = [];
    /** @var list<array{0: int, 1: int}> [sku_id, stock_ledger id] of this operation's on_hand rows, in apply (= ledger id) order */
    private array $valuePending = [];
    /** Db::transactionSerial() of the transaction that wrote $valuePending (I7: a rolled-back operation leaves stale entries) */
    private ?int $pendingSerial = null;
    /**
     * Per connection (every Stock on it): the Db::transactionSerial() of the transaction that took value clocks or the
     * feed clock, after which no balance may be locked (D39, I29). Static, so the rule holds across Stock instances
     * (a second Movements or Reservations on the same connection inside the same transaction).
     *
     * @var \WeakMap<Db, int>|null
     */
    private static ?\WeakMap $sealed = null;
    /** @var array<int, bool>|null warehouse id => is_sellable */
    private ?array $sellable = null;
    /** @var array<string, int>|null warehouse code => id */
    private ?array $warehouseIds = null;

    private readonly Idempotency $idem;

    public function __construct(private readonly Db $db)
    {
        $this->idem = new Idempotency($db);
    }

    public static function key(int $warehouseId, int $skuId): string
    {
        return $warehouseId . ':' . $skuId;
    }

    // ------------------------------------------------------------------------------------------
    // Locking and bucket changes
    // ------------------------------------------------------------------------------------------

    /**
     * Locks (FOR UPDATE) every balance in $pairs in (warehouse_id, sku_id) order and caches their
     * values for this operation. Missing rows are created. Resets the per-operation state, so an
     * operation calls lock() exactly once, with every pair it will touch.
     *
     * Refuses (LogicException) while on_hand rows applied in THIS transaction still wait for their
     * value seq: a second lock() before flush() would drop them from the item's sequence, or take
     * balance locks while the first operation's clocks are still to come (I7: one lock(), one flush()
     * per operation). Entries left by a rolled-back transaction are discarded. Refuses too once this
     * transaction has taken value clocks or the feed clock, through ANY Stock on this connection: a
     * balance locked after them would break the lock order (D39, I29).
     *
     * @param iterable<array{0: int, 1: int}> $pairs [warehouse_id, sku_id]
     */
    public function lock(iterable $pairs): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Stock::lock() must run inside a transaction');
        }
        if ($this->valuePending !== [] && $this->pendingSerial === $this->db->transactionSerial()) {
            throw new \LogicException('on_hand rows of this operation have no value seq yet: call flush() before lock() again');
        }
        if (self::$sealed !== null && (self::$sealed[$this->db] ?? null) === $this->db->transactionSerial()) {
            throw new \LogicException('this transaction already took value clocks or the feed clock: no balance is locked after them '
                . '(D39, I29: one lock() and one flush() per transaction)');
        }
        $this->valuePending = [];
        $this->pendingSerial = null;
        $this->rows = [];
        $this->availDelta = [];
        $this->negChecked = [];
        $this->lastFall = [];

        $uniq = [];
        foreach ($pairs as [$wh, $sku]) {
            $uniq[self::key((int) $wh, (int) $sku)] = [(int) $wh, (int) $sku];
        }
        uasort($uniq, static fn (array $a, array $b): int => $a <=> $b);

        $select = 'SELECT warehouse_id, sku_id, on_hand, allocated, held, counted_at FROM stock_balance '
            . 'WHERE warehouse_id = ? AND sku_id = ? FOR UPDATE';
        foreach ($uniq as $k => [$wh, $sku]) {
            $row = $this->db->one($select, [$wh, $sku]);
            if ($row === null) {
                // First movement of this item at this location. ODKU takes the X lock whether we
                // insert or a concurrent transaction inserted first (no S->X upgrade deadlock).
                $this->db->exec(
                    'INSERT INTO stock_balance (warehouse_id, sku_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE on_hand = on_hand',
                    [$wh, $sku],
                );
                $row = $this->db->one($select, [$wh, $sku]);
                if ($row === null) {
                    throw new \RuntimeException("stock_balance {$k} could not be created");
                }
            }
            $this->rows[$k] = [
                'warehouse_id' => (int) $row['warehouse_id'],
                'sku_id' => (int) $row['sku_id'],
                'on_hand' => (int) $row['on_hand'],
                'allocated' => (int) $row['allocated'],
                'held' => (int) $row['held'],
                'counted_at' => $row['counted_at'] === null ? null : (string) $row['counted_at'],
            ];
        }
    }

    /** @return array{warehouse_id: int, sku_id: int, on_hand: int, allocated: int, held: int, counted_at: ?string} */
    public function row(int $warehouseId, int $skuId): array
    {
        $k = self::key($warehouseId, $skuId);
        if (!isset($this->rows[$k])) {
            throw new \LogicException("stock_balance {$k} is not locked by this operation");
        }
        return $this->rows[$k];
    }

    /** available = on_hand - allocated - held of a locked balance (central units; may be negative). */
    public function available(int $warehouseId, int $skuId): int
    {
        $r = $this->row($warehouseId, $skuId);
        return $r['on_hand'] - $r['allocated'] - $r['held'];
    }

    /**
     * Changes one bucket of a locked balance and journals it. Returns the bucket's new value.
     * Every on_hand row (a journalled zero included) is queued for its item's value seq, which
     * flush() assigns (I3).
     *
     * @param array{type: string, actor: string, channel_id?: ?int, order_ref?: ?string, unit_id?: ?string,
     *              doc_ref?: ?string, idem_key?: ?string, effective_at?: ?string, note?: ?string,
     *              unit_cost?: ?string, cost_source?: ?string, document_id?: ?int, document_line?: ?int} $m
     *        unit_cost: canonical 6-dp GBP per central unit (Movements::normaliseCost), only on an on_hand
     *        row of a COST_TYPES movement, with its cost_source (I1); document_id/document_line: I2
     * @param bool $journalZero write a ledger row even when $delta is 0 (a count that found no difference)
     */
    public function apply(int $warehouseId, int $skuId, string $bucket, int $delta, array $m, bool $journalZero = false): int
    {
        if (!in_array($bucket, self::BUCKETS, true)) {
            throw new \InvalidArgumentException("unknown bucket {$bucket}");
        }
        $cost = $m['unit_cost'] ?? null;
        if ($cost !== null && ($bucket !== 'on_hand' || !in_array($m['type'], self::COST_TYPES, true)
            || !is_string($cost) || preg_match('/^(0|[1-9][0-9]{0,7})\.[0-9]{6}$/D', $cost) !== 1
            || !in_array($m['cost_source'] ?? null, self::COST_SOURCES, true))) {
            // The caller's validation failed open (ck_stock_ledger_cost would refuse most of it anyway).
            throw new \LogicException("a unit cost belongs on an on_hand row of a cost-bearing movement, as a canonical decimal with its source ({$bucket} {$m['type']})");
        }
        $k = self::key($warehouseId, $skuId);
        $before = $this->row($warehouseId, $skuId)[$bucket];
        if ($delta === 0 && !$journalZero) {
            return $before;
        }
        $after = $before + $delta;
        if ($after < self::INT_MIN || $after > self::INT_MAX || $delta < self::INT_MIN || $delta > self::INT_MAX) {
            // Beyond the INT columns: strict mode would fail the statement with a 500 (1264) and the
            // caller would retry for ever. Refuse it as a request CW cannot book (R16).
            throw new CwException('out_of_range', "{$bucket} of item {$skuId} would be {$after}, outside the storable range", 422,
                ['warehouse_id' => $warehouseId, 'sku_id' => $skuId, 'bucket' => $bucket]);
        }
        if ($bucket !== 'on_hand' && $after < 0) {
            // The CHECK would refuse it anyway; say which operation is wrong.
            throw new \LogicException("{$bucket} of {$k} would go negative ({$before} + {$delta}) in {$m['type']}");
        }
        if ($delta !== 0) {
            $this->db->exec(
                "UPDATE stock_balance SET `{$bucket}` = `{$bucket}` + ? WHERE warehouse_id = ? AND sku_id = ?",
                [$delta, $warehouseId, $skuId],
            );
        }
        $this->rows[$k][$bucket] = $after;
        $ledgerId = $this->db->insert(
            'INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, channel_id, '
            . 'order_ref, unit_id, doc_ref, idem_key, effective_at, actor, note, unit_cost, cost_currency, cost_source, document_id, document_line) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $warehouseId, $skuId, $bucket, $delta, $after, $m['type'], $m['channel_id'] ?? null,
                $m['order_ref'] ?? null, $m['unit_id'] ?? null, $m['doc_ref'] ?? null, $m['idem_key'] ?? null,
                $m['effective_at'] ?? null, $m['actor'], isset($m['note']) ? substr($m['note'], 0, 255) : null,
                $cost, $cost === null ? null : self::CURRENCY, $cost === null ? null : $m['cost_source'],
                $m['document_id'] ?? null, $m['document_line'] ?? null,
            ],
        );
        if ($bucket === 'on_hand') {
            if ($this->valuePending === []) {
                $this->pendingSerial = $this->db->transactionSerial();
            }
            $this->valuePending[] = [$skuId, $ledgerId];
        }
        $availChange = $bucket === 'on_hand' ? $delta : -$delta;
        $this->availDelta[$k] = ($this->availDelta[$k] ?? 0) + $availChange;
        if ($availChange < 0) {
            $this->lastFall[$k] = $m;
        }

        if ($bucket === 'on_hand' && $after < 0 && !isset($this->negChecked[$k])) {
            $this->negChecked[$k] = true;
            $this->reviewNegative($warehouseId, $skuId, $after, $m);
        }
        return $after;
    }

    /** Records the as-of time of a count at this location (D2). */
    public function setCountedAt(int $warehouseId, int $skuId, DateTimeImmutable $countedAt): void
    {
        $k = self::key($warehouseId, $skuId);
        $this->row($warehouseId, $skuId);
        $v = Clock::db($countedAt);
        $this->db->exec('UPDATE stock_balance SET counted_at = ? WHERE warehouse_id = ? AND sku_id = ?', [$v, $warehouseId, $skuId]);
        $this->rows[$k]['counted_at'] = $v;
    }

    /**
     * Net on_hand change of the ship/unship rows already applied to this balance whose
     * effective time is after $at (the §8.2 count rule: those units were on the shelf at the count).
     */
    public function shipsAppliedAfter(int $warehouseId, int $skuId, DateTimeImmutable $at): int
    {
        return (int) $this->db->value(
            "SELECT COALESCE(SUM(qty_delta), 0) FROM stock_ledger WHERE warehouse_id = ? AND sku_id = ? AND bucket = 'on_hand' "
            . "AND effective_at > ? AND movement_type IN ('ship', 'unship')",
            [$warehouseId, $skuId, Clock::db($at)],
        );
    }

    /**
     * On_hand rows of this balance other than ships/unships/counts whose effective time is after
     * $at (R13: goods-in, returns, ERP sales, write-offs, adjustments, transfers booked after a
     * count's counted_at; the count overwrites them). At most $limit, oldest first.
     *
     * @return list<array{movement_type: string, qty_delta: int, effective_at: string, doc_ref: ?string, order_ref: ?string, unit_id: ?string}>
     */
    public function otherMovementsAfter(int $warehouseId, int $skuId, DateTimeImmutable $at, int $limit): array
    {
        /** @var list<array{movement_type: string, qty_delta: int, effective_at: string, doc_ref: ?string, order_ref: ?string, unit_id: ?string}> */
        return $this->db->all(
            "SELECT movement_type, qty_delta, effective_at, doc_ref, order_ref, unit_id FROM stock_ledger WHERE warehouse_id = ? AND sku_id = ? "
            . "AND bucket = 'on_hand' AND effective_at > ? AND movement_type NOT IN ('ship', 'unship', 'count') ORDER BY effective_at, id LIMIT ?",
            [$warehouseId, $skuId, Clock::db($at), $limit],
        );
    }

    /**
     * Ships of this balance dispatched within ±NEAR_COUNT_SEC of $countedAt (ambiguous: was the
     * unit on the shelf when it was counted?). @return list<array{channel_id: ?int, order_ref: ?string, unit_id: ?string, effective_at: string}>
     */
    public function shipsNear(int $warehouseId, int $skuId, DateTimeImmutable $countedAt): array
    {
        $from = Clock::db($countedAt->modify('-' . self::NEAR_COUNT_SEC . ' seconds'));
        $to = Clock::db($countedAt->modify('+' . self::NEAR_COUNT_SEC . ' seconds'));
        /** @var list<array{channel_id: ?int, order_ref: ?string, unit_id: ?string, effective_at: string}> */
        return $this->db->all(
            "SELECT channel_id, order_ref, unit_id, effective_at FROM stock_ledger WHERE warehouse_id = ? AND sku_id = ? "
            . "AND bucket = 'allocated' AND movement_type = 'ship' AND effective_at BETWEEN ? AND ? ORDER BY id",
            [$warehouseId, $skuId, $from, $to],
        );
    }

    /**
     * Writes one stock_change row per sku whose availability at a sellable warehouse changed
     * since the last flush()/lock(). Call it at the end of the operation, just before commit, so
     * a row's created_at is close to its commit time (the feed's overlap window relies on it).
     *
     * First it gives every on_hand row of the operation its item's value seq (I3): always, also
     * when no feed row follows (a ship moves on_hand and allocated together, a VERIFY move or a
     * count that found no difference changes no sellable availability). Then (still before the
     * feed clock) it flags oversells of the non-sale paths (R6). The rows go in multi-row INSERTs
     * of FEED_CHUNK (R2): the feed clock is global, so it must be held for a couple of round
     * trips, not one per item.
     */
    public function flush(string $reason = 'stock'): void
    {
        $this->assignValueSeq();
        $this->flagShortfalls();
        $skus = [];
        foreach ($this->availDelta as $k => $d) {
            if ($d === 0) {
                continue;
            }
            [$wh, $sku] = array_map('intval', explode(':', $k));
            if ($this->isSellable($wh)) {
                $skus[$sku] = true;
            }
        }
        $this->availDelta = [];
        $this->lastFall = [];
        $ids = array_keys($skus);
        if ($ids === []) {
            return;
        }
        sort($ids);
        $this->feedLock();
        foreach (array_chunk($ids, self::FEED_CHUNK) as $chunk) {
            $params = [];
            foreach ($chunk as $sku) {
                array_push($params, $sku, $reason);
            }
            $this->db->exec('INSERT INTO stock_change (sku_id, reason) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?)')), $params);
        }
    }

    /**
     * Numbers this operation's on_hand ledger rows in their items' value sequences (I3): per item
     * 1, 2, 3, ... in commit order, with no gap, so IM8 (Valuation.php) values each item's on_hand
     * changes strictly in that order and can tell "not yet committed" from "missing".
     *
     * Each item's clock is its own `stock_value_clock` row: one multi-row INSERT ... ON DUPLICATE KEY
     * UPDATE per VALUE_CHUNK items, in ascending sku_id (a global order, so two operations never
     * wait for each other's clocks in a cycle), adds the item's row count to last_seq and X-locks the
     * row; the item's rows then get last_seq - n + 1 .. last_seq in apply order (= ledger id order).
     * Three round trips per chunk: the INSERT, a SELECT of the new last_seq values (own writes are
     * visible under READ COMMITTED) and the seq rows.
     *
     * Why a clock row and not the `sku` row (the brief's "under the sku row lock"): nothing in the
     * stock core X-locks `sku` on an on_hand change, and a link decision reads `sku` FOR SHARE BEFORE
     * the balances (M4). X-locking it here, after the balances, would close a cycle with every link
     * that adopts units of the same item (decision: sku S -> wants balance X; ship: balance X -> wants
     * sku X) and queue every `sku` FOR SHARE reader behind dispatch traffic. The clock row sits where
     * the brief wanted the lock: after every stock_balance and sku lock, before the feed clock, and
     * nothing else ever locks it.
     *
     * Why commit order and never a visible gap: the clock row's X lock is held to commit; a rollback
     * restores last_seq together with the seq rows; so seq n + 1 of an item cannot be assigned until
     * the transaction that took n has ended, and a reader that sees seq n + 1 committed sees n too.
     * (stock_ledger ids, AUTO_INCREMENT at insert, are not in commit order across warehouses: a
     * consumer reading ledger ids in id order could skip a row committed late.)
     */
    private function assignValueSeq(): void
    {
        $pending = $this->valuePending;
        $serial = $this->pendingSerial;
        $this->valuePending = [];
        $this->pendingSerial = null;
        if ($pending === [] || $serial !== $this->db->transactionSerial()) {
            return; // nothing, or rows of a rolled-back transaction (gone with it)
        }
        if (!$this->db->inTransaction()) {
            throw new \LogicException('value seqs are assigned inside the transaction that books the rows');
        }
        $bySku = [];
        foreach ($pending as [$sku, $ledgerId]) {
            $bySku[$sku][] = $ledgerId;
        }
        ksort($bySku);
        foreach (array_chunk($bySku, self::VALUE_CHUNK, true) as $chunk) {
            $params = [];
            foreach ($chunk as $sku => $ids) {
                array_push($params, $sku, count($ids));
            }
            $this->seal();
            $this->db->exec(
                'INSERT INTO stock_value_clock (sku_id, last_seq) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?)'))
                . ' AS new ON DUPLICATE KEY UPDATE last_seq = stock_value_clock.last_seq + new.last_seq',
                $params,
            );
            $last = [];
            foreach ($this->db->all(
                'SELECT sku_id, last_seq FROM stock_value_clock WHERE sku_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                array_keys($chunk),
            ) as $r) {
                $last[(int) $r['sku_id']] = (int) $r['last_seq'];
            }
            $rows = [];
            foreach ($chunk as $sku => $ids) {
                $seq = $last[$sku] - count($ids);
                foreach ($ids as $ledgerId) {
                    array_push($rows, $sku, ++$seq, $ledgerId);
                }
            }
            foreach (array_chunk($rows, 3 * self::VALUE_CHUNK) as $part) {
                $this->db->exec(
                    'INSERT INTO stock_value_seq (sku_id, seq, stock_ledger_id) VALUES ' . implode(', ', array_fill(0, intdiv(count($part), 3), '(?, ?, ?)')),
                    $part,
                );
            }
        }
    }

    /**
     * §4 "always flagged ... never silent" for every path other than a sale (R6): for each balance
     * of this operation at a sellable warehouse whose availability FELL and ended below zero, on a
     * strict or stopped item, one oversell_event. Kinds: count_short (a count found fewer units
     * than are promised), reset_short (a dispatch reset before the count, reported after it),
     * adopt_short (units sold while the listing was unlinked, adopted at link time) and
     * movement_short (erp_sale, write_off, supplier_return, adjustment, transfer_out, ...).
     * shortfall = min(fall, -available). Deduplicated per operation key and balance.
     * The policy is read without a lock: setPolicy() locks the item's balances first, and this
     * operation holds them.
     */
    private function flagShortfalls(): void
    {
        $cand = [];
        foreach ($this->availDelta as $k => $d) {
            $m = $this->lastFall[$k] ?? null;
            if ($d >= 0 || $m === null || in_array($m['type'], self::UNFLAGGED, true)) {
                continue;
            }
            [$wh, $sku] = array_map('intval', explode(':', $k));
            if (!$this->isSellable($wh) || $this->available($wh, $sku) >= 0) {
                continue;
            }
            $cand[$k] = [$wh, $sku, $d, $m];
        }
        if ($cand === []) {
            return;
        }
        $skuIds = array_values(array_unique(array_map(static fn (array $c): int => $c[1], $cand)));
        $policy = [];
        foreach ($this->db->all('SELECT id, sell_policy FROM sku WHERE id IN (' . implode(',', array_fill(0, count($skuIds), '?')) . ')', $skuIds) as $r) {
            $policy[(int) $r['id']] = (string) $r['sell_policy'];
        }
        foreach ($cand as [$wh, $sku, $d, $m]) {
            $p = $policy[$sku] ?? 'legacy';
            if (!in_array($p, ['strict', 'stopped'], true)) {
                continue; // legacy: not protected yet; backorder: below zero is intended
            }
            $avail = $this->available($wh, $sku);
            $kind = match ($m['type']) {
                'count' => 'count_short',
                'unship' => 'reset_short',
                'adopt' => 'adopt_short',
                default => 'movement_short',
            };
            $opKey = $m['idem_key'] ?? null;
            $this->db->exec(
                'INSERT INTO oversell_event (warehouse_id, sku_id, channel_id, reservation_id, order_ref, kind, shortfall, available_after, detail, dedupe_key) '
                . 'VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
                [$wh, $sku, $m['channel_id'] ?? null, $m['order_ref'] ?? null, $kind, min(-$d, -$avail), $avail,
                    Idempotency::json(['policy' => $p, 'movement' => $m['type'], 'fall' => -$d, 'actor' => $m['actor'],
                        'doc_ref' => $m['doc_ref'] ?? null, 'unit_id' => $m['unit_id'] ?? null, 'note' => $m['note'] ?? null]),
                    $opKey === null ? null : mb_strcut("{$kind}:{$m['actor']}:{$opKey}:{$wh}:{$sku}", 0, 191, 'UTF-8')],
            );
        }
    }

    /**
     * Takes the feed clock's row lock (D39), the last lock of any transaction: until this
     * transaction commits, no other one can allocate a stock_change seq, so seq order = commit
     * order. ODKU locks the row whether it exists or not (tests empty every table).
     */
    private function feedLock(): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('stock_change rows are written inside the transaction that makes the change');
        }
        $this->seal();
        $this->db->exec('INSERT INTO feed_clock (id, ticks) VALUES (1, 1) ON DUPLICATE KEY UPDATE ticks = ticks + 1');
    }

    /** Marks this connection's transaction as past its balance locks (value clocks or feed clock taken: lock() refuses, I29). */
    private function seal(): void
    {
        self::$sealed ??= new \WeakMap();
        self::$sealed[$this->db] = $this->db->transactionSerial();
    }

    // ------------------------------------------------------------------------------------------
    // Change-feed rows for non-stock changes (link, policy, warehouse). Call inside the
    // transaction that makes the change.
    // ------------------------------------------------------------------------------------------

    /** A listing's link, u, status or quarantine changed (DecisionService calls this). */
    public function listingChanged(int $listingId, string $reason = 'link'): int
    {
        $this->feedLock();
        return $this->db->insert('INSERT INTO stock_change (listing_id, reason) VALUES (?, ?)', [$listingId, $reason]);
    }

    /** Something about the item changed for every listing linked to it (e.g. sell policy). */
    public function skuChanged(int $skuId, string $reason): int
    {
        $this->feedLock();
        return $this->db->insert('INSERT INTO stock_change (sku_id, reason) VALUES (?, ?)', [$skuId, $reason]);
    }

    /** Every listing of one channel changed (warehouse assignment, mode): the site re-snapshots. */
    public function channelChanged(int $channelId, string $reason): int
    {
        $this->feedLock();
        return $this->db->insert('INSERT INTO stock_change (channel_id, reason) VALUES (?, ?)', [$channelId, $reason]);
    }

    // ------------------------------------------------------------------------------------------
    // Staff operations that change availability without moving stock
    // ------------------------------------------------------------------------------------------

    /**
     * Sets an item's sell policy (legacy/strict/backorder/stopped, §12). Locks the item's
     * balances, then the sku row (lock order), so a reserve deciding on the old policy finishes
     * first. The count gate (§7.4) is the caller's job.
     */
    public function setPolicy(Caller $caller, int $skuId, string $policy, string $idemKey): OpResult
    {
        if (!in_array($policy, self::POLICIES, true)) {
            throw new CwException('bad_policy', 'policy must be one of ' . implode(', ', self::POLICIES), 400);
        }
        return $this->idem->run(
            $caller, $idemKey, 'sku.policy', '/v1/skus/' . $skuId . '/policy', ['sku_id' => $skuId, 'policy' => $policy],
            'sku', (string) $skuId,
            function (Db $db) use ($skuId, $policy): OpResult {
                $whs = $db->column('SELECT warehouse_id FROM stock_balance WHERE sku_id = ?', [$skuId]);
                $this->lock(array_map(static fn ($wh): array => [(int) $wh, $skuId], $whs));
                $cur = $db->one('SELECT code, sell_policy FROM sku WHERE id = ? FOR UPDATE', [$skuId]);
                if ($cur === null) {
                    throw new CwException('unknown_sku', 'no such item', 404);
                }
                $from = (string) $cur['sell_policy'];
                if ($from === $policy) {
                    return OpResult::of(200, ['result' => 'unchanged', 'sku_code' => $cur['code'], 'from' => $from, 'to' => $policy]);
                }
                $db->exec('UPDATE sku SET sell_policy = ? WHERE id = ?', [$policy, $skuId]);
                $this->skuChanged($skuId, 'policy');
                return OpResult::of(200, ['result' => 'changed', 'sku_code' => $cur['code'], 'from' => $from, 'to' => $policy]);
            },
        );
    }

    /**
     * Points a channel at another sellable warehouse (v1: exactly one per channel, D9). Every
     * listing of the channel changes, so the feed tells the site to re-snapshot.
     */
    public function assignSellableWarehouse(Caller $caller, int $channelId, string $warehouseCode, string $idemKey): OpResult
    {
        return $this->idem->run(
            $caller, $idemKey, 'channel.warehouse', '/v1/channels/' . $channelId . '/warehouse',
            ['channel_id' => $channelId, 'warehouse' => $warehouseCode], 'channel', (string) $channelId,
            function (Db $db) use ($channelId, $warehouseCode): OpResult {
                if ($db->one('SELECT id FROM channel WHERE id = ? FOR UPDATE', [$channelId]) === null) {
                    throw new CwException('unknown_channel', 'no such channel', 404);
                }
                $wh = $db->one('SELECT id, is_sellable FROM warehouse WHERE code = ?', [$warehouseCode]);
                if ($wh === null) {
                    throw new CwException('unknown_warehouse', 'no such warehouse', 404);
                }
                if ((int) $wh['is_sellable'] !== 1) {
                    return OpResult::of(422, ['error' => 'not_sellable', 'message' => 'a channel can only sell from a sellable warehouse']);
                }
                $from = $db->value('SELECT warehouse_id FROM channel_warehouse WHERE channel_id = ? AND is_sellable = 1', [$channelId]);
                if ($from !== null && (int) $from === (int) $wh['id']) {
                    return OpResult::of(200, ['result' => 'unchanged', 'warehouse' => $warehouseCode]);
                }
                $db->exec('DELETE FROM channel_warehouse WHERE channel_id = ? AND is_sellable = 1', [$channelId]);
                $db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$channelId, (int) $wh['id']]);
                $this->channelChanged($channelId, 'warehouse');
                return OpResult::of(200, ['result' => 'changed', 'warehouse' => $warehouseCode, 'from_warehouse_id' => $from]);
            },
        );
    }

    // ------------------------------------------------------------------------------------------
    // Queues
    // ------------------------------------------------------------------------------------------

    /**
     * Opens a count_review row. With a dedupe key a replay never adds a second row (D17).
     *
     * @param array<string, mixed> $detail
     */
    public function openCountReview(int $warehouseId, int $skuId, string $source, ?int $proposedQty, ?string $ref, array $detail, ?string $dedupeKey): void
    {
        $this->db->exec(
            'INSERT INTO count_review (warehouse_id, sku_id, source, proposed_qty, ref, detail, dedupe_key) VALUES (?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE id = id',
            [$warehouseId, $skuId, $source, $proposedQty, $ref === null ? null : substr($ref, 0, 191),
                $detail === [] ? null : Idempotency::json($detail), $dedupeKey === null ? null : substr($dedupeKey, 0, 191)],
        );
    }

    /** @param array<string, mixed> $m */
    private function reviewNegative(int $warehouseId, int $skuId, int $onHand, array $m): void
    {
        $open = $this->db->value(
            "SELECT id FROM count_review WHERE sku_id = ? AND warehouse_id = ? AND source = 'negative_on_hand' AND status = 'open' LIMIT 1",
            [$skuId, $warehouseId],
        );
        if ($open !== null) {
            return; // one open review per location+item (serialised by the balance lock we hold)
        }
        $this->openCountReview($warehouseId, $skuId, 'negative_on_hand', null, $m['order_ref'] ?? $m['doc_ref'] ?? null,
            ['on_hand' => $onHand, 'movement' => $m['type']], null);
    }

    // ------------------------------------------------------------------------------------------
    // Warehouses
    // ------------------------------------------------------------------------------------------

    public function isSellable(int $warehouseId): bool
    {
        $this->loadWarehouses();
        return $this->sellable[$warehouseId] ?? false;
    }

    public function warehouseId(string $code): int
    {
        $this->loadWarehouses();
        if (!isset($this->warehouseIds[$code])) {
            $this->sellable = null; // a warehouse added since: reload once
            $this->loadWarehouses();
        }
        if (!isset($this->warehouseIds[$code])) {
            throw new CwException('unknown_warehouse', "no warehouse {$code}", 422);
        }
        return $this->warehouseIds[$code];
    }

    private function loadWarehouses(): void
    {
        if ($this->sellable !== null) {
            return;
        }
        $this->sellable = [];
        $this->warehouseIds = [];
        foreach ($this->db->all('SELECT id, code, is_sellable FROM warehouse') as $w) {
            $this->sellable[(int) $w['id']] = (int) $w['is_sellable'] === 1;
            $this->warehouseIds[(string) $w['code']] = (int) $w['id'];
        }
    }
}
