<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Clock;
use CW\Db;
use CW\StockOps\CostHints;

/**
 * The buying price of every CW item (item_cost, with every move in item_cost_event; docs/decisions.md PR12-PR19): "invoice price wins"
 * (the owner, 15 Oct 2026), as a moving average per item. Nothing here touches report_item, the stock ledger, CostHints or any stock
 * screen, and nothing runs inside a booking's transaction: a booking can never fail because of the cost (the job reads what was booked).
 *
 * THE START (seed(), bin/seed_item_cost.php, once): ERPNext's own valuation when purchases moved to CW (Bin.valuation_rate; an item
 * code is "P" + the Vape and Go variant: the CW item through that store's match; several warehouses weighted by their quantity), else
 * CW's average so far (report_item), else the supplier's last price (an ERPNext purchase invoice copy, the supplier item's price, its
 * last order price), else none. Each item gets one `start` event at the start time (profit.cost_start, midnight UK).
 *
 * THEN, in time order (run(), bin/refresh_item_cost.php every 10 minutes; the seed runs it from the start):
 *  - a delivery (a goods receipt's goods_in row, its own unit price ex VAT) moves the average with its units; a receipt made for a final
 *    invoice whose goods were on their way takes that invoice's landed price; a delivery taken back takes its units out at the price
 *    they came in at;
 *  - a supplier invoice made final: each goods line's landed price (its amount, plus the invoice's carriage, duty and other charges spread
 *    over its goods lines by value) replaces, for the units it bills that are still on hand, the price they came in at;
 *  - a credit note that is a price credit (not the answer to our return: those units left at the average) lowers the units still on
 *    hand by what it credits;
 *  - an invoice or credit note reversed: its moves undone on the units still on hand.
 * Every other movement (sales, counts, adjustments, the 130,206 units ERPNext relayed without a cost, returns) comes in or goes out at
 * the price of the moment, which leaves the average where it is. Units on hand = every warehouse of ours, from the ledger as it stood.
 * A copy of an ERPNext purchase invoice never moves it (its stock came through the relay at ERPNext's cost, inside the start).
 *
 * Restartable: each event is its own small transaction, written once (item_cost_event.source_key); the job's mark only moves on after
 * what it covers is done, and every run reads the last OVERLAP_SECONDS again (never before the start: profit_job.floor_at), so a booking
 * committed late is found (and skipped when done). An event never goes before the item's previous one (its `at` is moved up to it), so
 * "the price at a moment" reads one history; an event whose own time (source_at) is older than the item's last one is written and the item's
 * history folded again in source order (ProfitMath::fold, a `replay` event: the Codex review of 92efa82, a6). The move of each event is
 * ProfitMath::step, the same rule the fold uses. An item made after the start gets its starting price on the next run (startNewItems).
 */
