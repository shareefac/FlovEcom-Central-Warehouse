<?php

declare(strict_types=1);

namespace CW;

/**
 * Stock movements that are not sales (plan §3 POST /v1/movements, §8.2, §9):
 *
 *   goods_in, transfer_in                        on_hand + |qty| x u
 *   supplier_return, erp_sale, write_off,
 *   transfer_out, trade_sale (documents only)    on_hand - |qty| x u   (the type sets the sign)
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
 *
 * Cost (C0, I1): a staff line of a COST_TYPES movement may carry `unit_cost` (GBP per central unit,
 * at most 6 decimals); it is stored on the ledger row (cost_source `manual`) and enters the canonical
 * request only when sent, so the hash of every request without one is unchanged (I6). Sites cannot
 * send costs. Document postings (I-2 onwards) book through bookForDocument()/reverseDocument() inside
 * their own transaction, with the document's id and line numbers on every row (I2, I7).
 */
final class Movements
{
    public const TYPES = ['goods_in', 'supplier_return', 'erp_sale', 'adjustment', 'count', 'write_off', 'transfer_out', 'transfer_in'];
    private const PLUS = ['goods_in', 'transfer_in'];
    private const MINUS = ['supplier_return', 'erp_sale', 'write_off', 'transfer_out', 'trade_sale'];
    private const SUSPENSE = ['goods_in', 'supplier_return', 'erp_sale'];
    /** The only types a site may be granted (the ERP relay, §9). */
    public const CHANNEL_TYPES = ['goods_in', 'supplier_return', 'erp_sale'];
    /** Types whose lines may carry a unit cost (I1): the stock that comes in or goes out at a known price. */
    public const COST_TYPES = Stock::COST_TYPES;
    /**
     * Types a document posting books (bookForDocument, I7). `trade_sale` (stock issued on a trade or
     * inter-site document, IM11) is document-only: record() refuses it with 400 bad_type (I8).
     */
    public const DOCUMENT_TYPES = ['goods_in', 'supplier_return', 'adjustment', 'count', 'write_off', 'transfer_out', 'transfer_in', 'trade_sale'];
    /** The largest unit cost stock_ledger.unit_cost (DECIMAL(14,6)) holds. */
    public const MAX_COST = '99999999.999999';
    /** Movements per document posting (bookForDocument). */
    public const MAX_DOCUMENT_MOVEMENTS = 20;
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
        $mv = $this->prepare($caller, $req, false);
        return $this->idem->run(
            $caller, $idemKey, 'movement.' . $mv['type'], '/v1/movements', $mv['request'], 'movement', $mv['doc_ref'] ?? $mv['type'],
            function (Db $db) use ($caller, $mv, $idemKey): OpResult {
                if ($mv['counted_at'] !== null) {
                    // After the idempotency lookup, so a stored answer is still replayed (R5).
                    Clock::checkWindow($mv['counted_at'], 'counted_at', $this->now(), $mv['backdated'] ? self::BACKDATED_MAX_AGE_SEC : self::COUNT_MAX_AGE_SEC);
                }
                $booked = $this->book($caller, [$mv], $idemKey, null, true);
                if ($booked['unresolved'] !== []) {
                    $bad = array_map(static fn (array $u): array => ['line_index' => $u['line_index'], 'reason' => $u['reason']], $booked['unresolved']);
                    return OpResult::of(422, ['error' => 'unresolved_line', 'type' => $mv['type'], 'lines' => $bad]);
                }
                return OpResult::of(200, ['result' => 'recorded', 'type' => $mv['type'], 'doc_ref' => $mv['doc_ref'], 'lines' => $booked['movements'][0]['lines']]);
            },
        );
    }

    /**
     * Books every stock movement of ONE document posting inside the caller's transaction (I7): one lock(), one flush().
     *
     * Staff only (403 staff_only). Every ledger row carries doc_ref = the document number, document_id, document_line
     * (NULL on a count row summed from several lines, whose note lists them), idem_key = $opKey, the line's cost with
     * cost_source `document`, and the caller as actor. No idempotency row and no audit row: the posting audits itself, and
     * its own transaction (document row lock + status) is what makes it happen once; a second call for a document that
     * already booked stock is refused (LogicException). An unresolved line refuses the whole posting (422
     * unresolved_line, nothing booked): a document never parks lines in goods_in_suspense.
     *
     * @param array{document_id: int, doc_ref: string} $doc   doc_ref = the document number
     * @param list<array{type: string, warehouse?: string, counted_at?: string, backdated?: bool, note?: string,
     *                   lines: list<array{document_line: int, sku_id?: int, sku_code?: string, qty: int, unit_cost?: mixed, warehouse?: string}>}> $movements
     * @return list<array{type: string, lines: list<array<string, mixed>>}> per movement, record()'s per-line results
     *         (line_index = document_line)
     */
    public function bookForDocument(Caller $caller, array $doc, string $opKey, array $movements): array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('bookForDocument() runs inside the transaction that posts the document');
        }
        if ($caller->isChannel()) {
            throw new CwException('staff_only', 'documents are posted by staff', 403);
        }
        $doc = self::checkDoc($doc);
        Idempotency::checkKey($opKey);
        if ($this->db->value('SELECT 1 FROM stock_ledger WHERE document_id = ? LIMIT 1', [$doc['document_id']]) !== null) {
            // A posting books its stock in ONE call (one lock(), one flush()); a second call is a handler bug.
            throw new \LogicException("document {$doc['document_id']} has already booked stock: a posting books it once");
        }
        if ($movements === [] || !array_is_list($movements) || count($movements) > self::MAX_DOCUMENT_MOVEMENTS) {
            throw new CwException('bad_movements', 'a document posting books 1 to ' . self::MAX_DOCUMENT_MOVEMENTS . ' movements', 400);
        }
        $prepared = [];
        $lines = 0;
        foreach ($movements as $j => $mv) {
            if (!is_array($mv)) {
                throw new CwException('bad_movements', "movements[{$j}] must be an object", 400);
            }
            $p = $this->prepare($caller, ['doc_ref' => $doc['doc_ref']] + $mv, true);
            if ($p['counted_at'] !== null) {
                Clock::checkWindow($p['counted_at'], "movements[{$j}].counted_at", $this->now(),
                    $p['backdated'] ? self::BACKDATED_MAX_AGE_SEC : self::COUNT_MAX_AGE_SEC);
            }
            $lines += count($p['lines']);
            $prepared[] = $p;
        }
        if ($lines > self::MAX_LINES) {
            throw new CwException('bad_lines', 'a document posting books at most ' . self::MAX_LINES . " lines ({$lines} sent)", 400);
        }
        $booked = $this->book($caller, $prepared, $opKey, $doc, false);
        if ($booked['unresolved'] !== []) {
            $bad = array_map(static fn (array $u): array => ['movement' => $u['movement'], 'type' => $prepared[$u['movement']]['type'],
                'document_line' => $u['line_index'], 'reason' => $u['reason']], $booked['unresolved']);
            throw new CwException('unresolved_line', 'the document names items CW does not know: nothing was booked', 422, ['lines' => $bad]);
        }
        return $booked['movements'];
    }

    /**
     * Books the exact negation of every stock_ledger row of $originalDocumentId under the reversal document, in one lock()/flush() (I7).
     *
     * Each mirrored row keeps the original's bucket, movement_type, unit_cost, cost_source and document_line, with
     * effective_at = now and the note "reversal of <original doc_ref>"; a zero row (a count that found no difference) is
     * mirrored too. counted_at is never touched: a reversed count leaves the location's count time (a recount is IM2's).
     * Refuses (LogicException) outside a transaction and when the reversal document already booked stock, so a retry can
     * never negate twice. Staff only (403 staff_only).
     *
     * @param array{document_id: int, doc_ref: string} $reversal  @return int rows booked (0 = the original moved no stock)
     */
    public function reverseDocument(Caller $caller, int $originalDocumentId, array $reversal, string $opKey): int
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('reverseDocument() runs inside the transaction that posts the reversal');
        }
        if ($caller->isChannel()) {
            throw new CwException('staff_only', 'documents are reversed by staff', 403);
        }
        $reversal = self::checkDoc($reversal);
        Idempotency::checkKey($opKey);
        if ($originalDocumentId <= 0 || $originalDocumentId === $reversal['document_id']) {
            throw new \InvalidArgumentException('a reversal is another document than the one it reverses');
        }
        if ($this->db->value('SELECT 1 FROM stock_ledger WHERE document_id = ? LIMIT 1', [$reversal['document_id']]) !== null) {
            throw new \LogicException("document {$reversal['document_id']} has already booked stock: a reversal is booked once");
        }
        $rows = $this->db->all(
            'SELECT warehouse_id, sku_id, bucket, qty_delta, movement_type, doc_ref, unit_cost, cost_source, document_line '
            . 'FROM stock_ledger WHERE document_id = ? ORDER BY id',
            [$originalDocumentId],
        );
        if ($rows === []) {
            return 0;
        }
        $this->stock->lock(array_map(static fn (array $r): array => [(int) $r['warehouse_id'], (int) $r['sku_id']], $rows));
        $now = Clock::db($this->now());
        foreach ($rows as $r) {
            $m = ['type' => (string) $r['movement_type'], 'actor' => $caller->actor, 'channel_id' => null, 'doc_ref' => $reversal['doc_ref'],
                'idem_key' => $opKey, 'effective_at' => $now, 'note' => 'reversal of ' . ($r['doc_ref'] ?? 'document ' . $originalDocumentId),
                'document_id' => $reversal['document_id'], 'document_line' => $r['document_line'] === null ? null : (int) $r['document_line']];
            if ($r['unit_cost'] !== null) {
                $m += ['unit_cost' => self::normaliseCost((string) $r['unit_cost'], 'unit_cost'), 'cost_source' => (string) $r['cost_source']];
            }
            $this->stock->apply((int) $r['warehouse_id'], (int) $r['sku_id'], (string) $r['bucket'], -(int) $r['qty_delta'], $m, true);
        }
        $this->stock->flush();
        return count($rows);
    }

    /**
     * The canonical form of a unit cost: GBP per central unit with exactly 6 decimals ("1.250000"). Accepts an int
     * 0..99,999,999, a decimal string without sign, exponent, padding or grouping (at most 8 integer digits and 6
     * decimals), or a finite float >= 0 with at most 6 decimals. Anything else is 400 bad_cost.
     */
    public static function normaliseCost(mixed $v, string $field): string
    {
        $bad = static fn (): CwException => new CwException('bad_cost',
            "{$field} must be a non-negative amount of at most " . self::MAX_COST . ' with at most 6 decimals', 400, ['field' => $field]);
        if (is_int($v)) {
            if ($v < 0 || $v > 99_999_999) {
                throw $bad();
            }
            return $v . '.000000';
        }
        if (is_string($v)) {
            if (preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,6}))?$/D', $v, $m) !== 1) {
                throw $bad();
            }
            return $m[1] . '.' . str_pad($m[2] ?? '', 6, '0');
        }
        if (is_float($v)) {
            if (!is_finite($v) || $v < 0 || $v >= 100_000_000 || abs($v * 1e6 - round($v * 1e6)) >= 1e-6) {
                throw $bad();
            }
            return sprintf('%.6F', abs($v)); // abs: -0.0 prints as "-0.000000"
        }
        throw $bad();
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
     * Checks one movement request and builds what book() needs, plus `request`: the canonical form the idempotency hash
     * covers (unchanged by C0 for every request without a unit cost, I6). For a document movement ($forDocument) the
     * types are DOCUMENT_TYPES, doc_ref is the document number (set by bookForDocument), and every line needs its
     * document_line and names its item by sku_id or sku_code.
     *
     * @param array<string, mixed> $req
     * @return array{type: string, doc_ref: ?string, doc_type: ?string, warehouse: ?string, counted_at: ?\DateTimeImmutable,
     *               backdated: bool, note: ?string, lines: list<array<string, mixed>>, request: array<string, mixed>}
     */
    private function prepare(Caller $caller, array $req, bool $forDocument): array
    {
        $types = $forDocument ? self::DOCUMENT_TYPES : self::TYPES;
        $type = $req['type'] ?? null;
        if (!is_string($type) || !in_array($type, $types, true)) {
            throw new CwException('bad_type', 'type must be one of ' . implode(', ', $types), 400);
        }
        $this->checkAllowed($caller, $type);
        $docRef = self::optString($req['doc_ref'] ?? null, 'doc_ref', 191);
        if ($docRef === null && in_array($type, self::SUSPENSE, true)) {
            throw new CwException('doc_ref_required', "{$type} needs doc_ref (the document name)", 400);
        }
        $docType = $forDocument ? null : self::optString($req['doc_type'] ?? null, 'doc_type', 32) ?? (self::DOC_TYPES[$type] ?? null);
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
        $lines = self::normaliseLines($type, $req['lines'] ?? null, $caller->isChannel(), $forDocument);

        $request = ['type' => $type, 'doc_ref' => $docRef, 'doc_type' => $docType, 'warehouse' => $whCode,
            'counted_at' => $countedAt === null ? null : Clock::db($countedAt), 'note' => $note, 'lines' => $lines]
            + ($backdated ? ['backdated' => true] : []);
        return ['type' => $type, 'doc_ref' => $docRef, 'doc_type' => $docType, 'warehouse' => $whCode, 'counted_at' => $countedAt,
            'backdated' => $backdated, 'note' => $note, 'lines' => $lines, 'request' => $request];
    }

    /**
     * Books prepared movements, in order, with ONE Stock::lock() of every (warehouse, item) they touch and ONE flush()
     * (I7: a second lock() would take balances after the first operation's value clocks and feed clock).
     *
     * Every line of every movement is resolved first. When a line does not resolve and its movement may not park it in
     * goods_in_suspense ($allowSuspense and a SUSPENSE type: record() only), nothing is locked or booked and the
     * unresolved list comes back. Count lines of one (warehouse, item) are summed and must carry the same cost
     * (400 cost_conflict otherwise; "no cost" is a cost of its own).
     *
     * @param list<array<string, mixed>> $movements prepare() results
     * @param array{document_id: int, doc_ref: string}|null $doc the posting document (bookForDocument), null for record()
     * @return array{movements: list<array{type: string, lines: list<array<string, mixed>>}>,
     *               unresolved: list<array{movement: int, line_index: int, reason: string}>}
     */
    private function book(Caller $caller, array $movements, string $opKey, ?array $doc, bool $allowSuspense): array
    {
        $resolved = [];
        $unresolved = [];
        foreach ($movements as $j => $mv) {
            $defaultWh = $mv['warehouse'] !== null ? $this->stock->warehouseId($mv['warehouse']) : $this->defaultWarehouse($caller);
            $resolved[$j] = [];
            $unresolved[$j] = [];
            foreach ($mv['lines'] as $i => $line) {
                $wh = isset($line['warehouse']) ? $this->stock->warehouseId($line['warehouse']) : $defaultWh;
                $r = $this->resolve($caller, $line);
                if (isset($r['reason'])) {
                    $unresolved[$j][$i] = $r['reason'];
                } else {
                    $resolved[$j][$i] = ['warehouse_id' => $wh, 'sku_id' => $r['sku_id'], 'code' => $r['code'], 'u' => $r['u']];
                }
            }
        }
        $refused = [];
        foreach ($unresolved as $j => $bad) {
            if ($allowSuspense && in_array($movements[$j]['type'], self::SUSPENSE, true)) {
                continue;
            }
            foreach ($bad as $i => $reason) {
                $refused[] = ['movement' => $j, 'line_index' => $movements[$j]['lines'][$i]['line_index'], 'reason' => $reason];
            }
        }
        if ($refused !== []) {
            return ['movements' => [], 'unresolved' => $refused];
        }
        foreach ($movements as $j => $mv) {
            if ($mv['type'] !== 'count') {
                continue;
            }
            $costs = [];
            foreach ($resolved[$j] as $i => $r) {
                $k = Stock::key($r['warehouse_id'], $r['sku_id']);
                $cost = $mv['lines'][$i]['unit_cost'] ?? null;
                if (array_key_exists($k, $costs) && $costs[$k] !== $cost) {
                    throw new CwException('cost_conflict', "count lines of {$r['code']} at one location carry different unit costs", 400,
                        ['line_index' => $mv['lines'][$i]['line_index'], 'sku_code' => $r['code']]);
                }
                $costs[$k] = $cost;
            }
        }

        $pairs = [];
        foreach ($resolved as $rs) {
            foreach ($rs as $r) {
                $pairs[] = [$r['warehouse_id'], $r['sku_id']];
            }
        }
        $this->stock->lock($pairs);
        $now = Clock::db($this->now());
        $costSource = $doc === null ? 'manual' : 'document';
        $result = [];
        foreach ($movements as $j => $mv) {
            $type = $mv['type'];
            $lines = $mv['lines'];
            $m = ['type' => $type, 'actor' => $caller->actor, 'channel_id' => $caller->channelId, 'doc_ref' => $mv['doc_ref'],
                'idem_key' => $opKey, 'effective_at' => $mv['counted_at'] === null ? $now : Clock::db($mv['counted_at']), 'note' => $mv['note']]
                + ($doc === null ? [] : ['document_id' => $doc['document_id']]);
            $out = [];
            if ($type === 'count') {
                assert($mv['counted_at'] instanceof \DateTimeImmutable);
                // Lines of one item at one location are summed (several shelf spots).
                $counted = [];
                $cost = [];
                $from = [];
                foreach ($resolved[$j] as $i => $r) {
                    $k = Stock::key($r['warehouse_id'], $r['sku_id']);
                    $counted[$k] = ($counted[$k] ?? 0) + $lines[$i]['qty'] * $r['u'];
                    $cost[$k] = $lines[$i]['unit_cost'] ?? null;
                    $from[$k][] = $lines[$i]['line_index'];
                }
                $results = [];
                foreach ($counted as $k => $qty) {
                    [$wh, $sku] = array_map('intval', explode(':', $k));
                    $row = $m + self::costOf($cost[$k], $costSource);
                    $suffix = null;
                    if ($doc !== null) {
                        $row['document_line'] = count($from[$k]) === 1 ? $from[$k][0] : null;
                        $suffix = count($from[$k]) === 1 ? null : 'lines ' . implode(',', $from[$k]);
                    }
                    $results[$k] = $this->count($wh, $sku, $qty, $mv['counted_at'], $row, $mv['doc_ref'], $suffix);
                }
                foreach ($resolved[$j] as $i => $r) {
                    $out[] = ['line_index' => $lines[$i]['line_index'], 'sku_code' => $r['code']]
                        + $results[Stock::key($r['warehouse_id'], $r['sku_id'])];
                }
            } else {
                foreach ($resolved[$j] as $i => $r) {
                    $q = $lines[$i]['qty'] * $r['u'];
                    $delta = match (true) {
                        in_array($type, self::PLUS, true) => abs($q),
                        in_array($type, self::MINUS, true) => -abs($q),
                        default => $q, // adjustment: signed
                    };
                    $row = ['note' => self::lineNote($mv['note'], $lines[$i]['line_index'])] + self::costOf($lines[$i]['unit_cost'] ?? null, $costSource)
                        + ($doc === null ? [] : ['document_line' => $lines[$i]['line_index']]) + $m;
                    $after = $this->stock->apply($r['warehouse_id'], $r['sku_id'], 'on_hand', $delta, $row);
                    $out[] = ['line_index' => $lines[$i]['line_index'], 'result' => 'booked', 'sku_code' => $r['code'],
                        'delta' => $delta, 'on_hand' => $after];
                }
            }
            foreach ($unresolved[$j] as $i => $reason) {
                $out[] = $this->suspend($caller, $mv, $lines[$i], $reason);
            }
            usort($out, static fn (array $a, array $b): int => $a['line_index'] <=> $b['line_index']);
            $result[] = ['type' => $type, 'lines' => $out];
        }
        $this->stock->flush();
        return ['movements' => $result, 'unresolved' => []];
    }

    /**
     * Parks an unresolved relay line in goods_in_suspense (deduplicated by document + line index, §9).
     *
     * @param array<string, mixed> $mv a prepare() result
     * @param array<string, mixed> $line
     * @return array{line_index: int, result: string, reason: string}
     */
    private function suspend(Caller $caller, array $mv, array $line, string $reason): array
    {
        $type = $mv['type'];
        $sign = in_array($type, self::PLUS, true) ? 1 : -1;
        $this->db->exec(
            'INSERT INTO goods_in_suspense (source, channel_id, doc_type, doc_ref, line_index, movement_type, qty, external_variant_id, '
            . 'erp_item_code, barcode, description, reason, payload, dedupe_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE id = id',
            [$caller->isChannel() ? 'erp_relay' : 'cw_screen', $caller->channelId, $mv['doc_type'] ?? $type, $mv['doc_ref'], $line['line_index'], $type,
                $sign * abs($line['qty']), $line['variant_id'] ?? null, $line['erp_item_code'] ?? null, $line['barcode'] ?? null,
                $line['description'] ?? null, $reason, Idempotency::json($line),
                mb_strcut("{$type}:{$mv['doc_ref']}#{$line['line_index']}", 0, 191, 'UTF-8')],
        );
        return ['line_index' => $line['line_index'], 'result' => 'suspense', 'reason' => $reason];
    }

    /** @return array{unit_cost?: string, cost_source?: string} the cost keys of a Stock::apply() row */
    private static function costOf(?string $cost, string $source): array
    {
        return $cost === null ? [] : ['unit_cost' => $cost, 'cost_source' => $source];
    }

    /**
     * @param array<string, mixed> $doc
     * @return array{document_id: int, doc_ref: string}
     */
    private static function checkDoc(array $doc): array
    {
        $id = $doc['document_id'] ?? null;
        $ref = $doc['doc_ref'] ?? null;
        if (!is_int($id) || $id <= 0 || !is_string($ref) || trim($ref) === '' || strlen($ref) > 191) {
            throw new \InvalidArgumentException('a document is {document_id: positive int, doc_ref: its number (1-191 bytes)}');
        }
        return ['document_id' => $id, 'doc_ref' => trim($ref)];
    }

    /**
     * §8.2: on_hand = counted − (net on_hand of ships/unships already applied whose effective
     * time is after counted_at). A count older than the location's last count is ignored.
     *
     * @param array<string, mixed> $m
     * @param ?string $noteSuffix appended to the row's note (a document count summed from several lines lists them)
     * @return array{result: string, delta?: int, on_hand: int, counted?: int}
     */
    private function count(int $wh, int $sku, int $counted, \DateTimeImmutable $countedAt, array $m, ?string $docRef, ?string $noteSuffix = null): array
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
            ['note' => "counted={$counted} ships_after={$shipsAfter}" . ($noteSuffix === null ? '' : ' ' . $noteSuffix)] + $m, true);
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

    /**
     * Checks and normalises the lines of one movement. `unit_cost` is kept only when sent (a null counts as not sent),
     * as its canonical 6-dp string, so the canonical request of every line without one is what it was before C0 (I6).
     * A document line ($forDocument) needs document_line (1..MAX_LINE_INDEX, unique in its movement), which becomes its
     * line_index, and names its item by sku_id or sku_code.
     *
     * @return list<array<string, mixed>>
     */
    private static function normaliseLines(string $type, mixed $lines, bool $fromSite, bool $forDocument): array
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
            if ($forDocument && !isset($n['sku_id']) && !isset($n['sku_code'])) {
                throw new CwException('bad_lines', "lines[{$i}] of a document names its item by sku_id or sku_code", 400);
            }
            $qty = $line['qty'] ?? null;
            if (!is_int($qty) || ($type === 'count' ? $qty < 0 : $qty === 0) || abs($qty) > 10_000_000) {
                throw new CwException('bad_lines', $type === 'count' ? "lines[{$i}].qty (counted) must be >= 0" : "lines[{$i}].qty must be a non-zero integer", 400);
            }
            $n['qty'] = $qty;
            if ($forDocument) {
                $idx = $line['document_line'] ?? null;
                if (!is_int($idx) || $idx < 1 || $idx > self::MAX_LINE_INDEX || isset($indexes[$idx])) {
                    throw new CwException('bad_lines', "lines[{$i}].document_line must be a unique integer from 1 to " . self::MAX_LINE_INDEX, 400);
                }
            } else {
                $idx = $line['line_index'] ?? $i;
                if (!is_int($idx) || $idx < 0 || $idx > self::MAX_LINE_INDEX || isset($indexes[$idx])) {
                    throw new CwException('bad_lines', "lines[{$i}].line_index must be a unique integer from 0 to " . self::MAX_LINE_INDEX, 400);
                }
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
            if (isset($line['unit_cost'])) {
                if ($fromSite || !in_array($type, self::COST_TYPES, true)) {
                    throw new CwException('cost_not_allowed', $fromSite
                        ? 'a site cannot send unit costs: costs come from CW documents (I1)'
                        : "{$type} lines carry no unit cost (it is valued at the average cost, IM8)", 400, ['field' => "lines[{$i}].unit_cost"]);
                }
                $n['unit_cost'] = self::normaliseCost($line['unit_cost'], "lines[{$i}].unit_cost");
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
