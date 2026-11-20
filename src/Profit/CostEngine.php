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
 * what it covers is done, and every run reads the last OVERLAP_SECONDS again, so a booking committed late is found (and skipped when done).
 * An event never goes before the item's previous one (its `at` is moved up to it), so "the price at a moment" reads one history.
 */
final class CostEngine
{
    public const JOB = 'item_cost';
    /** Only what is at least this old is read (a booking still committing is left for the next run). */
    public const LAG_SECONDS = 120;
    /** Every run reads this far back again (bookings committed late). */
    public const OVERLAP_SECONDS = 600;
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
     * Writes the start (item_cost and one `start` event per item, at $startAt) and sets the job's mark there. Refused when items have a
     * price already (the start is once).
     *
     * @param array<int, array{e4: ?int, source: string}> $prices plan()'s
     */
    public function seed(array $prices, string $startAt): int
    {
        if ($this->db->value('SELECT 1 FROM item_cost LIMIT 1') !== null) {
            throw new \RuntimeException('the buying prices are started already (item_cost has rows): the start is done once');
        }
        $now = Clock::db(($this->clock)());
        $n = 0;
        foreach (array_chunk($prices, 500, true) as $chunk) {
            $this->db->transaction(function (Db $db) use ($chunk, $startAt, $now, &$n): void {
                foreach ($chunk as $sku => $p) {
                    $db->exec('INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                        [$sku, $p['e4'], $p['source'], $startAt, $now]);
                    $id = $db->insert('INSERT INTO item_cost_event (sku_id, at, kind, source_key, new_cost_e4, note) VALUES (?, ?, ?, ?, ?, ?)',
                        [$sku, $startAt, 'start', 'start:' . $sku, $p['e4'], 'start: ' . str_replace('_', ' ', $p['source'])]);
                    $db->exec('UPDATE item_cost SET last_event_id = ? WHERE sku_id = ?', [$id, $sku]);
                    $n++;
                }
            });
        }
        $this->db->exec('INSERT INTO profit_job (job, mark_at, ran_at, summary) VALUES (?, ?, ?, ?) AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at, '
            . 'ran_at = n.ran_at, summary = n.summary', [self::JOB, $startAt, $now, json_encode(['seeded' => $n], JSON_THROW_ON_ERROR)]);
        return $n;
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
        $channel = $this->db->value('SELECT id FROM channel WHERE code = ?', [$listingStore]);
        $links = [];
        foreach (array_chunk(array_map('strval', array_keys($byVariant)), 500) as $chunk) {
            foreach ($channel === null ? [] : $this->db->all("SELECT external_variant_id, sku_id, units_per_item FROM channel_listing WHERE channel_id = ? "
                . "AND status IN ('mapped', 'quarantined') AND sku_id IS NOT NULL AND external_variant_id IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                [(int) $channel, ...$chunk]) as $r) {
                $links[(string) $r['external_variant_id']] = [(int) $r['sku_id'], max(1, (int) $r['units_per_item'])];
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
     * Follows every delivery, invoice and credit note since the mark (less the overlap) up to LAG_SECONDS ago, in time order.
     *
     * @return array<string, mixed> what it did (`not_started` when the start was never written)
     */
    public function run(int $maxEvents = self::MAX_EVENTS): array
    {
        $mark = $this->db->value('SELECT mark_at FROM profit_job WHERE job = ?', [self::JOB]);
        if ($mark === null) {
            return ['not_started' => true];
        }
        $now = ($this->clock)();
        $cutoff = Clock::db($now->modify('-' . self::LAG_SECONDS . ' seconds'));
        $from = Clock::db(Clock::fromDb((string) $mark)->modify('-' . self::OVERLAP_SECONDS . ' seconds'));
        if ($cutoff <= (string) $mark) {
            return ['events' => 0, 'mark_at' => (string) $mark];
        }
        $events = [];
        $lo = $this->db->value('SELECT id FROM stock_ledger WHERE created_at >= ? ORDER BY created_at, id LIMIT 1', [$from]);
        if ($lo !== null) {
            foreach ($this->db->all("SELECT l.id, l.sku_id, l.qty_delta, CAST(l.unit_cost AS CHAR) AS unit_cost, l.document_id, l.document_line, l.doc_ref, "
                . "l.created_at, d.reverses_id FROM stock_ledger l JOIN document d ON d.id = l.document_id "
                . "WHERE l.id >= ? AND l.created_at >= ? AND l.created_at <= ? AND l.movement_type = 'goods_in' AND l.bucket = 'on_hand' "
                . "AND l.cost_source = 'document' AND l.unit_cost IS NOT NULL ORDER BY l.id", [(int) $lo, $from, $cutoff]) as $r) {
                $events[] = [(string) $r['created_at'], 0, (int) $r['id'], 'ledger', $r];
            }
        }
        foreach ($this->db->all("SELECT d.id, d.doc_type, d.number, d.posted_at, d.reverses_id FROM document d WHERE d.doc_type IN ('SINV', 'SCN') "
            . "AND d.status IN ('posted', 'reversed') AND d.posted_at >= ? AND d.posted_at <= ? ORDER BY d.posted_at, d.id", [$from, $cutoff]) as $r) {
            $events[] = [(string) $r['posted_at'], 1, (int) $r['id'], 'document', $r];
        }
        usort($events, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        $done = ['receipt' => 0, 'receipt_reversal' => 0, 'invoice' => 0, 'invoice_reversal' => 0, 'credit_note' => 0, 'credit_note_reversal' => 0, 'skipped' => 0];
        $written = 0;
        $newMark = $cutoff;
        foreach ($events as [$at, , , $kind, $r]) {
            if ($written >= $maxEvents) {
                $newMark = max((string) $mark, $at);
                break;
            }
            $made = $kind === 'ledger' ? $this->ledgerEvent($r) : $this->documentEvents($r);
            foreach ($made as $k) {
                $done[$k]++;
                $written += $k === 'skipped' ? 0 : 1;
            }
        }
        $summary = ['events' => $written, 'kinds' => array_filter($done), 'from' => $from, 'to' => $newMark];
        $this->db->exec('UPDATE profit_job SET mark_at = ?, ran_at = ?, summary = ? WHERE job = ?',
            [$newMark, Clock::db($now), json_encode($summary, JSON_THROW_ON_ERROR), self::JOB]);
        return $summary + ['mark_at' => $newMark];
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
        if ($r['reverses_id'] !== null && $qty < 0) {
            $orig = (int) $r['reverses_id'];
            $in = $line === null ? null : $this->db->value("SELECT price_e4 FROM item_cost_event WHERE kind = 'receipt' AND document_id = ? AND document_line = ? "
                . 'AND sku_id = ? ORDER BY id DESC LIMIT 1', [$orig, $line, $sku]);
            $price = $in === null ? $own : (int) $in;
            return [$this->write($sku, (string) $r['created_at'], 'receipt_reversal', 'ledger:' . (int) $r['id'], $doc, $line, (string) $r['doc_ref'], $orig, $line,
                -$qty, $onHand, $price, null, static fn (?int $c): ?int => ProfitMath::remove($c, $onHand, -$qty, $price), 'a delivery taken back')];
        }
        if ($qty <= 0) {
            return ['skipped'];
        }
        // A receipt of a final invoice's goods that were on their way: the invoice's landed price.
        $price = $own;
        $refDoc = null;
        $refLine = null;
        $note = 'a delivery';
        if ($line !== null) {
            $a = $this->db->one("SELECT a.sinv_document_id, a.line_no FROM invoice_arrival a JOIN document d ON d.id = a.sinv_document_id AND d.status = 'posted' "
                . "WHERE a.grn_document_id = ? AND a.grn_line_no = ? AND a.sku_id = ? AND a.state = 'received' ORDER BY a.sinv_document_id, a.line_no LIMIT 1",
                [$doc, $line, $sku]);
            if ($a !== null && !$this->isCopy((int) $a['sinv_document_id'])) {
                foreach ($this->landed((int) $a['sinv_document_id']) as $g) {
                    if ($g['line_no'] === (int) $a['line_no'] && $g['price_e4'] !== null) {
                        $price = $g['price_e4'];
                        $refDoc = (int) $a['sinv_document_id'];
                        $refLine = $g['line_no'];
                        $note = 'a delivery of an invoice\'s goods on their way, at the invoice\'s landed price';
                    }
                }
            }
        }
        return [$this->write($sku, (string) $r['created_at'], 'receipt', 'ledger:' . (int) $r['id'], $doc, $line, (string) $r['doc_ref'], $refDoc, $refLine,
            $qty, $onHand, $price, null, static fn (?int $c): ?int => ProfitMath::receive($c, $onHand, $qty, $price), $note)];
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
            $orig = (int) $d['reverses_id'];
            $out = [];
            foreach ($this->db->all("SELECT * FROM item_cost_event WHERE document_id = ? AND kind IN ('invoice', 'credit_note') ORDER BY id", [$orig]) as $e) {
                $units = (int) $e['units'];
                if ($units <= 0 || $e['price_e4'] === null) {
                    continue;
                }
                $sku = (int) $e['sku_id'];
                $credit = $e['kind'] === 'credit_note';
                if (!$credit && $e['booked_e4'] === null) {
                    continue;
                }
                $from = $credit ? 0 : (int) $e['price_e4'];
                $to = $credit ? (int) $e['price_e4'] : (int) $e['booked_e4'];
                $onHand = $this->onHandAt($sku, $at);
                $out[] = $this->write($sku, $at, $credit ? 'credit_note_reversal' : 'invoice_reversal', 'doc:' . $id . ':' . (int) $e['document_line'] . ':' . $sku,
                    $id, $e['document_line'] === null ? null : (int) $e['document_line'], $number, $orig, null, $units, $onHand, $to, $from,
                    static fn (?int $c): ?int => ProfitMath::correct($c, $onHand, $units, $from, $to), 'cancelled: ' . ($credit ? 'a credit note' : 'an invoice'));
            }
            return $out;
        }
        if ($this->isCopy($id)) {
            return [];
        }
        $h = $this->db->one('SELECT kind, debit_note_document_id FROM supplier_invoice WHERE document_id = ?', [$id]);
        if ($h === null) {
            return [];
        }
        if ($h['kind'] === 'credit_note' && $h['debit_note_document_id'] !== null) {
            return []; // the answer to our return: those units left at the average
        }
        $out = [];
        foreach ($this->landed($id) as $g) {
            if ($g['price_e4'] === null || $g['sku_id'] === null) {
                continue;
            }
            $sku = $g['sku_id'];
            $units = $g['units'];
            $onHand = $this->onHandAt($sku, $at);
            $key = 'doc:' . $id . ':' . $g['line_no'] . ':' . $sku;
            if ($h['kind'] === 'credit_note') {
                $per = $g['price_e4'];
                $out[] = $this->write($sku, $at, 'credit_note', $key, $id, $g['line_no'], $number, null, null, $units, $onHand, $per, null,
                    static fn (?int $c): ?int => ProfitMath::correct($c, $onHand, $units, $per, 0), 'a price credit');
                continue;
            }
            $booked = null;
            if ($g['grn'] !== null) {
                [$gd, $gl] = $g['grn'];
                $b = $this->db->value("SELECT price_e4 FROM item_cost_event WHERE ((kind = 'receipt' AND document_id = ? AND document_line = ?) "
                    . "OR (kind = 'invoice' AND ref_document_id = ? AND ref_line = ? AND booked_e4 IS NOT NULL)) AND sku_id = ? ORDER BY id DESC LIMIT 1",
                    [$gd, $gl, $gd, $gl, $sku]);
                $booked = $b !== null ? (int) $b
                    : ProfitMath::e4((string) ($this->db->value('SELECT CAST(unit_cost AS CHAR) FROM document_line WHERE document_id = ? AND line_no = ?', [$gd, $gl]) ?? '0'));
            }
            $price = $g['price_e4'];
            $out[] = $this->write($sku, $at, 'invoice', $key, $id, $g['line_no'], $number, $g['grn'][0] ?? null, $g['grn'][1] ?? null,
                $booked === null ? 0 : $units, $onHand, $price, $booked,
                static fn (?int $c): ?int => $booked === null ? $c : ProfitMath::correct($c, $onHand, $units, $booked, $price),
                $booked === null ? 'invoiced before its goods arrived (the delivery takes this price)' : 'invoice price wins');
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
     * Writes one event and the item's new price in a transaction of its own; `skipped` when that source was written before.
     *
     * @param \Closure(?int): ?int $move the item's price before => after
     */
    private function write(int $sku, string $at, string $kind, string $key, ?int $doc, ?int $line, ?string $docRef, ?int $refDoc, ?int $refLine, int $units,
        int $onHand, ?int $price, ?int $booked, \Closure $move, string $note): string
    {
        return $this->db->transaction(function (Db $db) use ($sku, $at, $kind, $key, $doc, $line, $docRef, $refDoc, $refLine, $units, $onHand, $price, $booked,
            $move, $note): string {
            if ($db->value('SELECT 1 FROM item_cost_event WHERE source_key = ?', [$key]) !== null) {
                return 'skipped';
            }
            $now = Clock::db(($this->clock)());
            $ic = $db->one('SELECT unit_cost_e4 FROM item_cost WHERE sku_id = ? FOR UPDATE', [$sku]);
            if ($ic === null) {
                // An item made after the start: it starts with no price, at its first event.
                $db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, NULL, 'none', ?, ?)", [$sku, $at, $now]);
                $ic = ['unit_cost_e4' => null];
            }
            $old = $ic['unit_cost_e4'] === null ? null : (int) $ic['unit_cost_e4'];
            $new = $move($old);
            $last = $db->value('SELECT at FROM item_cost_event WHERE sku_id = ? ORDER BY at DESC, id DESC LIMIT 1', [$sku]);
            $when = $last !== null && (string) $last > $at ? (string) $last : $at;
            $id = $db->insert('INSERT INTO item_cost_event (sku_id, at, kind, source_key, document_id, document_line, doc_ref, ref_document_id, ref_line, units, on_hand, '
                . 'price_e4, booked_e4, old_cost_e4, new_cost_e4, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$sku, $when, $kind, $key, $doc, $line, $docRef === null ? null : mb_substr($docRef, 0, 64), $refDoc, $refLine, $units, $onHand, $price, $booked, $old, $new,
                    mb_substr($note, 0, 255)]);
            $db->exec('UPDATE item_cost SET unit_cost_e4 = ?, last_event_id = ?, updated_at = ? WHERE sku_id = ?', [$new, $id, $now, $sku]);
            return $kind;
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