final class CostEngine
{
    public const JOB = 'item_cost';
    /** Only what is at least this old is read (a booking still committing is left for the next run). */
    public const LAG_SECONDS = 300;
    /** Every run reads this far back again (bookings committed late). */
    public const OVERLAP_SECONDS = 3600;
    public const MAX_EVENTS = 20_000;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    private readonly ProfitSettings $settings;
    /** @var list<int>|null */
    private ?array $own = null;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null, ?ProfitSettings $settings = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $this->settings = $settings ?? new ProfitSettings($db);
    }

    // ---- the start --------------------------------------------------------------------------------------------------------------------

    /**
     * The starting price of every item (nothing written).
     *
     * @param string $binTsv ERPNext's Bin as tab-separated text: a header row naming item_code, warehouse, actual_qty, valuation_rate
     * @return array{prices: array<int, array{e4: ?int, source: string}>, counts: array<string, int>, bin_rows: int, bin_unmatched: int}
     */
    public function plan(string $binTsv, string $listingStore): array
    {
        $erp = $this->binPrices($binTsv, $listingStore);
        $skus = array_map('intval', $this->db->column('SELECT id FROM sku ORDER BY id'));
        $prices = [];
        foreach ($skus as $s) {
            if (isset($erp['prices'][$s])) {
                $prices[$s] = ['e4' => $erp['prices'][$s], 'source' => 'erp_bin'];
            }
        }
        $rest = array_values(array_diff($skus, array_keys($prices)));
        foreach ($rest === [] ? [] : $this->cwAverage($rest) as $s => $e4) {
            $prices[$s] = ['e4' => $e4, 'source' => 'cw_average'];
        }
        $rest = array_values(array_diff($rest, array_keys($prices)));
        foreach ($rest === [] ? [] : $this->supplierPrices($rest) as $s => $e4) {
            $prices[$s] = ['e4' => $e4, 'source' => 'supplier_price'];
        }
        foreach (array_diff($rest, array_keys($prices)) as $s) {
            $prices[$s] = ['e4' => null, 'source' => 'none'];
        }
        ksort($prices);
        $counts = ['erp_bin' => 0, 'cw_average' => 0, 'supplier_price' => 0, 'none' => 0];
        foreach ($prices as $p) {
            $counts[$p['source']]++;
        }
        return ['prices' => $prices, 'counts' => $counts, 'bin_rows' => $erp['rows'], 'bin_unmatched' => $erp['unmatched']];
    }

    /**
     * Writes the start (item_cost and one `start` event per item, at $startAt) and sets the job's mark and floor there. Refused once the
     * start is done (the job has a mark). A start stopped half way (some batches written, no mark) goes on with the items not written yet
     * (the Codex review of 92efa82, b4).
     *
     * @param array<int, array{e4: ?int, source: string}> $prices plan()'s
     * @return int the items written by this call
     */
    public function seed(array $prices, string $startAt): int
    {
        if ($this->db->value('SELECT mark_at FROM profit_job WHERE job = ?', [self::JOB]) !== null) {
            throw new \RuntimeException('the buying prices are started already (the cost job has its mark): the start is done once');
        }
        $have = array_fill_keys(array_map('intval', $this->db->column('SELECT sku_id FROM item_cost')), true);
        $now = Clock::db(($this->clock)());
        $n = 0;
        foreach (array_chunk(array_diff_key($prices, $have), 500, true) as $chunk) {
            $this->db->transaction(function (Db $db) use ($chunk, $startAt, $now, &$n): void {
                foreach ($chunk as $sku => $p) {
                    $this->startItem($db, (int) $sku, $p['e4'], $p['source'], $startAt, $now);
                    $n++;
                }
            });
        }
        $this->db->exec('INSERT INTO profit_job (job, mark_at, floor_at, ran_at, summary) VALUES (?, ?, ?, ?, ?) AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at, '
            . 'floor_at = n.floor_at, ran_at = n.ran_at, summary = n.summary', [self::JOB, $startAt, $startAt, $now, json_encode(['seeded' => $n], JSON_THROW_ON_ERROR)]);
        return $n;
    }

    /** One item's start: its row and its `start` event (inside the caller's transaction; an item already started is left alone). */
    private function startItem(Db $db, int $sku, ?int $e4, string $source, string $at, string $now): void
    {
        if ($db->value('SELECT 1 FROM item_cost WHERE sku_id = ? FOR UPDATE', [$sku]) !== null) {
            return;
        }
        $db->exec('INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$sku, $e4, $source, $at, $now]);
        $id = $db->insert('INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4, note) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$sku, $at, $at, 'start', 'start:' . $sku, $e4, 'start: ' . str_replace('_', ' ', $source)]);
        $db->exec('UPDATE item_cost SET last_event_id = ? WHERE sku_id = ?', [$id, $sku]);
    }

    /**
     * @return array{prices: array<int, int>, rows: int, unmatched: int} item => price per CW unit (GBP x 10,000)
     */
    private function binPrices(string $tsv, string $listingStore): array
    {
        $rows = preg_split('/\r?\n/', trim($tsv)) ?: [];
        $head = array_map('trim', explode("\t", (string) array_shift($rows)));
        $col = array_flip($head);
        foreach (['item_code', 'actual_qty', 'valuation_rate'] as $need) {
            if (!isset($col[$need])) {
                throw new \InvalidArgumentException("the Bin file has no column {$need}");
            }
        }
        $placeholders = [];
        $ph = $this->db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'erp_opening.placeholder_costs'");
        foreach (explode(',', trim((string) json_decode((string) ($ph ?? '""'), true))) as $p) {
            if (trim($p) !== '' && ($e4 = ProfitMath::e4(trim($p))) !== null) {
                $placeholders[$e4] = true;
            }
        }
        $byVariant = [];
        $n = 0;
        foreach ($rows as $line) {
            if (trim($line) === '') {
                continue;
            }
            $f = explode("\t", $line);
            $n++;
            if (preg_match('/^P([0-9]{1,18})$/D', trim($f[$col['item_code']] ?? ''), $m) !== 1) {
                continue;
            }
            $rate = ProfitMath::e4(trim($f[$col['valuation_rate']] ?? ''));
            $qty = (int) round((float) trim($f[$col['actual_qty']] ?? '0'));
            if ($rate === null || $rate <= 0 || isset($placeholders[$rate])) {
                continue;
            }
            $byVariant[$m[1]][] = [$rate, $qty];
        }
        // An ERPNext item is the CW item the opening import makes it (ErpItemResolver: CW's ERPNext link first, else the variant its code
        // names through the store's match; a merged item, an unmatched or quarantined listing resolve to nothing; docs/decisions.md RB10).
        $ids = [];
        foreach (array_keys($byVariant) as $variant) {
            $ids[\CW\Ops\ErpOpeningRules::itemKey('P' . $variant)] = (string) $variant;
        }
        $resolver = new \CW\Ops\ErpItemResolver($this->db, $listingStore, $ids);
        $links = [];
        foreach (array_keys($byVariant) as $variant) {
            $r = $resolver->resolve('P' . $variant);
            if (isset($r['sku_id'])) {
                $links[(string) $variant] = [(int) $r['sku_id'], max(1, (int) $r['u'])];
            }
        }
        $acc = [];
        $unmatched = 0;
        foreach ($byVariant as $variant => $list) {
            if (!isset($links[(string) $variant])) {
                $unmatched++;
                continue;
            }
            [$sku, $u] = $links[(string) $variant];
            foreach ($list as [$rate, $qty]) {
                $acc[$sku][] = [ProfitMath::divRound($rate, $u), $qty * $u];
            }
        }
        $out = [];
        foreach ($acc as $sku => $list) {
            $q = 0;
            $v = 0;
            foreach ($list as [$e4, $units]) {
                if ($units > 0) {
                    $q += $units;
                    $v += $e4 * $units;
                }
            }
            $out[$sku] = $q > 0 ? ProfitMath::divRound($v, $q)
                : ProfitMath::divRound(array_sum(array_column($list, 0)), count($list));
        }
        return ['prices' => $out, 'rows' => $n, 'unmatched' => $unmatched];
    }

    /** @param list<int> $skus @return array<int, int> CW's average so far (report_item) */
    private function cwAverage(array $skus): array
    {
        $out = [];
        foreach (array_chunk($skus, 500) as $chunk) {
            foreach ($this->db->all('SELECT sku_id, in_units, CAST(in_value AS CHAR) AS v FROM report_item WHERE in_units > 0 AND sku_id IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $e4 = ProfitMath::e4(bcdiv((string) $r['v'], (string) $r['in_units'], 8));
                if ($e4 !== null && $e4 > 0) {
                    $out[(int) $r['sku_id']] = $e4;
                }
            }
        }
        return $out;
    }

    /** @param list<int> $skus @return array<int, int> the supplier's last price of one unit */
    private function supplierPrices(array $skus): array
    {
        $want = array_fill_keys($skus, true);
        $out = [];
        // The newest ERPNext purchase invoice line of the item (a copy CW keeps; never a debit note).
        foreach ($this->db->all("SELECT l.sku_id, CAST(l.net_amount AS CHAR) AS amount, l.units FROM erp_pi_line l JOIN erp_pi p ON p.id = l.erp_pi_id "
            . "WHERE l.sku_id IS NOT NULL AND l.units > 0 AND l.net_amount > 0 AND p.is_return = 0 AND p.docstatus = 1 ORDER BY p.posting_date, p.id, l.idx") as $r) {
            $s = (int) $r['sku_id'];
            if (isset($want[$s]) && ($e4 = ProfitMath::e4(bcdiv((string) $r['amount'], (string) $r['units'], 8))) !== null && $e4 > 0) {
                $out[$s] = $e4;
            }
        }
        $rest = array_values(array_diff($skus, array_keys($out)));
        foreach ($rest === [] ? [] : (new CostHints($this->db))->supplierPrice($rest) as $s => $p) {
            if (($e4 = ProfitMath::e4((string) $p)) !== null && $e4 > 0) {
                $out[$s] = $e4;
            }
        }
        $rest = array_values(array_diff($rest, array_keys($out)));
        foreach (array_chunk($rest, 500) as $chunk) {
            foreach ($this->db->all('SELECT sku_id, CAST(last_po_pack_price AS CHAR) AS p, units_per_pack FROM supplier_item WHERE last_po_pack_price IS NOT NULL '
                . 'AND sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') ORDER BY last_po_on, id', $chunk) as $r) {
                $e4 = ProfitMath::e4(bcdiv((string) $r['p'], (string) max(1, (int) $r['units_per_pack']), 8));
                if ($e4 !== null && $e4 > 0) {
                    $out[(int) $r['sku_id']] = $e4;
                }
            }
        }
        return $out;
    }

    // ---- the replay -------------------------------------------------------------------------------------------------------------------

    /** @return array{mark_at: ?string, ran_at: ?string, summary: mixed} */
    public function state(): array
    {
        $r = $this->db->one('SELECT mark_at, ran_at, CAST(summary AS CHAR) AS summary FROM profit_job WHERE job = ?', [self::JOB]);
        return ['mark_at' => $r['mark_at'] ?? null, 'ran_at' => $r['ran_at'] ?? null,
            'summary' => isset($r['summary']) ? json_decode((string) $r['summary'], true) : null];
    }

    /**
     * Follows every delivery, invoice and credit note since the mark (less the overlap, never before the start) up to LAG_SECONDS ago, in
     * time order; first gives an item made since the start its starting price.
     *
     * @return array<string, mixed> what it did (`not_started` when the start was never written)
     */
    public function run(int $maxEvents = self::MAX_EVENTS): array
    {
        $job = $this->db->one('SELECT mark_at, floor_at FROM profit_job WHERE job = ?', [self::JOB]);
        if ($job === null || $job['mark_at'] === null) {
            return ['not_started' => true];
        }
        $mark = (string) $job['mark_at'];
        $floor = $job['floor_at'] === null ? '' : (string) $job['floor_at'];
        $now = ($this->clock)();
        $cutoff = Clock::db($now->modify('-' . self::LAG_SECONDS . ' seconds'));
        if ($cutoff <= $mark) {
            return ['events' => 0, 'mark_at' => $mark];
        }
        $started = $this->startNewItems($floor === '' ? $mark : $floor);
        $from = max($floor, Clock::db(Clock::fromDb($mark)->modify('-' . self::OVERLAP_SECONDS . ' seconds')));
        $events = [];
        $lo = $this->db->value('SELECT id FROM stock_ledger WHERE created_at >= ? ORDER BY created_at, id LIMIT 1', [$from]);
        if ($lo !== null) {
            foreach ($this->db->all("SELECT l.id, l.sku_id, l.qty_delta, CAST(l.unit_cost AS CHAR) AS unit_cost, l.document_id, l.document_line, l.doc_ref, "
                . "l.created_at, d.reverses_id FROM stock_ledger l JOIN document d ON d.id = l.document_id JOIN warehouse w ON w.id = l.warehouse_id "
                . "WHERE l.id >= ? AND l.created_at >= ? AND l.created_at <= ? AND l.movement_type = 'goods_in' AND l.bucket = 'on_hand' "
                . "AND l.cost_source = 'document' AND l.unit_cost IS NOT NULL AND w.stock_owner = 'own' ORDER BY l.id", [(int) $lo, $from, $cutoff]) as $r) {
                $events[] = [(string) $r['created_at'], 0, (int) $r['id'], 'ledger', $r];
            }
        }
        foreach ($this->db->all("SELECT d.id, d.doc_type, d.number, d.posted_at, d.reverses_id FROM document d WHERE d.doc_type IN ('SINV', 'SCN') "
            . "AND d.status IN ('posted', 'reversed') AND d.posted_at >= ? AND d.posted_at <= ? ORDER BY d.posted_at, d.id", [$from, $cutoff]) as $r) {
            $events[] = [(string) $r['posted_at'], 1, (int) $r['id'], 'document', $r];
        }
        usort($events, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        $done = [];
        $written = 0;
        $newMark = $cutoff;
        foreach ($events as [$at, , , $kind, $r]) {
            if ($written >= $maxEvents) {
                $newMark = max($mark, $at);
                break;
            }
            foreach ($kind === 'ledger' ? $this->ledgerEvent($r) : $this->documentEvents($r) as $k) {
                $done[$k] = ($done[$k] ?? 0) + 1;
                $written += $k === 'skipped' ? 0 : 1;
            }
        }
        $summary = ['events' => $written, 'kinds' => $done, 'started' => $started, 'from' => $from, 'to' => $newMark];
        $this->db->exec('UPDATE profit_job SET mark_at = ?, ran_at = ?, summary = ? WHERE job = ?',
            [$newMark, Clock::db($now), json_encode($summary, JSON_THROW_ON_ERROR), self::JOB]);
        return $summary + ['mark_at' => $newMark];
    }

    /**
     * Items made since the start (no item_cost row): their starting price as the start gives it to an item ERPNext does not value (CW's
     * average so far, else the supplier's price, else none), from when the item was made (never before the start). The Codex review of
     * 92efa82, b5.
     */
    private function startNewItems(string $floor): int
    {
        $skus = [];
        foreach ($this->db->all('SELECT s.id, s.created_at FROM sku s LEFT JOIN item_cost c ON c.sku_id = s.id WHERE c.sku_id IS NULL ORDER BY s.id LIMIT 5000') as $r) {
            $skus[(int) $r['id']] = max($floor, (string) $r['created_at']);
        }
        if ($skus === []) {
            return 0;
        }
        $ids = array_keys($skus);
        $prices = [];
        foreach ($this->cwAverage($ids) as $s => $e4) {
            $prices[$s] = ['e4' => $e4, 'source' => 'cw_average'];
        }
        $rest = array_values(array_diff($ids, array_keys($prices)));
        foreach ($rest === [] ? [] : $this->supplierPrices($rest) as $s => $e4) {
            $prices[$s] = ['e4' => $e4, 'source' => 'supplier_price'];
        }
        $now = Clock::db(($this->clock)());
        $n = 0;
        foreach ($skus as $sku => $at) {
            $p = $prices[$sku] ?? ['e4' => null, 'source' => 'none'];
            $this->db->transaction(function (Db $db) use ($sku, $p, $at, $now): void {
                $this->startItem($db, $sku, $p['e4'], $p['source'], $at, $now);
            });
            $n++;
        }
        return $n;
    }

    /**
     * One goods_in row of a receipt (or of its reversal).
     *
     * @param array<string, mixed> $r
     * @return list<string> what was written (an event kind, or `skipped` when it was done before)
     */
    private function ledgerEvent(array $r): array
    {
        $sku = (int) $r['sku_id'];
        $qty = (int) $r['qty_delta'];
        $own = ProfitMath::e4((string) $r['unit_cost']) ?? 0;
        $onHand = $this->onHandBefore($sku, (int) $r['id']);
        $doc = (int) $r['document_id'];
        $line = $r['document_line'] === null ? null : (int) $r['document_line'];
        $at = (string) $r['created_at'];
        if ($r['reverses_id'] !== null && $qty < 0) {
            $orig = (int) $r['reverses_id'];
            $in = $line === null ? null : $this->db->value("SELECT price_e4 FROM item_cost_event WHERE kind = 'receipt' AND document_id = ? AND document_line = ? "
                . 'AND sku_id = ? ORDER BY id DESC LIMIT 1', [$orig, $line, $sku]);
            $price = $in === null ? $own : (int) $in;
            return [$this->write(['sku_id' => $sku, 'source_at' => $at, 'kind' => 'receipt_reversal', 'source_key' => 'ledger:' . (int) $r['id'], 'document_id' => $doc,
                'document_line' => $line, 'doc_ref' => (string) $r['doc_ref'], 'ref_document_id' => $orig, 'ref_line' => $line, 'units' => -$qty, 'on_hand' => $onHand,
                'price_e4' => $price, 'booked_e4' => null, 'note' => 'a delivery taken back'])];
        }
        if ($qty <= 0) {
            return ['skipped'];
        }
        // A receipt made for a final invoice (its goods were on their way): that invoice's landed price, less what its credit notes took off
        // before the goods came. Found from what never changes (goods_receipt_invoice, the lines of both), not from the arrival row, which a
        // reversal of the receipt opens again (the Codex review of 92efa82, a13, a5).
        $price = $own;
        $refDoc = null;
        $refLine = null;
        $note = 'a delivery';
        $inv = $line === null ? null : $this->invoiceLineOf($doc, $line, $sku, $at);
        if ($inv !== null) {
            foreach ($this->landed($inv[0]) as $g) {
                if ($g['line_no'] === $inv[1] && $g['price_e4'] !== null) {
                    $credits = 0;
                    foreach ($this->db->all("SELECT e.booked_e4, e.document_id FROM item_cost_event e WHERE e.kind = 'credit_note' AND e.deferred = 1 AND e.ref_document_id = ? "
                        . 'AND e.ref_line = ? AND e.sku_id = ?', [$inv[0], $inv[1], $sku]) as $c) {
                        $undone = $this->db->value("SELECT 1 FROM item_cost_event WHERE kind = 'credit_note_reversal' AND ref_document_id = ? AND sku_id = ? AND source_at <= ? "
                            . 'LIMIT 1', [(int) $c['document_id'], $sku, $at]);
                        $credits += $undone === null ? (int) $c['booked_e4'] : 0;
                    }
                    $price = max(0, $g['price_e4'] - $credits);
                    $refDoc = $inv[0];
                    $refLine = $inv[1];
                    $note = 'a delivery of an invoice\'s goods on their way, at the invoice\'s landed price' . ($credits > 0 ? ', less its credits' : '');
                }
            }
        }
        return [$this->write(['sku_id' => $sku, 'source_at' => $at, 'kind' => 'receipt', 'source_key' => 'ledger:' . (int) $r['id'], 'document_id' => $doc,
            'document_line' => $line, 'doc_ref' => (string) $r['doc_ref'], 'ref_document_id' => $refDoc, 'ref_line' => $refLine, 'units' => $qty, 'on_hand' => $onHand,
            'price_e4' => $price, 'booked_e4' => null, 'note' => $note])];
    }

    /**
     * The final invoice line a receipt's line received (a receipt made from an invoice, pack J), when that invoice was final before the
     * receipt: the n-th line of the item on the receipt is the n-th goods line of the item on the invoice (the receipt is made line for line
     * from it; a line left over shares the item's last line, as ArrivalBooking gives them).
     *
     * @return array{0: int, 1: int}|null [invoice document id, its line]
     */
    private function invoiceLineOf(int $grn, int $line, int $sku, string $at): ?array
    {
        $inv = $this->db->one("SELECT g.sinv_document_id AS id FROM goods_receipt_invoice g JOIN document d ON d.id = g.sinv_document_id "
            . "AND d.status IN ('posted', 'reversed') AND d.posted_at <= ? WHERE g.grn_document_id = ?", [$at, $grn]);
        if ($inv === null || $this->isCopy((int) $inv['id'])) {
            return null;
        }
        $mine = array_map('intval', $this->db->column('SELECT line_no FROM document_line WHERE document_id = ? AND sku_id = ? ORDER BY line_no', [$grn, $sku]));
        $theirs = array_map('intval', $this->db->column("SELECT dl.line_no FROM document_line dl JOIN sinv_line sl ON sl.document_id = dl.document_id AND sl.line_no = dl.line_no "
            . "WHERE dl.document_id = ? AND dl.sku_id = ? AND sl.kind = 'goods' ORDER BY dl.line_no", [(int) $inv['id'], $sku]));
        $i = array_search($line, $mine, true);
        if ($i === false || $theirs === []) {
            return null;
        }
        return [(int) $inv['id'], $theirs[min((int) $i, count($theirs) - 1)]];
    }

    /**
     * A supplier invoice or credit note made final, or the reversal of one.
     *
     * @param array<string, mixed> $d the document row
     * @return list<string>
     */
    private function documentEvents(array $d): array
    {
        $id = (int) $d['id'];
        $at = (string) $d['posted_at'];
        $number = (string) $d['number'];
        if ($d['reverses_id'] !== null) {
            return $this->reversalEvents($id, (int) $d['reverses_id'], $at, $number);
        }
        if ($this->isCopy($id)) {
            return [];
        }
        $h = $this->db->one('SELECT kind, credits_document_id, debit_note_document_id FROM supplier_invoice WHERE document_id = ?', [$id]);
        if ($h === null) {
            return [];
        }
        if ($h['kind'] === 'credit_note' && $h['debit_note_document_id'] !== null) {
            return []; // the answer to our return: those units left at the average
        }
        $credit = $h['kind'] === 'credit_note';
        $creditOf = [];
        if ($credit) {
            foreach ($this->db->all('SELECT line_no, credit_of_line_no FROM sinv_line WHERE document_id = ?', [$id]) as $c) {
                $creditOf[(int) $c['line_no']] = $c['credit_of_line_no'] === null ? null : (int) $c['credit_of_line_no'];
            }
        }
        $out = [];
        foreach ($this->landed($id) as $g) {
            if ($g['price_e4'] === null || $g['sku_id'] === null) {
                continue;
            }
            $sku = $g['sku_id'];
            $onHand = $this->onHandAt($sku, $at);
            $base = ['sku_id' => $sku, 'source_at' => $at, 'source_key' => 'doc:' . $id . ':' . $g['line_no'] . ':' . $sku, 'document_id' => $id,
                'document_line' => $g['line_no'], 'doc_ref' => $number, 'on_hand' => $onHand];
            if ($credit) {
                // A price credit: what it takes off a unit, on the credited units still on hand. Of an invoice whose goods have not come yet
                // (no delivery named, none received from it so far): deferred; their receipt takes it in (the Codex review of 92efa82, a5).
                $inv = $h['credits_document_id'] === null ? null : (int) $h['credits_document_id'];
                $invLine = $creditOf[$g['line_no']] ?? null;
                $deferred = $inv !== null && $invLine !== null && $g['grn'] === null
                    && $this->db->value("SELECT 1 FROM item_cost_event WHERE kind = 'receipt' AND ref_document_id = ? AND ref_line = ? AND sku_id = ? LIMIT 1",
                        [$inv, $invLine, $sku]) === null;
                $out[] = $this->write($base + ['kind' => 'credit_note', 'ref_document_id' => $inv, 'ref_line' => $invLine,
                    'units' => $deferred ? $g['units'] : ProfitMath::reach($g['units'], $onHand), 'price_e4' => 0, 'booked_e4' => $g['price_e4'], 'deferred' => $deferred ? 1 : 0,
                    'note' => $deferred ? 'a price credit of goods still on their way: their receipt takes it in' : 'a price credit']);
                continue;
            }
            $booked = null;
            if ($g['grn'] !== null) {
                // The price the units of that delivery line came in at: the receipt's own (an earlier invoice of other units of the same
                // line moved only its own units: the Codex review of 92efa82, a2).
                [$gd, $gl] = $g['grn'];
                $b = $this->db->value("SELECT price_e4 FROM item_cost_event WHERE kind = 'receipt' AND document_id = ? AND document_line = ? AND sku_id = ? ORDER BY id LIMIT 1",
                    [$gd, $gl, $sku]);
                $booked = $b !== null ? (int) $b
                    : ProfitMath::e4((string) ($this->db->value('SELECT CAST(unit_cost AS CHAR) FROM document_line WHERE document_id = ? AND line_no = ?', [$gd, $gl]) ?? '0'));
            }
            $out[] = $this->write($base + ['kind' => 'invoice', 'ref_document_id' => $g['grn'][0] ?? null, 'ref_line' => $g['grn'][1] ?? null,
                'units' => $booked === null ? 0 : ProfitMath::reach($g['units'], $onHand), 'price_e4' => $g['price_e4'], 'booked_e4' => $booked,
                'note' => $booked === null ? 'invoiced before its goods arrived (the delivery takes this price)' : 'invoice price wins']);
        }
        return $out;
    }

    /**
     * An invoice or a credit note cancelled: each of its moves undone on the units still on hand, by what it really moved a unit (its
     * value change over the units it reached: nothing when it moved nothing, the Codex review of 92efa82, a3). A deferred credit is undone
     * only once a receipt took it in; before that, the receipt leaves it out.
     *
     * @return list<string>
     */
    private function reversalEvents(int $id, int $orig, string $at, string $number): array
    {
        $out = [];
        foreach ($this->db->all("SELECT * FROM item_cost_event WHERE document_id = ? AND kind IN ('invoice', 'credit_note') ORDER BY id", [$orig]) as $e) {
            $sku = (int) $e['sku_id'];
            $units = (int) $e['units'];
            $credit = $e['kind'] === 'credit_note';
            if ((int) $e['deferred'] === 1) {
                $took = $this->db->value("SELECT source_at FROM item_cost_event WHERE kind = 'receipt' AND ref_document_id = ? AND ref_line = ? AND sku_id = ? "
                    . 'ORDER BY id LIMIT 1', [(int) $e['ref_document_id'], (int) $e['ref_line'], $sku]);
                $per = $took !== null && (string) $took <= $at ? -(int) $e['booked_e4'] : 0;
            } elseif ($units > 0 && $e['old_cost_e4'] !== null && $e['new_cost_e4'] !== null && (int) $e['on_hand'] > 0) {
                $per = ProfitMath::divRound(((int) $e['new_cost_e4'] - (int) $e['old_cost_e4']) * (int) $e['on_hand'], $units);
            } else {
                $per = 0;
            }
            $onHand = $this->onHandAt($sku, $at);
            $out[] = $this->write(['sku_id' => $sku, 'source_at' => $at, 'kind' => $credit ? 'credit_note_reversal' : 'invoice_reversal',
                'source_key' => 'doc:' . $id . ':' . (int) $e['document_line'] . ':' . $sku, 'document_id' => $id,
                'document_line' => $e['document_line'] === null ? null : (int) $e['document_line'], 'doc_ref' => $number, 'ref_document_id' => $orig, 'ref_line' => null,
                'units' => $per === 0 ? 0 : ProfitMath::reach($units, $onHand), 'on_hand' => $onHand, 'price_e4' => -$per, 'booked_e4' => 0,
                'note' => 'cancelled: ' . ($credit ? 'a credit note' : 'an invoice')]);
        }
        return $out;
    }

    /**
     * The goods lines of a supplier invoice or credit note with their landed price: the line's amount plus its share of every other line
     * (carriage, duty, service, other) spread by value, per unit.
     *
     * @return list<array{line_no: int, sku_id: ?int, units: int, price_e4: ?int, grn: ?array{0: int, 1: int}}>
     */
    public function landed(int $documentId): array
    {
        $goods = [];
        $charges = 0;
        foreach ($this->db->all('SELECT dl.line_no, dl.sku_id, dl.qty, CAST(dl.amount AS CHAR) AS amount, sl.kind, sl.grn_document_id, sl.grn_line_no '
            . 'FROM document_line dl JOIN sinv_line sl ON sl.document_id = dl.document_id AND sl.line_no = dl.line_no WHERE dl.document_id = ? ORDER BY dl.line_no',
            [$documentId]) as $r) {
            $e2 = ProfitMath::divRound(ProfitMath::e4((string) ($r['amount'] ?? '0')) ?? 0, 100);
            if ($r['kind'] === 'goods') {
                $goods[(int) $r['line_no']] = ['line_no' => (int) $r['line_no'], 'sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'],
                    'units' => abs((int) $r['qty']), 'e2' => abs($e2),
                    'grn' => $r['grn_document_id'] === null ? null : [(int) $r['grn_document_id'], (int) $r['grn_line_no']]];
            } else {
                $charges += $e2;
            }
        }
        $shares = ProfitMath::spread($charges, array_map(static fn (array $g): int => $g['e2'], $goods));
        $out = [];
        foreach ($goods as $no => $g) {
            $landed = $g['e2'] + ($shares[$no] ?? 0);
            $out[] = ['line_no' => $g['line_no'], 'sku_id' => $g['sku_id'], 'units' => $g['units'],
                'price_e4' => $g['units'] > 0 ? max(0, ProfitMath::divRound($landed * 100, $g['units'])) : null, 'grn' => $g['grn']];
        }
        return $out;
    }

    private function isCopy(int $documentId): bool
    {
        return $this->db->value("SELECT 1 FROM supplier_invoice WHERE document_id = ? AND origin = 'erpnext'", [$documentId]) !== null;
    }

    /**
     * Writes one event and the item's new price in a transaction of its own; `skipped` when that source was written before. The move is
     * ProfitMath::step (the rule a replay folds with). An event whose source is older than the item's last one (a booking that committed
     * late) is written with its own source time, and the item's history is folded again in source order (replayItem: the Codex review of
     * 92efa82, a6).
     *
     * @param array<string, mixed> $e the event's columns (sku_id, source_at, kind, source_key, document_id, document_line, doc_ref,
     *        ref_document_id, ref_line, units, on_hand, price_e4, booked_e4, deferred?, note)
     */
    private function write(array $e): string
    {
        $late = false;
        $kind = $this->db->transaction(function (Db $db) use ($e, &$late): string {
            if ($db->value('SELECT 1 FROM item_cost_event WHERE source_key = ?', [$e['source_key']]) !== null) {
                return 'skipped';
            }
            $sku = (int) $e['sku_id'];
            $now = Clock::db(($this->clock)());
            $ic = $db->one('SELECT unit_cost_e4 FROM item_cost WHERE sku_id = ? FOR UPDATE', [$sku]);
            if ($ic === null) {
                // An item the start did not reach yet (made a moment ago): it starts with no price, at its first event.
                $this->startItem($db, $sku, null, 'none', (string) $e['source_at'], $now);
                $ic = ['unit_cost_e4' => null];
            }
            $old = $ic['unit_cost_e4'] === null ? null : (int) $ic['unit_cost_e4'];
            $new = ProfitMath::step($old, $e);
            $last = $db->one('SELECT at, source_at FROM item_cost_event WHERE sku_id = ? ORDER BY at DESC, id DESC LIMIT 1', [$sku]);
            $at = (string) $e['source_at'];
            $when = $last !== null && (string) $last['at'] > $at ? (string) $last['at'] : $at;
            $late = $last !== null && (string) $db->value('SELECT MAX(source_at) FROM item_cost_event WHERE sku_id = ?', [$sku]) > $at;
            $id = $db->insert('INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, document_id, document_line, doc_ref, ref_document_id, ref_line, '
                . 'units, on_hand, price_e4, booked_e4, deferred, old_cost_e4, new_cost_e4, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$sku, $when, $at, $e['kind'], $e['source_key'], $e['document_id'] ?? null, $e['document_line'] ?? null,
                    isset($e['doc_ref']) ? mb_substr((string) $e['doc_ref'], 0, 64) : null, $e['ref_document_id'] ?? null, $e['ref_line'] ?? null, (int) $e['units'],
                    $e['on_hand'], $e['price_e4'], $e['booked_e4'], (int) ($e['deferred'] ?? 0), $old, $new, mb_substr((string) $e['note'], 0, 255)]);
            $db->exec('UPDATE item_cost SET unit_cost_e4 = ?, last_event_id = ?, updated_at = ? WHERE sku_id = ?', [$new, $id, $now, $sku]);
            return (string) $e['kind'];
        });
        if ($late) {
            $this->replayItem((int) $e['sku_id']);
        }
        return $kind;
    }

    /**
     * The item's history folded again in its sources' order (ProfitMath::fold); when that gives another price than the item has, a `replay`
     * event sets it (who reads "the price at a moment" from then on reads the folded price).
     *
     * @return ?int the item's price after it
     */
    public function replayItem(int $sku): ?int
    {
        return $this->db->transaction(function (Db $db) use ($sku): ?int {
            $ic = $db->one('SELECT unit_cost_e4 FROM item_cost WHERE sku_id = ? FOR UPDATE', [$sku]);
            if ($ic === null) {
                return null;
            }
            $events = $db->all("SELECT * FROM item_cost_event WHERE sku_id = ? AND kind <> 'replay' ORDER BY source_at, id", [$sku]);
            $folded = ProfitMath::fold($events);
            $old = $ic['unit_cost_e4'] === null ? null : (int) $ic['unit_cost_e4'];
            if ($folded === $old) {
                return $old;
            }
            $now = Clock::db(($this->clock)());
            $last = (string) $db->value('SELECT MAX(at) FROM item_cost_event WHERE sku_id = ?', [$sku]);
            $n = (int) $db->value("SELECT COUNT(*) FROM item_cost_event WHERE sku_id = ? AND kind = 'replay'", [$sku]);
            $id = $db->insert("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, units, old_cost_e4, new_cost_e4, note) VALUES (?, ?, ?, 'replay', ?, 0, ?, ?, ?)",
                [$sku, max($last, $now), max($last, $now), 'replay:' . $sku . ':' . ($n + 1), $old, $folded, 'an event came late: the history folded again in its own order']);
            $db->exec('UPDATE item_cost SET unit_cost_e4 = ?, last_event_id = ?, updated_at = ? WHERE sku_id = ?', [$folded, $id, $now, $sku]);
            return $folded;
        });
    }

    /** Units of $sku on hand in our warehouses just before ledger row $ledgerId. */
    public function onHandBefore(int $sku, int $ledgerId): int
    {
        $sum = 0;
        foreach ($this->ownWarehouses() as $wh) {
            $sum += (int) ($this->db->value("SELECT balance_after FROM stock_ledger WHERE warehouse_id = ? AND sku_id = ? AND bucket = 'on_hand' AND id < ? "
                . 'ORDER BY id DESC LIMIT 1', [$wh, $sku, $ledgerId]) ?? 0);
        }
        return $sum;
    }

    /** Units of $sku on hand in our warehouses at a moment (every ledger row booked up to then). */
    public function onHandAt(int $sku, string $at): int
    {
        $next = $this->db->value('SELECT id FROM stock_ledger WHERE created_at > ? ORDER BY created_at, id LIMIT 1', [$at]);
        return $this->onHandBefore($sku, $next === null ? PHP_INT_MAX : (int) $next);
    }

    /** @return list<int> */
    private function ownWarehouses(): array
    {
        return $this->own ??= array_map('intval', $this->db->column("SELECT id FROM warehouse WHERE stock_owner = 'own' ORDER BY id"));
    }
}
