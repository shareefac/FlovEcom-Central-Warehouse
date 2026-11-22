<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Clock;
use CW\Db;
use CW\Reports\ReportRefresh;
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
 * Restartable: each event is its own small transaction, written once in its item's timeline (item_cost_event.source_key); the job's mark
 * only moves on after what it covers is done, and every run reads the last OVERLAP_SECONDS again (never before the start: profit_job.floor_at),
 * so a booking committed late is found (and skipped when done). Each item's history in force (item_cost.timeline) is always in time order:
 * an event read in order is written by ProfitMath::step on the price before it; an event older than the item's last one (late, recovered, the
 * cancellation of one) is never put in at its place or adjusted in place: the item's whole history is written again from its start, in time
 * order, as its next timeline, each event worked out again by the same rules as of its own time (rebuild(), PR79); earlier timelines are kept.
 * An item made after the start gets its starting price on the next run, just before its first delivery (startNewItems: CW's average of what
 * came in with a price before it, else the supplier's price; PR59, PR65). Each document read whole is marked (item_cost_document); one not
 * marked a day after it left the window is read again (all of them at night: PR57); a cancellation reads the document it cancels first
 * (PR68). Two invoice lines received on one receipt line share what came by the arrival matching (PR66). Units on hand are read in each
 * item's commit order (stock_value_seq), every operation whole (PR67, PR80).
 */
final class CostEngine
{
    public const JOB = 'item_cost';
    /** Only what is at least this old is read (a booking still committing is left for the next run). */
    public const LAG_SECONDS = 300;
    /** Every run reads this far back again (bookings committed late). */
    public const OVERLAP_SECONDS = 3600;
    public const MAX_EVENTS = 20_000;
    /** Documents read again after they left the window, per run. */
    public const UNREAD_PER_RUN = 500;
    /** ... and at night (--reconcile: since the start). */
    public const UNREAD_NIGHTLY = 20_000;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    private readonly ProfitSettings $settings;
    /** @var list<int>|null */
    private ?array $own = null;
    /** The start of the buying prices (profit_job.floor_at), read once. */
    private ?string $floor = null;
    /** @var array<int, int> item => the timeline a rebuild of it is writing (rebuild()) */
    private array $timelineOf = [];
    /** The item a rebuild is writing the history of (null: none). */
    private ?int $rebuilding = null;
    /** Events the rebuilds since the run last looked have written (a run counts them against its limit). */
    private int $rebuildWrites = 0;
    /** @var array<int, array<int, int>> item => [place in its commit order => units on hand there] (levelAt) */
    private array $levels = [];

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null, ?ProfitSettings $settings = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $this->settings = $settings ?? new ProfitSettings($db);
    }

    // ---- the start --------------------------------------------------------------------------------------------------------------------

    /**
     * The starting price of every item (nothing written), as of $startAt (the start's own moment, profit.cost_start; default: now).
     *
     * REPLAY-SAFE (the final review of 6d59eda, c-HIGH1 / a-M5 / b-HIGH3; docs/decisions.md PR99): the start is followed by the replay of every
     * delivery, invoice and credit note since $startAt, so no starting price may already hold one of them. ERPNext's Bin valuation (frozen
     * when purchases moved to CW) first; else CW's own average of what came in with a price BEFORE $startAt (never today's report_item: it
     * holds the deliveries the replay adds again); else the supplier's last price known before $startAt (an ERPNext purchase invoice posted
     * before it, a supplier item's price or order price dated before it); else none: the item's first priced delivery sets its price. Every
     * input is of before $startAt, so a start stopped half way and run again prices its later batches exactly as its first.
     *
     * The Bin file is checked whole first: a row that does not have the header's columns, or whose quantity or valuation is blank or not a
     * number, is BROKEN; `broken` names each (row number, item code, why) unless $allowSkips names it (its item code, or "row:<n>"): those are
     * left out and listed in `skipped`. bin/seed_item_cost.php writes nothing while one is broken.
     *
     * @param string $binTsv ERPNext's Bin as tab-separated text: a header row naming item_code, warehouse, actual_qty, valuation_rate
     * @param list<string> $allowSkips item codes (or row:<n>) whose broken rows may be left out
     * @param ?int $expectRows the export's own count of its rows: a file with another count is cut short or grown (one broken entry, row 0)
     * @return array{prices: array<int, array{e4: ?int, source: string}>, counts: array<string, int>, bin_rows: int, bin_unmatched: int,
     *         broken: list<array{row: int, item_code: string, why: string}>, skipped: list<array{row: int, item_code: string, why: string}>}
     */
    public function plan(string $binTsv, string $listingStore, ?string $startAt = null, array $allowSkips = [], ?int $expectRows = null): array
    {
        // Without a start (a look at today's figures): every input up to now. With one (bin/seed_item_cost.php always gives it): only what was
        // known before it.
        $bounded = $startAt !== null;
        $startAt ??= Clock::db(($this->clock)());
        $erp = $this->binPrices($binTsv, $listingStore, $allowSkips, $expectRows);
        // An item made at or after the start is not in it: the job starts it just before its first delivery (startNewItems: what came in
        // before that, PR65), never with an empty start at the start's moment (the Codex check of c48683e). Unless ERPNext's Bin prices it
        // (a CW item made since for an ERPNext item that had its valuation at the start): that price is its start (the Codex check of
        // 2b718e7: never an unknown cost where ERPNext knew £4).
        $skus = array_map('intval', $this->db->column('SELECT id FROM sku ORDER BY id'));
        $late = $bounded ? array_fill_keys(array_map('intval', $this->db->column('SELECT id FROM sku WHERE created_at >= ?', [$startAt])), true) : [];
        $prices = [];
        foreach ($skus as $s) {
            if (isset($erp['prices'][$s])) {
                $prices[$s] = ['e4' => $erp['prices'][$s], 'source' => 'erp_bin'];
            }
        }
        $skus = array_values(array_filter($skus, static fn (int $s): bool => isset($prices[$s]) || !isset($late[$s])));
        $rest = array_values(array_diff($skus, array_keys($prices)));
        foreach ($rest === [] ? [] : $this->cwAverageBefore($rest, $startAt) as $s => $e4) {
            $prices[$s] = ['e4' => $e4, 'source' => 'cw_average'];
        }
        $rest = array_values(array_diff($rest, array_keys($prices)));
        foreach ($rest === [] ? [] : $this->supplierPrices($rest, $bounded ? $startAt : null) as $s => $e4) {
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
        return ['prices' => $prices, 'counts' => $counts, 'bin_rows' => $erp['rows'], 'bin_unmatched' => $erp['unmatched'], 'broken' => $erp['broken'],
            'skipped' => $erp['skipped']];
    }

    /**
     * Writes the start (item_cost and one `start` event per item, at $startAt) and sets the job's mark and floor there, in ONE transaction.
     * Refused once the start is done (the job has a mark). Items already started (an item the job met first) are left as they are.
     *
     * @param array<int, array{e4: ?int, source: string}> $prices plan()'s
     * @return int the items written by this call
     */
    public function seed(array $prices, string $startAt): int
    {
        if ($this->db->value('SELECT mark_at FROM profit_job WHERE job = ?', [self::JOB]) !== null) {
            throw new \RuntimeException('the buying prices are started already (the cost job has its mark): the start is done once');
        }
        $now = Clock::db(($this->clock)());
        // ONE transaction, the mark with it: a start is written whole or not at all, so a start run again is priced from one set of inputs
        // (never half from one run and half from another: the Codex check of c48683e; a few thousand items, a few seconds, new rows only).
        return $this->db->transaction(function (Db $db) use ($prices, $startAt, $now): int {
            $have = array_fill_keys(array_map('intval', $db->column('SELECT sku_id FROM item_cost')), true);
            $n = 0;
            foreach (array_diff_key($prices, $have) as $sku => $p) {
                $this->startItem($db, (int) $sku, $p['e4'], $p['source'], $startAt, $now);
                $n++;
            }
            $db->exec('INSERT INTO profit_job (job, mark_at, floor_at, ran_at, summary) VALUES (?, ?, ?, ?, ?) AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at, '
                . 'floor_at = n.floor_at, ran_at = n.ran_at, summary = n.summary', [self::JOB, $startAt, $startAt, $now, json_encode(['seeded' => $n], JSON_THROW_ON_ERROR)]);
            return $n;
        });
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
     * @param list<string> $allowSkips item codes (or row:<n>) whose broken rows are left out
     * @return array{prices: array<int, int>, rows: int, unmatched: int, broken: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     *         item => price per CW unit (GBP x 10,000)
     */
    private function binPrices(string $tsv, string $listingStore, array $allowSkips = [], ?int $expectRows = null): array
    {
        $rows = preg_split('/\r?\n/', trim($tsv, "\r\n")) ?: []; // a last row's empty last column is a column (never trimmed away)
        $head = array_map('trim', explode("\t", (string) array_shift($rows)));
        $col = array_flip($head);
        // The warehouse too: an item is valued over its warehouses, each once (the second final review of 3c31d94, b-M6, PR144).
        foreach (['item_code', 'warehouse', 'actual_qty', 'valuation_rate'] as $need) {
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
        $broken = [];
        $skipped = [];
        $allow = array_fill_keys(array_map('trim', $allowSkips), true);
        $number = '/^-?[0-9]+(\.[0-9]+)?$/D';
        /** @var array<string, int> item code + warehouse => the row that had it first */
        $seen = [];
        foreach ($rows as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $f = explode("\t", $line);
            $n++;
            $row = $i + 2; // the file's line number (the header is line 1)
            $code = trim($f[$col['item_code']] ?? '');
            // A broken row (the final review of 6d59eda, b-HIGH3; PR99): never skipped without a word, never read as a number it is not.
            $where = trim($f[$col['warehouse']] ?? '');
            // An item and warehouse twice: one copied over another warehouse's row (10 at £2 in A twice where B had 10 at £6) would pass a
            // count of rows and give £2 for £4 (b-M6, PR144); refused, whatever --rows says.
            $twice = $code !== '' && $where !== '' && isset($seen[$code . "\t" . $where]) ? $seen[$code . "\t" . $where] : null;
            $why = match (true) {
                count($f) !== count($head) => 'has ' . count($f) . ' columns, not ' . count($head),
                $code === '' => 'has no item_code',
                $where === '' => 'has no warehouse',
                $twice !== null => "holds its item and warehouse twice (row {$twice} has them already)",
                preg_match($number, trim($f[$col['actual_qty']] ?? '')) !== 1 => 'its actual_qty is blank or not a number',
                preg_match($number, trim($f[$col['valuation_rate']] ?? '')) !== 1 => 'its valuation_rate is blank or not a number',
                default => null,
            };
            if ($code !== '' && $where !== '' && $twice === null) {
                $seen[$code . "\t" . $where] = $row;
            }
            if ($why !== null) {
                $entry = ['row' => $row, 'item_code' => mb_substr($code, 0, 40), 'why' => $why];
                if (($code !== '' && isset($allow[$code])) || isset($allow['row:' . $row])) {
                    $skipped[] = $entry;
                } else {
                    $broken[] = $entry;
                }
                continue;
            }
            if (preg_match('/^P([0-9]{1,18})$/D', $code, $m) !== 1) {
                continue;
            }
            $rate = ProfitMath::e4(trim($f[$col['valuation_rate']]));
            $qty = (int) round((float) trim($f[$col['actual_qty']]));
            if ($rate === null || $rate <= 0 || isset($placeholders[$rate])) {
                continue;
            }
            $byVariant[$m[1]][] = [$rate, $qty];
        }
        if ($expectRows !== null && $expectRows !== $n) {
            $broken[] = ['row' => 0, 'item_code' => '', 'why' => "is one of {$n} rows, not the {$expectRows} the export counted (the file is cut short or grown)"];
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
        return ['prices' => $out, 'rows' => $n, 'unmatched' => $unmatched, 'broken' => $broken, 'skipped' => $skipped];
    }

    /**
     * CW's average price of each item (CostHints::average's rule: every unit that came in with a price) of what was booked before $before:
     * by the ledger's booking time, the clock the replay chooses its deliveries by (a delivery booked before the start and made final after it
     * is in the opening, never in neither: the Codex check of c48683e), read through the items' value sequence (PR99).
     *
     * @param list<int> $skus
     * @return array<int, int>
     */
    private function cwAverageBefore(array $skus, string $before): array
    {
        $out = [];
        foreach (array_chunk($skus, 500) as $chunk) {
            foreach ($this->db->all('SELECT s.sku_id, CAST(SUM(l.qty_delta * l.unit_cost) AS CHAR) AS v, SUM(l.qty_delta) AS q FROM stock_value_seq s '
                . 'JOIN stock_ledger l ON l.id = s.stock_ledger_id WHERE s.sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') '
                . 'AND l.unit_cost IS NOT NULL AND l.qty_delta > 0 AND l.created_at < ? GROUP BY s.sku_id', [...$chunk, $before]) as $r) {
                if ((int) $r['q'] <= 0) {
                    continue;
                }
                $e4 = ProfitMath::e4(bcdiv((string) $r['v'], (string) $r['q'], 8));
                if ($e4 !== null && $e4 > 0) {
                    $out[(int) $r['sku_id']] = $e4;
                }
            }
        }
        return $out;
    }

    /**
     * The supplier's last price of one unit; with $before (the start: PR99) only prices known before it (an ERPNext purchase invoice posted
     * before its day, a supplier item's price or order price dated before it), never one a later invoice set.
     *
     * @param list<int> $skus
     * @return array<int, int>
     */
    private function supplierPrices(array $skus, ?string $before = null): array
    {
        if ($before !== null) {
            return $this->supplierPricesBefore($skus, \CW\Reports\ReportRefresh::ukDay($before));
        }
        $out = [];
        // The newest ERPNext purchase invoice line of each item asked for (a copy CW keeps; never a debit note; a unit price of at least a
        // hundredth of a penny), a chunk of items at a time, read by the items' own index (ix_erp_pi_line_sku, 0074) and only that line of each
        // sent back (the Codex review of 57d98ad and re-check of 6b8a7ee, N10).
        foreach (array_chunk($skus, 500) as $chunk) {
            foreach ($this->db->all('SELECT t.sku_id, t.amount, t.units FROM (SELECT l.sku_id, CAST(l.net_amount AS CHAR) AS amount, l.units, ROW_NUMBER() OVER '
                . '(PARTITION BY l.sku_id ORDER BY p.posting_date DESC, p.id DESC, l.idx DESC) AS n FROM erp_pi_line l JOIN erp_pi p ON p.id = l.erp_pi_id '
                . 'WHERE l.sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') AND l.units > 0 AND l.net_amount > 0 AND l.net_amount * 20000 >= l.units '
                . 'AND p.is_return = 0 AND p.docstatus = 1) t WHERE t.n = 1', $chunk) as $r) {
                if (($e4 = ProfitMath::e4(bcdiv((string) $r['amount'], (string) $r['units'], 8))) !== null && $e4 > 0) {
                    $out[(int) $r['sku_id']] = $e4;
                }
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

    /**
     * supplierPrices() as known before the UK day $day (the start).
     *
     * @param list<int> $skus
     * @return array<int, int>
     */
    private function supplierPricesBefore(array $skus, string $day): array
    {
        $out = [];
        foreach (array_chunk($skus, 500) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->db->all('SELECT t.sku_id, t.amount, t.units FROM (SELECT l.sku_id, CAST(l.net_amount AS CHAR) AS amount, l.units, ROW_NUMBER() OVER '
                . '(PARTITION BY l.sku_id ORDER BY p.posting_date DESC, p.id DESC, l.idx DESC) AS n FROM erp_pi_line l JOIN erp_pi p ON p.id = l.erp_pi_id '
                . "WHERE l.sku_id IN ({$in}) AND l.units > 0 AND l.net_amount > 0 AND l.net_amount * 20000 >= l.units "
                . 'AND p.is_return = 0 AND p.docstatus = 1 AND p.posting_date < ?) t WHERE t.n = 1', [...$chunk, $day]) as $r) {
                if (($e4 = ProfitMath::e4(bcdiv((string) $r['amount'], (string) $r['units'], 8))) !== null && $e4 > 0) {
                    $out[(int) $r['sku_id']] = $e4;
                }
            }
            $rest = array_values(array_diff($chunk, array_keys($out)));
            if ($rest === []) {
                continue;
            }
            $in = implode(', ', array_fill(0, count($rest), '?'));
            // The newest price dated before the day wins (rows read oldest first, the last one kept).
            foreach ($this->db->all("SELECT sku_id, CAST(last_pack_price AS CHAR) AS p, units_per_pack, last_price_on AS on_day FROM supplier_item WHERE sku_id IN ({$in}) "
                . 'AND last_pack_price IS NOT NULL AND last_price_on IS NOT NULL AND last_price_on < ? '
                . "UNION ALL SELECT sku_id, CAST(last_po_pack_price AS CHAR) AS p, units_per_pack, last_po_on AS on_day FROM supplier_item WHERE sku_id IN ({$in}) "
                . 'AND last_po_pack_price IS NOT NULL AND last_po_on IS NOT NULL AND last_po_on < ? ORDER BY on_day', [...$rest, $day, ...$rest, $day]) as $r) {
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
    public function run(int $maxEvents = self::MAX_EVENTS, int $unreadLimit = self::UNREAD_PER_RUN, bool $reconcile = false): array
    {
        $job = $this->db->one('SELECT mark_at, floor_at FROM profit_job WHERE job = ?', [self::JOB]);
        if ($job === null || $job['mark_at'] === null) {
            return ['not_started' => true];
        }
        $mark = (string) $job['mark_at'];
        $floor = $job['floor_at'] === null ? '' : (string) $job['floor_at'];
        $this->floor = $floor;
        $now = ($this->clock)();
        $cutoff = Clock::db($now->modify('-' . self::LAG_SECONDS . ' seconds'));
        if ($cutoff <= $mark) {
            return ['events' => 0, 'mark_at' => $mark];
        }
        $started = $this->startNewItems($cutoff);
        $from = max($floor, Clock::db(Clock::fromDb($mark)->modify('-' . self::OVERLAP_SECONDS . ' seconds')));
        $events = [];
        // By the booking time alone (its own index): ids are given at insert, so a row committed with an earlier id and a later time than the
        // window's first row is still read (the final review of 6d59eda, c-M3, PR107).
        foreach ($this->db->all("SELECT l.id, l.sku_id, l.qty_delta, CAST(l.unit_cost AS CHAR) AS unit_cost, l.document_id, l.document_line, l.doc_ref, "
            . "l.created_at, COALESCE(q.created_at, l.created_at) AS seq_at, d.reverses_id FROM stock_ledger l FORCE INDEX (ix_stock_ledger_created) JOIN document d ON d.id = l.document_id "
            . "JOIN warehouse w ON w.id = l.warehouse_id LEFT JOIN stock_value_seq q ON q.stock_ledger_id = l.id "
            . "WHERE l.created_at >= ? AND l.created_at <= ? AND l.movement_type = 'goods_in' AND l.bucket = 'on_hand' "
            . "AND l.cost_source = 'document' AND l.unit_cost IS NOT NULL AND w.stock_owner = 'own' ORDER BY l.created_at, l.id", [$from, $cutoff]) as $r) {
            $events[] = [(string) $r['seq_at'], 0, (int) $r['id'], 'ledger', $r];
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
                // Done is everything BEFORE the first event not read: the mark stops a microsecond short of it (a sale paid at its time
                // waits for it: the Codex review of 57d98ad, N6).
                // Never past the cutoff: a delivery is stamped when its stock was made final, which can be after the cutoff, and what came
                // between the cutoff and it was not read (the Codex check of e1f075c, M1).
                $newMark = min($cutoff, max($mark, Clock::db(Clock::fromDb($at)->modify('-1 usec'))));
                break;
            }
            foreach ($kind === 'ledger' ? $this->ledgerEvent($r) : $this->documentEvents($r) as $k) {
                $done[$k] = ($done[$k] ?? 0) + 1;
                $written += $k === 'skipped' || $k === 'rebuilt' ? 0 : 1;
            }
            $written += $this->rebuildWrites; // an event met late wrote its item's history again: every event of it counts
            $this->rebuildWrites = 0;
        }
        // What a booking that committed later than the overlap left behind (b2): a receipt, an invoice or a credit note not read whole since
        // it left the window, whatever its time since the start (each run up to UNREAD_PER_RUN, the oldest first; at night, with --reconcile,
        // up to UNREAD_NIGHTLY). Applied now (late: its item's history is written again).
        $since = $floor === '' ? $mark : $floor;
        // The look back waits for the next run once this run has written its limit (a recovered event can rebuild an item's history).
        foreach ($written >= $maxEvents ? [] : $this->unread($since, $from, $reconcile ? self::UNREAD_NIGHTLY : $unreadLimit) as $k) {
            $done[$k] = ($done[$k] ?? 0) + 1;
            $written += $k === 'skipped' || $k === 'rebuilt' ? 0 : 1;
        }
        $written += $this->rebuildWrites;
        $this->rebuildWrites = 0;
        // The mark never passes a document not read yet (the lead, on the Codex check of e1f075c): while one is left before the window (more
        // than a run reads), it stays just before it (never going back: a sale costed already keeps its cost; bin/recost_sales.php is the
        // deliberate re-cost).
        $left = $this->db->value("SELECT MIN(d.posted_at) FROM document d LEFT JOIN item_cost_document r ON r.document_id = d.id WHERE d.doc_type IN ('GRN', 'REL', 'SINV', 'SCN') "
            . "AND d.status IN ('posted', 'reversed') AND d.posted_at >= ? AND d.posted_at < ? AND r.document_id IS NULL", [$since, $from]);
        if ($left !== null) {
            $newMark = max($mark, min($newMark, Clock::db(Clock::fromDb((string) $left)->modify('-1 usec'))));
        }
        $summary = ['events' => $written, 'kinds' => $done, 'started' => $started, 'from' => $from, 'to' => $newMark];
        $this->db->exec('UPDATE profit_job SET mark_at = ?, ran_at = ?, summary = ? WHERE job = ?',
            [$newMark, Clock::db($now), json_encode($summary, JSON_THROW_ON_ERROR), self::JOB]);
        return $summary + ['mark_at' => $newMark];
    }

    /**
     * The receipts, invoices and credit notes posted between the start and $before that the job has not read whole since they left its
     * window (a booking that committed long after its own time is among them).
     *
     * @return list<string> what was written
     */
    private function unread(string $since, string $before, int $limit = self::UNREAD_PER_RUN): array
    {
        $out = [];
        // Every document is read whole once more after it left the job's window (its events written once each: what is done is skipped), and
        // marked read (item_cost_document), also when it moves no price: a document partly read when a run stopped is read again, and
        // documents that move nothing never fill the run (the Codex re-check of 37a2cf0, R1, R2).
        foreach ($this->db->all("SELECT d.id, d.doc_type, d.number, d.posted_at, d.reverses_id FROM document d LEFT JOIN item_cost_document r ON r.document_id = d.id "
            . "WHERE d.doc_type IN ('GRN', 'REL', 'SINV', 'SCN') AND d.status IN ('posted', 'reversed') AND d.posted_at >= ? AND d.posted_at < ? AND r.document_id IS NULL "
            . 'ORDER BY d.posted_at, d.id LIMIT ' . max(1, $limit), [$since, $before]) as $d) {
            array_push($out, ...$this->readDocument($d));
        }
        return $out;
    }

    /**
     * One receipt, invoice or credit note read whole (its events written once each: what is done is skipped) and marked read
     * (item_cost_document), also when it moves no price.
     *
     * @param array<string, mixed> $d the document row (id, doc_type, number, posted_at, reverses_id)
     * @return list<string> what was written
     */
    private function readDocument(array $d): array
    {
        $out = [];
        // A receipt, or a priced release of another account's stock into ours (REL: its goods_in at the agreed price, a delivery to the job;
        // the third final review, c-M3, PR154), read by its ledger rows; an invoice or a credit note by its lines.
        if (!in_array($d['doc_type'], ['GRN', 'REL'], true)) {
            $out = $this->documentEvents($d);
        } else {
            foreach ($this->db->all("SELECT l.id, l.sku_id, l.qty_delta, CAST(l.unit_cost AS CHAR) AS unit_cost, l.document_id, l.document_line, l.doc_ref, "
                . "l.created_at, COALESCE(q.created_at, l.created_at) AS seq_at, d.reverses_id FROM stock_ledger l JOIN document d ON d.id = l.document_id "
                . "JOIN warehouse w ON w.id = l.warehouse_id LEFT JOIN stock_value_seq q ON q.stock_ledger_id = l.id "
                . "WHERE l.document_id = ? AND l.movement_type = 'goods_in' AND l.bucket = 'on_hand' AND l.cost_source = 'document' AND l.unit_cost IS NOT NULL "
                . "AND w.stock_owner = 'own' ORDER BY l.id", [(int) $d['id']]) as $r) {
                array_push($out, ...$this->ledgerEvent($r));
            }
        }
        $this->db->exec('INSERT INTO item_cost_document (document_id, read_at) VALUES (?, ?) AS n ON DUPLICATE KEY UPDATE read_at = n.read_at',
            [(int) $d['id'], Clock::db(($this->clock)())]);
        return $out;
    }

    /**
     * A cancellation is read after what it cancels: the document it reverses is read whole first when it is not marked read yet, whatever
     * its time (a booking that committed late and lies outside every run's look back until the night): never a cancellation marked read with
     * nothing to undo while its original is still to come (the Codex re-check of 6b8a7ee, F5). A document of before the start is inside the
     * start (never read).
     *
     * @return list<string> what was written
     */
    private function readOriginal(int $orig): array
    {
        if ($this->rebuilding !== null || $this->db->value('SELECT 1 FROM item_cost_document WHERE document_id = ?', [$orig]) !== null) {
            // A rebuild reads every source of the item in time order (the original before its cancellation) and marks nothing.
            return [];
        }
        $d = $this->db->one("SELECT d.id, d.doc_type, d.number, d.posted_at, d.reverses_id FROM document d WHERE d.id = ? AND d.doc_type IN ('GRN', 'REL', 'SINV', 'SCN') "
            . "AND d.status IN ('posted', 'reversed') AND d.reverses_id IS NULL", [$orig]);
        $this->floor ??= (string) ($this->db->value('SELECT floor_at FROM profit_job WHERE job = ?', [self::JOB]) ?? '');
        if ($d === null || (string) $d['posted_at'] < $this->floor) {
            return [];
        }
        return $this->readDocument($d);
    }

    /**
     * CW's average price of one item (CostHints::average's rule: every unit that came in with a price) of the rows booked before $before.
     */
    private function averageBefore(int $sku, string $before): ?int
    {
        $r = $this->db->one('SELECT CAST(SUM(l.qty_delta * l.unit_cost) AS CHAR) AS v, SUM(l.qty_delta) AS q FROM stock_value_seq s JOIN stock_ledger l ON l.id = s.stock_ledger_id '
            . 'WHERE s.sku_id = ? AND l.unit_cost IS NOT NULL AND l.qty_delta > 0 AND s.created_at < ?', [$sku, $before]);
        if ($r === null || (int) ($r['q'] ?? 0) <= 0) {
            return null;
        }
        return ProfitMath::e4(bcdiv((string) $r['v'], (string) $r['q'], 6));
    }

    /**
     * Items made since the start (no item_cost row). Each starts just before the first delivery the job reads of it (or at $cutoff, the run's
     * own time, when it has none yet), so every delivery, invoice and credit note of it is folded on top of its start in time order, as for
     * an item of the start: an invoice made final before the item's first run keeps its correction (the Codex re-check of 6b8a7ee, F1).
     * Its starting price: CW's average of what came in with a price before that moment (stock booked in by hand at a price: the re-check of
     * 37a2cf0, R5), else the supplier's price, else none.
     */
    private function startNewItems(string $cutoff): int
    {
        $skus = array_map('intval', $this->db->column('SELECT s.id FROM sku s LEFT JOIN item_cost c ON c.sku_id = s.id WHERE c.sku_id IS NULL ORDER BY s.id LIMIT 5000'));
        if ($skus === []) {
            return 0;
        }
        $firstIn = [];
        foreach (array_chunk($skus, 500) as $chunk) {
            // By when their stock was made final (the item's commit order, the clock of every event: the Codex check of e1f075c, M2).
            foreach ($this->db->all("SELECT l.sku_id, MIN(COALESCE(q.created_at, l.created_at)) AS first_at FROM stock_ledger l JOIN warehouse w ON w.id = l.warehouse_id "
                . 'LEFT JOIN stock_value_seq q ON q.stock_ledger_id = l.id WHERE l.sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ") "
                . "AND l.movement_type = 'goods_in' AND l.bucket = 'on_hand' AND l.cost_source = 'document' AND l.unit_cost IS NOT NULL AND w.stock_owner = 'own' "
                . 'AND l.created_at <= ? GROUP BY l.sku_id', [...$chunk, $cutoff]) as $r) {
                $firstIn[(int) $r['sku_id']] = (string) $r['first_at'];
            }
        }
        $prices = [];
        $at = [];
        foreach ($skus as $sku) {
            $first = $firstIn[$sku] ?? null;
            $at[$sku] = $first === null ? $cutoff : Clock::db(Clock::fromDb($first)->modify('-1 usec'));
            $e4 = $this->averageBefore($sku, $first ?? Clock::db(Clock::fromDb($cutoff)->modify('+1 usec')));
            if ($e4 !== null) {
                $prices[$sku] = ['e4' => $e4, 'source' => 'cw_average'];
            }
        }
        // The supplier's price known on the UK day the item starts (its first delivery), never one dated after it (the second final review of
        // 3c31d94, c-M5, PR151: a price raised two days after its first delivery made its start £4 where the job on the day would have said
        // none, and ten unpriced units at £4 with ten at £2 gave £3); none known then: none (its first priced delivery sets it).
        $byDay = [];
        foreach (array_values(array_diff($skus, array_keys($prices))) as $sku) {
            $byDay[ReportRefresh::ukDay($firstIn[$sku] ?? $at[$sku])][] = $sku; // the delivery's own day (at UK midnight too: LOW 2)
        }
        foreach ($byDay as $day => $group) {
            foreach ($this->supplierPricesBefore($group, ReportRefresh::nextDay((string) $day)) as $s => $e4) {
                $prices[$s] = ['e4' => $e4, 'source' => 'supplier_price'];
            }
        }
        $now = Clock::db(($this->clock)());
        foreach ($skus as $sku) {
            $p = $prices[$sku] ?? ['e4' => null, 'source' => 'none'];
            $this->db->transaction(function (Db $db) use ($sku, $p, $at, $now): void {
                $this->startItem($db, $sku, $p['e4'], $p['source'], $at[$sku], $now);
            });
        }
        return count($skus);
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
        // Its time is when its units were on hand: when its operation was made final (its place in the item's commit order, stock_value_seq),
        // the clock "units on hand at a moment" reads too (the Codex check of 3f1b54e, M1; the booking's own time is kept in the ledger).
        $at = (string) ($r['seq_at'] ?? $r['created_at']);
        if ($r['reverses_id'] !== null && $qty < 0) {
            $orig = (int) $r['reverses_id'];
            // The delivery it takes back read first (F5): its units leave at the price they came in at.
            $first = $this->readOriginal($orig);
            $in = $line === null ? null : $this->db->value("SELECT price_e4 FROM item_cost_event WHERE kind = 'receipt' AND document_id = ? AND document_line = ? "
                . 'AND sku_id = ? AND timeline = ? ORDER BY id DESC LIMIT 1', [$orig, $line, $sku, $this->tl($sku)]);
            $price = $in === null ? $own : (int) $in;
            if ($line !== null) {
                // ... with the moves its invoices and credits gave them since (the second final review of 3c31d94, F2, PR131): ten at £4, ten at £2
                // invoiced at £3 (£70) taken back leave at £3 and the ten that stay keep £40 (it took £20 off, leaving £50). An invoice or credit
                // of it cancelled later finds none of its units here (reachNow): the move comes off once.
                $price += $this->adjustmentOn($sku, $orig, $line, (int) $r['id'], $at, $price, $in !== null);
            }
            return [...$first, $this->write(['sku_id' => $sku, 'source_at' => $at, 'kind' => 'receipt_reversal', 'source_key' => 'ledger:' . (int) $r['id'], 'document_id' => $doc,
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
        $basis = $line === null ? null : $this->receiptBasis($doc, $line, $sku, $at, $own);
        if ($basis !== null) {
            // The units this receipt line brought are given to the invoice line(s) it received in line order, each at most its own units (the
            // arrival matching, Arrivals::missingOf: two lines keyed as one on the receipt and short take what came from the first line
            // first; the Codex re-check of 6b8a7ee, F2); its price is their landed value less the part of each credit given before the goods
            // came that is on the units that came (N1, F3), plus the units beyond the invoice at the receipt's own price (re-check of e5d2e5a).
            $p = ProfitMath::receiptPrice($basis['lines'], $basis['came'], $own);
            if ($p !== null) {
                $price = $p;
                $refDoc = $basis['invoice'];
                $refLine = (int) array_key_first($basis['lines']);
                $credited = array_filter($basis['lines'], static fn (array $l): bool => $l['credits'] !== []) !== [];
                $note = 'a delivery of an invoice\'s goods on their way, at the invoice\'s landed price' . ($credited ? ', less its credits' : '');
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
     * @return array{0: int, 1: non-empty-list<int>, 2?: array<int, int>}|null [invoice document id, its lines this receipt line received, and for a
     *         receipt of the rest the units of each it settled]
     */
    private function invoiceLineOf(int $grn, int $line, int $sku, string $at): ?array
    {
        $inv = $this->db->one("SELECT g.sinv_document_id AS id FROM goods_receipt_invoice g JOIN document d ON d.id = g.sinv_document_id "
            . "AND d.status IN ('posted', 'reversed') AND d.posted_at <= ? WHERE g.grn_document_id = ?", [$at, $grn]);
        if ($inv === null || $this->isCopy((int) $inv['id'])) {
            return null;
        }
        // CW-1155 (docs/decisions.md RR14): a receipt of the REST of an invoice takes the invoice lines its rows name (written by its posting,
        // never changed), each at most the units the row settled: never the n-th line of the item (two lines of one item at two prices, only
        // the second owed: the second's price). A line of it no row names brought nothing of the invoice: its own price.
        $rest = $this->db->all("SELECT line_no, units, document_line_no FROM invoice_arrival_rest WHERE kind = 'rest' AND document_id = ? AND sinv_document_id = ? "
            . 'ORDER BY line_no, id', [$grn, (int) $inv['id']]);
        if ($rest !== []) {
            $got = [];
            $caps = [];
            foreach ($rest as $r) {
                if ((int) $r['document_line_no'] === $line) {
                    $got[] = (int) $r['line_no'];
                    $caps[(int) $r['line_no']] = ($caps[(int) $r['line_no']] ?? 0) + (int) $r['units'];
                }
            }
            return $got === [] ? null : [(int) $inv['id'], array_values(array_unique($got)), $caps];
        }
        $mine = array_map('intval', $this->db->column('SELECT line_no FROM document_line WHERE document_id = ? AND sku_id = ? ORDER BY line_no', [$grn, $sku]));
        // Only the invoice's lines that were on their way (an arrival row was made for each when the invoice was made final, and is never
        // removed): a line of the item already received on a named delivery is not this receipt's (the Codex review of 57d98ad, N4).
        $theirs = array_map('intval', $this->db->column('SELECT a.line_no FROM invoice_arrival a WHERE a.sinv_document_id = ? AND a.sku_id = ? ORDER BY a.line_no',
            [(int) $inv['id'], $sku]));
        $j = array_search($line, $mine, true);
        if ($j === false || $theirs === []) {
            return null;
        }
        // The invoice's lines go to the receipt's lines of the item in order, a line left over to the receipt's last line (ArrivalBooking).
        $got = [];
        foreach ($theirs as $i => $no) {
            if (min($i, count($mine) - 1) === (int) $j) {
                $got[] = $no;
            }
        }
        return $got === [] ? null : [(int) $inv['id'], $got];
    }

    /**
     * What one receipt line brought of the final invoice it was made from: its units (Arrivals::missingOf's rule: accepted, set aside to
     * check or quarantined) given to the invoice lines it received in line order, each at most its own units (only the lines that got
     * some), each with its landed price and the credits of it given while its goods were on their way and not cancelled by $at.
     *
     * @return array{invoice: int, came: int, own: int, lines: non-empty-array<int, array{take: int, units: int, landed: int, credits: array<int, int>}>}|null
     *         lines: invoice line => what it gave; credits: credit note line ("document:line") => its value (what it takes off a unit x the units it credits)
     */
    private function receiptBasis(int $grn, int $line, int $sku, string $at, ?int $own = null): ?array
    {
        $inv = $this->invoiceLineOf($grn, $line, $sku, $at);
        if ($inv === null) {
            return null;
        }
        $came = (int) ($this->db->value('SELECT COALESCE(accepted_units, 0) + COALESCE(verify_units, 0) + COALESCE(quarantine_units, 0) FROM grn_line '
            . 'WHERE document_id = ? AND line_no = ?', [$grn, $line]) ?? 0);
        if ($came <= 0) {
            // Not counted on the line: what it booked into stock.
            $came = (int) $this->db->value("SELECT COALESCE(SUM(qty_delta), 0) FROM stock_ledger WHERE document_id = ? AND document_line = ? AND sku_id = ? "
                . "AND movement_type = 'goods_in' AND bucket = 'on_hand' AND qty_delta > 0", [$grn, $line, $sku]);
        }
        $own ??= ProfitMath::e4((string) ($this->db->value("SELECT CAST(unit_cost AS CHAR) FROM stock_ledger WHERE document_id = ? AND document_line = ? AND sku_id = ? "
            . "AND movement_type = 'goods_in' AND bucket = 'on_hand' AND qty_delta > 0 AND unit_cost IS NOT NULL ORDER BY id LIMIT 1", [$grn, $line, $sku]) ?? '0')) ?? 0;
        $landed = [];
        foreach ($this->landed($inv[0]) as $g) {
            if (in_array($g['line_no'], $inv[1], true) && $g['price_e4'] !== null && $g['units'] > 0) {
                $landed[$g['line_no']] = [$g['price_e4'], $g['units']];
            }
        }
        $lines = [];
        $left = $came;
        foreach ($inv[1] as $no) {
            if (!isset($landed[$no]) || $left <= 0) {
                continue;
            }
            $take = min($landed[$no][1], $inv[2][$no] ?? PHP_INT_MAX, $left);
            $left -= $take;
            $lines[$no] = ['take' => $take, 'units' => $landed[$no][1], 'landed' => $landed[$no][0], 'credits' => $this->deferredCredits($inv[0], $no, $sku, $at)];
        }
        return $lines === [] ? null : ['invoice' => $inv[0], 'came' => $came, 'own' => $own, 'lines' => $lines];
    }

    /**
     * The value of each credit of an invoice line given while its goods were on their way (deferred) and not cancelled by $at: what it takes
     * off a credited unit x the units it credits (GBP x 10,000), by credit note line ("document:line").
     *
     * @return array<string, int>
     */
    private function deferredCredits(int $invoice, int $invLine, int $sku, string $at): array
    {
        $out = [];
        $tl = $this->tl($sku);
        foreach ($this->db->all("SELECT e.booked_e4, e.units, e.document_id, e.document_line FROM item_cost_event e WHERE e.kind = 'credit_note' AND e.deferred = 1 "
            . 'AND e.ref_document_id = ? AND e.ref_line = ? AND e.sku_id = ? AND e.timeline = ? ORDER BY e.id', [$invoice, $invLine, $sku, $tl]) as $c) {
            // Undone by the cancellation of its own line (the lines of one note cancelled one after the other each give back their own part,
            // against what still stands: the Codex check of e1f075c).
            $undone = $this->db->value("SELECT 1 FROM item_cost_event WHERE kind = 'credit_note_reversal' AND ref_document_id = ? AND document_line = ? AND sku_id = ? "
                . 'AND timeline = ? AND source_at <= ? LIMIT 1', [(int) $c['document_id'], (int) $c['document_line'], $sku, $tl, $at]);
            if ($undone === null) {
                // Each credit line its own (one credit note of two lines of the same invoice line: each undone by its own share, the two
                // together giving back what they took off together: the Codex check of 3f1b54e).
                $out[(int) $c['document_id'] . ':' . (int) $c['document_line']] = (int) $c['booked_e4'] * (int) $c['units'];
            }
        }
        return $out;
    }

    /**
     * The receipt lines still standing at $at (not taken back by then: the Codex re-check of e5d2e5a) that took in goods of an invoice line:
     * receipt events of that invoice whose line, by receiptBasis, got units of it (a line received into several warehouses is several events,
     * one line). Per receipt line: its units in, its first and last portion's times and its basis.
     *
     * @return list<array{units: int, first_at: string, last_at: string, last_row: int, basis: array<string, mixed>, grn: int, line: int}>
     */
    private function receiptsTaking(int $invoice, int $invLine, int $sku, string $at): array
    {
        $out = [];
        foreach ($this->db->all("SELECT e.document_id, e.document_line, COALESCE(SUM(e.units), 0) AS units, MIN(e.source_at) AS first_at, MAX(e.source_at) AS last_at "
            . "FROM item_cost_event e WHERE e.kind = 'receipt' AND e.ref_document_id = ? AND e.sku_id = ? AND e.timeline = ? AND e.source_at <= ? AND e.document_line IS NOT NULL "
            . "AND NOT EXISTS (SELECT 1 FROM document r JOIN stock_ledger rl ON rl.document_id = r.id AND rl.sku_id = e.sku_id AND rl.bucket = 'on_hand' "
            . "JOIN stock_value_seq rq ON rq.stock_ledger_id = rl.id WHERE r.reverses_id = e.document_id AND r.status = 'posted' AND rq.created_at <= ?) "
            . 'GROUP BY e.document_id, e.document_line ORDER BY first_at, e.document_id, e.document_line', [$invoice, $sku, $this->tl($sku), $at, $at]) as $g) {
            $basis = $this->receiptBasis((int) $g['document_id'], (int) $g['document_line'], $sku, (string) $g['first_at']);
            if ($basis === null || $basis['invoice'] !== $invoice || !isset($basis['lines'][$invLine])) {
                continue;
            }
            // Its last portion's row: "since it came" starts after the whole receipt in the item's commit order.
            $lastRow = (int) $this->db->value("SELECT MAX(id) FROM stock_ledger WHERE document_id = ? AND document_line = ? AND sku_id = ? AND movement_type = 'goods_in' "
                . "AND bucket = 'on_hand' AND qty_delta > 0", [(int) $g['document_id'], (int) $g['document_line'], $sku]);
            $out[] = ['units' => (int) $g['units'], 'first_at' => (string) $g['first_at'], 'last_at' => (string) $g['last_at'], 'last_row' => $lastRow, 'basis' => $basis,
                'grn' => (int) $g['document_id'], 'line' => (int) $g['document_line']];
        }
        return $out;
    }

    /**
     * What one credit took (or would take) off a unit of a receipt line: the line's price without it less its price with it, both worked out
     * as the receipt's (ProfitMath::receiptPrice, rounded as the receipt was): an undo gives back exactly what the receipt took off, and a
     * credit's share is never worked out past an integer (the Codex re-check of e5d2e5a).
     *
     * @param array<string, mixed> $basis receiptBasis()'s
     */
    private static function creditShare(array $basis, int $invLine, string $creditDoc, int $value): int
    {
        $with = $basis['lines'];
        $without = $basis['lines'];
        $with[$invLine]['credits'][$creditDoc] = $value;
        unset($without[$invLine]['credits'][$creditDoc]);
        $a = ProfitMath::receiptPrice($without, (int) $basis['came'], (int) $basis['own']);
        $b = ProfitMath::receiptPrice($with, (int) $basis['came'], (int) $basis['own']);
        return $a === null || $b === null ? 0 : max(0, $a - $b);
    }

    /**
     * A supplier invoice or credit note made final, or the reversal of one.
     *
     * @param array<string, mixed> $d the document row
     * @return list<string>
     */
    private function documentEvents(array $d, ?int $onlySku = null): array
    {
        $id = (int) $d['id'];
        $at = (string) $d['posted_at'];
        $number = (string) $d['number'];
        if ($d['reverses_id'] !== null) {
            $first = $this->readOriginal((int) $d['reverses_id']);
            return [...$first, ...$this->reversalEvents($id, (int) $d['reverses_id'], $at, $number, $onlySku)];
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
            if ($g['price_e4'] === null || $g['sku_id'] === null || ($onlySku !== null && $g['sku_id'] !== $onlySku)) {
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
                // On their way AT THE CREDIT'S TIME when no receipt standing then had taken that line's goods in (a receipt took them when one of
                // its lines got units of the line: two lines keyed as one; the Codex re-checks of 6b8a7ee F3 and e5d2e5a). A credit read after a
                // receipt of a later time is older than the item's last event: write() rebuilds the item's history in time order (PR79).
                $taking = $inv !== null && $invLine !== null ? $this->receiptsTaking($inv, $invLine, $sku, $at) : [];
                $deferred = $inv !== null && $invLine !== null && $g['grn'] === null && $taking === [];
                // Never onto other stock: at most the units of the credited delivery that can still be here, the fewest on hand since it came
                // (the final review of 6d59eda, c-HIGH2, PR100).
                $units = $deferred ? $g['units'] : min(ProfitMath::reach($g['units'], $onHand), $this->creditedStillHere($sku, $inv, $invLine, $g['grn'], $taking, $at));
                $out[] = $this->write($base + ['kind' => 'credit_note', 'ref_document_id' => $inv, 'ref_line' => $invLine,
                    'units' => $units, 'price_e4' => 0, 'booked_e4' => $g['price_e4'], 'deferred' => $deferred ? 1 : 0,
                    'note' => $deferred ? 'a price credit of goods still on their way: their receipt takes it in' : 'a price credit']);
                continue;
            }
            $booked = null;
            if ($g['grn'] !== null) {
                // The price the units of that delivery line came in at: the receipt's own (an earlier invoice of other units of the same
                // line moved only its own units: the Codex review of 92efa82, a2).
                [$gd, $gl] = $g['grn'];
                $b = $this->db->value("SELECT price_e4 FROM item_cost_event WHERE kind = 'receipt' AND document_id = ? AND document_line = ? AND sku_id = ? AND timeline = ? "
                    . 'ORDER BY id LIMIT 1', [$gd, $gl, $sku, $this->tl($sku)]);
                $booked = $b !== null ? (int) $b
                    : ProfitMath::e4((string) ($this->db->value('SELECT CAST(unit_cost AS CHAR) FROM document_line WHERE document_id = ? AND line_no = ?', [$gd, $gl]) ?? '0'));
            }
            // The units it bills that are still on hand, never more than the units of the billed delivery that can still be here: the fewest on
            // hand since that delivery came, in the item's commit order (the final review of 6d59eda, c-HIGH2 / a-M6, PR100: a delivery sold out
            // and replaced is never repriced onto the replacement stock), less the units of it the invoice lines posted before this one moved (the
            // second final review of 3c31d94, F1, PR130): 100 in at £2, 80 sold, two invoices of 50 at £3: the first moves the 20 left, the
            // second none (each moved the same 20 from £2: £4). In a history written again the same, in time order.
            // A named delivery line that booked nothing of the item (all short) reaches none, never "no bound" (the third final review, c-M1,
            // PR154). Its units still here are its layer of the item's stock, the oldest sold first (receiptStillHere(), a-HIGH1, PR154).
            $units = 0;
            if ($booked !== null) {
                $here = $this->receiptStillHere($sku, $g['grn'][0], $g['grn'][1], $at);
                $units = min(ProfitMath::reach($g['units'], $onHand), self::freeHere($this->layoutOn($sku, $g['grn'][0], $g['grn'][1], $at, (string) $base['source_key']), $here));
            }
            $out[] = $this->write($base + ['kind' => 'invoice', 'ref_document_id' => $g['grn'][0] ?? null, 'ref_line' => $g['grn'][1] ?? null,
                'units' => $units, 'price_e4' => $g['price_e4'], 'booked_e4' => $booked,
                'note' => $booked === null ? 'invoiced before its goods arrived (the delivery takes this price)' : 'invoice price wins']);
        }
        return $out;
    }

    /**
     * The units of one delivery line ($grn, line $line) of $sku that can still be on hand at $at: its layer of the item's stock, the oldest
     * sold first (layerHere(): at most its units, at most the fewest on hand since it came less what the item's later deliveries brought by
     * then; the third final review, a-HIGH1, PR154), so one unit on the shelf is ever the units of ONE delivery: two deliveries of 50 at £1,
     * 50 sold, each invoiced at £2: the first has none left, the second its 50 (£2, never £3). 0 once it was taken back by then; 0 when the
     * delivery line booked nothing of the item (all short: never "no bound", c-M1, PR154).
     */
    private function receiptStillHere(int $sku, int $grn, int $line, string $at): int
    {
        $r = $this->db->one("SELECT MAX(id) AS last_row, COALESCE(SUM(qty_delta), 0) AS units FROM stock_ledger WHERE document_id = ? AND document_line = ? AND sku_id = ? "
            . "AND movement_type = 'goods_in' AND bucket = 'on_hand' AND qty_delta > 0", [$grn, $line, $sku]);
        if ($r === null || $r['last_row'] === null) {
            return 0;
        }
        // Taken back by then (its reversal's stock made final by $at): none of its units can be here (the Codex check of c48683e: ten at £4
        // stay, ten received at £2 and taken back, a 50p credit of those ten never comes off the first ten).
        if ($this->db->value("SELECT 1 FROM document v JOIN stock_ledger vl ON vl.document_id = v.id AND vl.sku_id = ? AND vl.bucket = 'on_hand' "
            . "JOIN stock_value_seq vq ON vq.stock_ledger_id = vl.id WHERE v.reverses_id = ? AND v.status = 'posted' AND vq.created_at <= ? LIMIT 1", [$sku, $grn, $at]) !== null) {
            return 0;
        }
        return max(0, min((int) $r['units'], $this->lineHere($sku, $grn, $line, $this->position($sku, $at))));
    }

    /**
     * A delivery line's units still on hand at place $to: the sum of its portions' layers (each ledger row of it into our warehouses, e.g. its
     * accepted units into MAIN and the ones set aside into VERIFY, which a receipt books after every line's MAIN units), each at most its own
     * units (the Codex check of the fix round, r13 a and c: one walk from the line's last portion counted the units of a line booked between
     * its portions as its own: two lines of an item on one receipt, the first partly set aside, both invoiced at £2 gave £2.20 for £2.00).
     */
    private function lineHere(int $sku, int $grn, int $line, int $to): int
    {
        $n = 0;
        foreach ($this->db->all("SELECT l.id, l.qty_delta FROM stock_ledger l JOIN warehouse w ON w.id = l.warehouse_id WHERE l.document_id = ? AND l.document_line = ? "
            . "AND l.sku_id = ? AND l.movement_type = 'goods_in' AND l.bucket = 'on_hand' AND l.qty_delta > 0 AND w.stock_owner = 'own' ORDER BY l.id", [$grn, $line, $sku]) as $p) {
            $n += max(0, min((int) $p['qty_delta'], $this->layerHere($sku, (int) $p['id'], $to)));
        }
        return $n;
    }

    /**
     * The units of the delivery portion that is ledger row $lastRow (lineHere() sums a line's portions) that can still be on hand at place $to
     * of the item's commit order, the oldest sold first (FIFO layers; the third final review, a-HIGH1, PR154): the fewest, over the ends of the operations from its own to
     * $to, of the units on hand LESS the units the item's later deliveries brought by then (a priced goods_in of ours after it, less its own
     * cancellation), never below 0 (its units in are the caller's cap). So the units on hand are given to the newest deliveries first and a
     * sale takes the oldest: a unit is ever one delivery's, and the deliveries' units still here add up to the units on hand at most. Every
     * other movement (a sale, a count, a return, a transfer, an earlier delivery taken back) counts as before. The same rows lowestBetween()
     * reads (the item's on-hand rows from the delivery to $to, by the primary key of stock_value_seq), with their document's cancellation link.
     */
    private function layerHere(int $sku, int $lastRow, int $to): int
    {
        $s = $this->seqOf($lastRow);
        $from = $this->opEnd($sku, $s);
        $level = $this->levelAt($sku, $s);
        $rows = $this->seqRows($sku, $s, max($to, $from), true);
        $this->remember($sku, max($to, $from), $level + array_sum(array_column($rows, 'qty')));
        return ProfitMath::lowestLayer($level, $rows, $s === $from);
    }

    /**
     * The units a price credit of invoice $inv, line $invLine can still reach at $at: of the delivery that invoice line billed (the invoice made
     * for a named delivery), else of the receipts that took its goods in (goods on their way), each at most what can still be here
     * (receiptStillHere). No delivery known: no cap (the units on hand, as before).
     *
     * @param array{0: int, 1: int}|null $creditGrn the credit note line's own delivery, when it names one
     * @param list<array<string, mixed>> $taking receiptsTaking()'s
     */
    private function creditedStillHere(int $sku, ?int $inv, ?int $invLine, ?array $creditGrn, array $taking, string $at): int
    {
        $grn = $creditGrn;
        if ($grn === null && $inv !== null && $invLine !== null) {
            foreach ($this->landed($inv) as $l) {
                if ($l['line_no'] === $invLine) {
                    $grn = $l['grn'];
                }
            }
        }
        if ($grn !== null) {
            // A named delivery line that booked nothing reaches none (c-M1, PR154).
            $here = $this->receiptStillHere($sku, $grn[0], $grn[1], $at);
            // Of a delivery line several invoice lines billed, only the units its own invoice line moved, as they stand in posting order (the
            // second final review of 3c31d94, F1, PR130): a credit of the second invoice whose units were all the first one's reaches none.
            $shares = $inv === null || $invLine === null ? [] : self::shares($this->layoutOn($sku, $grn[0], $grn[1], $at), $here);
            return $shares[$inv . ':' . $invLine] ?? $here;
        }
        if ($taking === []) {
            // Goods that were on their way, no receipt of them standing (all taken back): none can be here (a credit of them cancelled after
            // they left adds nothing to the stock that stays: the Codex check of the fix round, new HIGH 1, PR152). No invoice line: no bound.
            return $inv !== null && $invLine !== null ? 0 : PHP_INT_MAX;
        }
        $n = 0;
        foreach ($taking as $t) {
            // The units of the CREDITED line this receipt took (a receipt of two invoice lines keyed as one: ten of one line, two of the other).
            $mine = (int) ($t['basis']['lines'][(int) $invLine]['take'] ?? $t['units']);
            $n += max(0, min($mine, $this->lineHere($sku, (int) $t['grn'], (int) $t['line'], $this->position($sku, $at))));
        }
        return $n;
    }

    /**
     * Where the invoice lines of one delivery line (receipt $grn, line $line) of $sku sit among its units at $at (the second final review of
     * 3c31d94, F1 / F2, PR130, PR131; the Codex check of the fix round, F1 PARTLY, PR152). Its units are places 0, 1, 2 ...; the units still
     * here at a moment are its first places (the ones an invoice moved stay before the rest: PR130's posting order). The invoice events of the
     * item's timeline billing that delivery line (of $at or before; $except left out: the one being worked out now) are replayed in time order:
     * each takes the FREE places among the units still here at its own time first (places a cancelled invoice gave back), then the places
     * after every one taken; a cancellation frees its invoice's places, never handing them to an invoice posted before it (two invoices
     * cancelled one after the other undo their move once; one posted after it takes them again).
     *
     * @return array{segs: list<array{0: ?string, 1: int}>, claims: array<string, array<string, mixed>>} segs: the places in order, each an
     *         owner ("invoice document:line" of an invoice standing at $at, or null: free) and a number of units; claims: those invoices' events
     */
    private function layoutOn(int $sku, int $grn, int $line, string $at, ?string $except = null): array
    {
        $tl = $this->tl($sku);
        $byId = [];
        $events = [];
        foreach ($this->db->all("SELECT e.id, e.document_id, e.document_line, e.units, e.price_e4, e.booked_e4, e.on_hand, e.old_cost_e4, e.new_cost_e4, e.source_key, e.source_at "
            . "FROM item_cost_event e WHERE e.ref_document_id = ? AND e.ref_line = ? AND e.kind = 'invoice' AND e.sku_id = ? AND e.timeline = ? AND e.source_at <= ? "
            . 'ORDER BY e.source_at, e.id', [$grn, $line, $sku, $tl, $at]) as $e) {
            if ($except !== null && (string) $e['source_key'] === $except) {
                continue;
            }
            $byId[(int) $e['id']] = $e;
            $events[] = [(string) $e['source_at'], (int) $e['id'], $e, false];
        }
        if ($byId !== []) {
            foreach ($this->db->all("SELECT id, undoes_event_id, source_at FROM item_cost_event WHERE sku_id = ? AND timeline = ? AND kind = 'invoice_reversal' AND source_at <= ? "
                . 'AND undoes_event_id IN (' . implode(', ', array_fill(0, count($byId), '?')) . ')', [$sku, $tl, $at, ...array_keys($byId)]) as $r) {
                $events[] = [(string) $r['source_at'], (int) $r['id'], $r, true];
            }
        }
        usort($events, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $segs = [];
        $claims = [];
        foreach ($events as [, , $e, $cancel]) {
            if ($cancel) {
                $x = $byId[(int) $e['undoes_event_id']] ?? null;
                if ($x !== null) {
                    $key = (int) $x['document_id'] . ':' . (int) $x['document_line'];
                    foreach ($segs as $i => $seg) {
                        if ($seg[0] === $key) {
                            $segs[$i][0] = null;
                        }
                    }
                    unset($claims[$key]);
                }
                continue;
            }
            $key = (int) $e['document_id'] . ':' . (int) $e['document_line'];
            $claims[$key] = $e;
            $units = max(0, (int) $e['units']);
            if ($units === 0) {
                continue;
            }
            $here = $this->receiptStillHere($sku, $grn, $line, (string) $e['source_at']);
            $next = [];
            $pos = 0;
            foreach ($segs as [$k, $n]) {
                if ($k === null && $units > 0 && $pos < $here) {
                    $take = min($n, $units, $here - $pos);
                    $next[] = [$key, $take];
                    if ($n > $take) {
                        $next[] = [null, $n - $take];
                    }
                    $units -= $take;
                } else {
                    $next[] = [$k, $n];
                }
                $pos += $n;
            }
            if ($units > 0) {
                $next[] = [$key, $units];
            }
            $segs = $next;
        }
        return ['segs' => $segs, 'claims' => $claims];
    }

    /**
     * Of a delivery line's units still here ($here: its first places), each standing invoice line's (layoutOn()). "invoice document:line" => units.
     *
     * @param array{segs: list<array{0: ?string, 1: int}>, claims: array<string, array<string, mixed>>} $layout
     * @return array<string, int>
     */
    private static function shares(array $layout, int $here): array
    {
        $out = array_fill_keys(array_map('strval', array_keys($layout['claims'])), 0);
        $pos = 0;
        foreach ($layout['segs'] as [$k, $n]) {
            if ($k !== null && $pos < $here) {
                $out[$k] = ($out[$k] ?? 0) + min($n, $here - $pos);
            }
            $pos += $n;
        }
        return $out;
    }

    /**
     * Of a delivery line's units still here ($here), those no standing invoice line has moved: the free places, then the places after every one.
     *
     * @param array{segs: list<array{0: ?string, 1: int}>, claims: array<string, array<string, mixed>>} $layout
     */
    private static function freeHere(array $layout, int $here): int
    {
        $free = 0;
        $pos = 0;
        foreach ($layout['segs'] as [$k, $n]) {
            if ($k === null && $pos < $here) {
                $free += min($n, $here - $pos);
            }
            $pos += $n;
        }
        return $free + max(0, $here - $pos);
    }

    /**
     * What an invoice or credit event really moved, of $face (its move a unit x its units): never more in size than the item's price change
     * over the units on hand then (a credit the price could not absorb, floored at 0, took less: the Codex check of the fix round, new HIGH 2,
     * PR152).
     *
     * @param array<string, mixed> $e an item_cost_event row
     */
    private static function applied(array $e, int $face): int
    {
        if ($face === 0 || $e['old_cost_e4'] === null || $e['new_cost_e4'] === null || $e['on_hand'] === null) {
            return $face;
        }
        $actual = ((int) $e['new_cost_e4'] - (int) $e['old_cost_e4']) * (int) $e['on_hand'];
        return $face > 0 ? min($face, max(0, $actual)) : max($face, min(0, $actual));
    }

    /**
     * The units an invoice or a price credit being cancelled can still reach at $at, by its delivery line (PR130, PR131): an invoice of a named
     * delivery its share of what is left of it (shares(); none once the delivery was taken back: its move left with those units); a credit
     * what creditedStillHere() gives its invoice line now. No delivery known: no bound (PHP_INT_MAX).
     *
     * @param array<string, mixed> $e the item_cost_event being cancelled
     */
    private function reachNow(array $e, int $sku, string $at): int
    {
        if ($e['ref_document_id'] === null || $e['ref_line'] === null) {
            return PHP_INT_MAX;
        }
        $ref = (int) $e['ref_document_id'];
        $refLine = (int) $e['ref_line'];
        if ($e['kind'] === 'invoice') {
            $here = $this->receiptStillHere($sku, $ref, $refLine, $at);
            return self::shares($this->layoutOn($sku, $ref, $refLine, $at), $here)[(int) $e['document_id'] . ':' . (int) $e['document_line']] ?? 0;
        }
        // A credit: by its own line's delivery when it names one, else by its invoice line's (creditedStillHere()).
        $own = null;
        foreach ($this->landed((int) $e['document_id']) as $l) {
            if ($l['line_no'] === (int) $e['document_line']) {
                $own = $l['grn'];
            }
        }
        return $this->creditedStillHere($sku, $ref, $refLine, $own, $this->receiptsTaking($ref, $refLine, $sku, $at), $at);
    }

    /**
     * The price credits standing on one invoice line at $at (not deferred, not cancelled by then): what each takes off a unit and the units
     * it reached.
     *
     * @return list<array{per: int, units: int, row: array<string, mixed>}>
     */
    private function standingCredits(int $sku, int $invoice, int $invLine, string $at): array
    {
        return array_map(static fn (array $c): array => ['per' => (int) $c['booked_e4'], 'units' => max(0, (int) $c['units']), 'row' => $c],
            $this->db->all("SELECT e.booked_e4, e.units, e.on_hand, e.old_cost_e4, e.new_cost_e4 FROM item_cost_event e WHERE e.ref_document_id = ? AND e.ref_line = ? AND e.kind = 'credit_note' AND e.deferred = 0 "
                . 'AND e.sku_id = ? AND e.timeline = ? AND e.source_at <= ? AND e.booked_e4 IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item_cost_event r WHERE r.sku_id = e.sku_id '
                . "AND r.timeline = e.timeline AND r.kind = 'credit_note_reversal' AND r.undoes_event_id = e.id AND r.source_at <= ?) ORDER BY e.source_at, e.id",
                [$invoice, $invLine, $sku, $this->tl($sku), $at, $at]));
    }

    /**
     * What a unit of one delivery line (receipt $grn, line $line) carries beyond the price it came in at ($in), when it is taken back at $at
     * (ledger row $revRow; the second final review of 3c31d94, F2, PR131): the moves standing then on its units that can still be here just
     * before (the fewest on hand since it came, up to the operation that takes it back): each invoice naming it on its share (posting order)
     * with the price credits of that invoice line on those units; for goods that were on their way, its price worked out again with the credits
     * standing now (a deferred credit cancelled since gives its part back: b-M8) and the credits given since they came. Spread over every unit
     * the line brought, as the reversal takes them all back.
     */
    private function adjustmentOn(int $sku, int $grn, int $line, int $revRow, string $at, int $in, bool $withBasis = true): int
    {
        $g = $this->db->one("SELECT MAX(id) AS last_row, COALESCE(SUM(qty_delta), 0) AS units FROM stock_ledger WHERE document_id = ? AND document_line = ? AND sku_id = ? "
            . "AND movement_type = 'goods_in' AND bucket = 'on_hand' AND qty_delta > 0", [$grn, $line, $sku]);
        $n = (int) ($g['units'] ?? 0);
        if ($n <= 0 || $g['last_row'] === null) {
            return 0;
        }
        $first = (int) ($this->db->value('SELECT MIN(q.seq) FROM stock_ledger l JOIN stock_value_seq q ON q.stock_ledger_id = l.id WHERE l.document_id = '
            . '(SELECT document_id FROM stock_ledger WHERE id = ?) AND l.sku_id = ?', [$revRow, $sku]) ?? 0);
        $here = $first <= 0 ? $n : max(0, min($n, $this->lineHere($sku, $grn, $line, $first - 1)));
        if ($here <= 0) {
            return 0;
        }
        $value = 0;
        $layout = $this->layoutOn($sku, $grn, $line, $at);
        $shares = self::shares($layout, $here);
        foreach ($layout['claims'] as $key => $c) {
            $mine = $shares[$key] ?? 0;
            $units = max(0, (int) $c['units']);
            if ($mine <= 0 || $units <= 0) {
                continue;
            }
            // What it really moved a unit (a move the floor at 0 cut is never taken out in full: the Codex check of the fix round, PR152).
            $delta = $c['booked_e4'] === null || $c['price_e4'] === null ? 0 : (int) $c['price_e4'] - (int) $c['booked_e4'];
            $value += ProfitMath::mulDivRound(self::applied($c, $delta * $units), $mine, $units);
            foreach ($this->standingCredits($sku, (int) $c['document_id'], (int) $c['document_line'], $at) as $cr) {
                if ($cr['units'] > 0) {
                    $value += ProfitMath::mulDivRound(self::applied($cr['row'], -$cr['per'] * $cr['units']), min($cr['units'], $mine), $cr['units']);
                }
            }
        }
        // A delivery of before the start (in the start's valuation, no receipt event: the Codex check of the fix round, F2 PARTLY) takes the
        // moves of the invoices that name it since; its price as goods on their way is inside the start.
        $basis = $withBasis ? $this->receiptBasis($grn, $line, $sku, $at) : null;
        if ($basis !== null) {
            $now = ProfitMath::receiptPrice($basis['lines'], (int) $basis['came'], (int) $basis['own']);
            if ($now !== null) {
                $value += ($now - $in) * $here;
            }
            foreach ($basis['lines'] as $no => $l) {
                foreach ($this->standingCredits($sku, (int) $basis['invoice'], (int) $no, $at) as $cr) {
                    if ($cr['units'] > 0) {
                        $value += ProfitMath::mulDivRound(self::applied($cr['row'], -$cr['per'] * $cr['units']), min($cr['units'], (int) $l['take'], $here), $cr['units']);
                    }
                }
            }
        }
        return ProfitMath::divRound($value, $n);
    }

    /**
     * An invoice or a credit note cancelled: each of its moves undone on the units still on hand, by what it really moved a unit (its
     * value change over the units it reached: nothing when it moved nothing, the Codex review of 92efa82, a3). A deferred credit is undone
     * only once a receipt took it in; before that, the receipt leaves it out.
     *
     * @return list<string>
     */
    private function reversalEvents(int $id, int $orig, string $at, string $number, ?int $onlySku = null): array
    {
        $out = [];
        foreach ($this->db->all("SELECT * FROM item_cost_event WHERE document_id = ? AND kind IN ('invoice', 'credit_note') ORDER BY id", [$orig]) as $e) {
            $sku = (int) $e['sku_id'];
            if (($onlySku !== null && $sku !== $onlySku) || (int) $e['timeline'] !== $this->tl($sku)) {
                continue; // another item (a rebuild writes one item), or a history no longer in force
            }
            $credit = $e['kind'] === 'credit_note';
            $onHand = $this->onHandAt($sku, $at);
            $units = 0;
            $per = 0;
            if ((int) $e['deferred'] === 1) {
                // Taken in by the receipt line(s) still standing that got units of its invoice line: undone by exactly what it took off a unit of
                // each (its price without the credit less its price with it, as the receipt worked it out), on that receipt's units still
                // here, the fewest on hand since its last portion came in (every portion: R4; the line found by what it took: F3; a receipt
                // taken back counts nothing, and the share is never worked out past an integer: the Codex re-check of e5d2e5a).
                // Its share is worked out against the credits still standing now (credits of the line cancelled before give their own
                // rounding back once: the Codex check of 51417a3, L4).
                $value = 0;
                foreach ($this->receiptsTaking((int) $e['ref_document_id'], (int) $e['ref_line'], $sku, $at) as $t) {
                    $u = min($t['units'], $this->lineHere($sku, (int) $t['grn'], (int) $t['line'], $this->position($sku, $at)));
                    if ($u > 0) {
                        $basis = $t['basis'];
                        foreach (array_keys($basis['lines']) as $no) {
                            $basis['lines'][$no]['credits'] = $this->deferredCredits((int) $e['ref_document_id'], $no, $sku, $at);
                        }
                        $value += self::creditShare($basis, (int) $e['ref_line'], $orig . ':' . (int) $e['document_line'], (int) $e['booked_e4'] * (int) $e['units']) * $u;
                        $units += $u;
                    }
                }
                $per = $units > 0 ? ProfitMath::divRound($value, $units) : 0;
            } else {
                // What the event really moved a unit in the item's history (its timeline, in time order: the Codex review of 57d98ad, N2),
                // undone on the units it reached that can still be here: never more than the fewest on hand since (they sold out: nothing of
                // it is left to undo; a3).
                $reached = (int) $e['units'];
                $effect = $this->effectOf($e);
                if ($reached > 0) {
                    // The units are kept even when it moved nothing (N2).
                    $per = $effect === 0 ? 0 : -ProfitMath::divRound($effect * (int) $e['on_hand'], $reached);
                    // Never more than the units of its delivery line it still has (in posting order), none once that delivery was taken back:
                    // its move left with those units (the second final review of 3c31d94, F1 / F2, PR130, PR131).
                    $units = min($reached, $this->lowestOnHandSince($sku, (string) $e['source_at'], $at), $this->reachNow($e, $sku, $at));
                }
            }
            $out[] = $this->write(['sku_id' => $sku, 'source_at' => $at, 'kind' => $credit ? 'credit_note_reversal' : 'invoice_reversal',
                'source_key' => 'doc:' . $id . ':' . (int) $e['document_line'] . ':' . $sku, 'document_id' => $id,
                'document_line' => $e['document_line'] === null ? null : (int) $e['document_line'], 'doc_ref' => $number, 'ref_document_id' => $orig, 'ref_line' => null,
                'units' => max(0, min($units, $onHand)), 'on_hand' => $onHand, 'price_e4' => $per, 'booked_e4' => 0,
                'undoes_event_id' => (int) $e['deferred'] === 1 ? null : (int) $e['id'], 'note' => 'cancelled: ' . ($credit ? 'a credit note' : 'an invoice')]);
        }
        return $out;
    }

    /**
     * What one event moved the item's price, in the history folded in its sources' order: the price after it less the price before it.
     *
     * @param array<string, mixed> $e an item_cost_event row
     */
    private function effectOf(array $e): int
    {
        $before = $this->db->all("SELECT id, kind, units, on_hand, price_e4, booked_e4, new_cost_e4, deferred, undoes_event_id FROM item_cost_event WHERE sku_id = ? "
            . "AND timeline = ? AND (kind = 'start' OR source_at < ? OR (source_at = ? AND id < ?)) ORDER BY source_at, id",
            [(int) $e['sku_id'], (int) $e['timeline'], $e['source_at'], $e['source_at'], (int) $e['id']]);
        $from = ProfitMath::fold($before);
        $to = ProfitMath::step($from, $e);
        return $from === null || $to === null ? 0 : $to - $from;
    }

    /**
     * The fewest units of $sku on hand in our warehouses at any moment from $since to $until: lowestBetween() of their places in the item's
     * commit order.
     */
    private function lowestOnHandSince(int $sku, string $since, string $until): int
    {
        return $this->lowestBetween($sku, $this->position($sku, $since), $this->position($sku, $until));
    }

    /**
     * The fewest units of $sku on hand in our warehouses from place $from to place $to of its commit order (stock_value_seq: each item's
     * on-hand rows numbered in the order they were made final, an operation's rows together), looked at only between whole operations
     * (ProfitMath::lowestLevel; the Codex re-checks of 37a2cf0 R3, 6b8a7ee F4, e5d2e5a and 51417a3: a transfer between two of our rooms is
     * never a sell-out, however long it took).
     */
    private function lowestBetween(int $sku, int $from, int $to): int
    {
        $level = $this->levelAt($sku, $from);
        if ($to <= $from) {
            return max(0, $level);
        }
        $rows = $this->seqRows($sku, $from, $to);
        $this->remember($sku, $to, $level + array_sum(array_column($rows, 'qty')));
        return ProfitMath::lowestLevel($level, $rows);
    }

    /**
     * An item's place in its commit order at a moment: its last stock_value_seq made by then (made at the end of its operation, just before it
     * was final: what was on hand then), carried to the end of the operation it is in (0: nothing yet).
     */
    private function position(int $sku, string $at): int
    {
        // Its seq rows are made in order, so their times only grow: a binary search on the primary key (a few reads, never the history
        // after the moment: the Codex check of 3f1b54e, M2).
        $lo = 0;
        $hi = (int) ($this->db->value('SELECT last_seq FROM stock_value_clock WHERE sku_id = ?', [$sku]) ?? 0);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            $t = $this->db->value('SELECT created_at FROM stock_value_seq WHERE sku_id = ? AND seq = ?', [$sku, $mid]);
            if ($t !== null && (string) $t <= $at) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        // A clock stepped back can leave a later row with an earlier time just after: those are taken too.
        $lo = max($lo, (int) ($this->db->value('SELECT MAX(seq) FROM stock_value_seq WHERE sku_id = ? AND seq > ? AND seq <= ? AND created_at <= ?', [$sku, $lo, $lo + 64, $at])
            ?? 0));
        return $lo === 0 ? 0 : $this->opEnd($sku, $lo);
    }

    /** The place of one ledger row in its item's commit order (0: none, never for an on-hand row). */
    private function seqOf(int $ledgerId): int
    {
        return (int) ($this->db->value('SELECT seq FROM stock_value_seq WHERE stock_ledger_id = ?', [$ledgerId]) ?? 0);
    }

    /** The last place of the operation that place $s belongs to: an operation's rows of an item are together in its commit order. */
    private function opEnd(int $sku, int $s): int
    {
        if ($s <= 0) {
            return 0;
        }
        $op = $this->seqRows($sku, $s - 1, $s)[0]['op'] ?? null;
        if ($op === null) {
            return $s;
        }
        // The next row first (most operations end there), then more at a time.
        for ($n = 1; ; $n = min(64, $n * 4)) {
            $next = $this->seqRows($sku, $s, $s + $n);
            foreach ($next as $r) {
                if ($r['op'] !== $op) {
                    return $s;
                }
                $s = (int) $r['seq'];
            }
            if (count($next) < $n) {
                return $s;
            }
        }
    }

    /**
     * The item's rows with $from < place <= $to in its commit order: each with its operation (ProfitMath::operation) and, for our own
     * warehouses' on-hand rows, its units (another owner's rows count 0: they are there to keep an operation together).
     *
     * With $layers (layerHere()), each row also says whether it is a LATER delivery's: a priced goods_in of ours (a delivery, as the job
     * reads one) with qty in, or the cancellation of such a delivery met in these rows (its document reverses one whose goods_in came here).
     *
     * @return list<array{id: int, seq: int, qty: int, op: ?string, later?: bool}>
     */
    private function seqRows(int $sku, int $from, int $to, bool $layers = false): array
    {
        if ($to <= $from) {
            return [];
        }
        $own = array_fill_keys($this->ownWarehouses(), true);
        $rows = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'seq' => (int) $r['seq'],
            'qty' => isset($own[(int) $r['warehouse_id']]) ? (int) $r['qty_delta'] : 0,
            'op' => ProfitMath::operation($r['document_id'] === null ? null : (int) $r['document_id'], $r['idem_key'] === null ? null : (string) $r['idem_key'],
                $r['channel_id'] === null ? null : (int) $r['channel_id'], (string) $r['actor']),
            'delivery' => isset($own[(int) $r['warehouse_id']]) && ($r['dlv'] ?? '0') === '1', 'doc' => $r['document_id'] === null ? null : (int) $r['document_id'],
            'reverses' => ($r['reverses_id'] ?? null) === null ? null : (int) $r['reverses_id']],
            $this->db->all('SELECT STRAIGHT_JOIN s.seq, l.id, l.warehouse_id, l.qty_delta, l.document_id, l.idem_key, l.channel_id, l.actor'
                . ($layers ? ", IF(l.movement_type = 'goods_in' AND l.bucket = 'on_hand' AND l.cost_source = 'document' AND l.unit_cost IS NOT NULL, '1', '0') AS dlv, d.reverses_id" : '')
                . ' FROM stock_value_seq s FORCE INDEX (PRIMARY) JOIN stock_ledger l ON l.id = s.stock_ledger_id'
                . ($layers ? ' LEFT JOIN document d ON d.id = l.document_id' : '')
                . ' WHERE s.sku_id = ? AND s.seq > ? AND s.seq <= ? ORDER BY s.seq', [$sku, $from, $to]));
        if (!$layers) {
            return array_map(static fn (array $r): array => ['id' => $r['id'], 'seq' => $r['seq'], 'qty' => $r['qty'], 'op' => $r['op']], $rows);
        }
        $later = [];
        foreach ($rows as $i => $r) {
            $mark = false;
            if ($r['delivery'] && $r['qty'] > 0 && $r['doc'] !== null) {
                $later[$r['doc']] = true;
                $mark = true;
            } elseif ($r['delivery'] && $r['qty'] < 0 && $r['reverses'] !== null && isset($later[$r['reverses']])) {
                $mark = true; // a later delivery taken back: its units leave with it, never the earlier one's
            }
            $rows[$i] = ['id' => $r['id'], 'seq' => $r['seq'], 'qty' => $r['qty'], 'op' => $r['op'], 'later' => $mark];
        }
        return $rows;
    }

    /**
     * Units of $sku on hand in our warehouses at place $s of its commit order, exactly: the sum of our warehouses' on-hand rows of its seq
     * rows walked from the nearest place known (nothing before the first; today's balances at its last; every place worked out before by this
     * engine), never bounded by ledger ids or booking times (the Codex check of e1f075c, M4). A rebuild reads its events in time order, so
     * each walk is from the event before: the history is read about once per rebuild (M2).
     */
    private function levelAt(int $sku, int $s): int
    {
        if ($s <= 0 || $this->ownWarehouses() === []) {
            return 0;
        }
        if (!isset($this->levels[$sku])) {
            $own = $this->ownWarehouses();
            $this->levels[$sku] = [0 => 0];
            // Its last place and today's balances in ONE read (one consistent view: a booking made final meanwhile is in both or in neither).
            $end = $this->db->one('SELECT c.last_seq, (SELECT COALESCE(SUM(b.on_hand), 0) FROM stock_balance b WHERE b.sku_id = c.sku_id AND b.warehouse_id IN ('
                . implode(', ', array_fill(0, count($own), '?')) . ')) AS level FROM stock_value_clock c WHERE c.sku_id = ?', [...$own, $sku]);
            if ($end !== null && (int) $end['last_seq'] > 0) {
                $this->levels[$sku][(int) $end['last_seq']] = (int) $end['level'];
            }
        }
        $points = $this->levels[$sku];
        if (isset($points[$s])) {
            return $points[$s];
        }
        $near = 0;
        foreach (array_keys($points) as $p) {
            if (abs($p - $s) < abs($near - $s)) {
                $near = $p;
            }
        }
        $level = $near < $s ? $points[$near] + $this->ownSum($sku, $near, $s) : $points[$near] - $this->ownSum($sku, $s, $near);
        $this->remember($sku, $s, $level);
        return $level;
    }

    /** Keeps a place's level for the next walks (a few dozen places an item). */
    private function remember(int $sku, int $s, int $level): void
    {
        $this->levels[$sku][$s] = $level;
        if (count($this->levels[$sku]) > 64) {
            foreach (array_keys($this->levels[$sku]) as $p) {
                if ($p !== 0 && $p !== $s) {
                    unset($this->levels[$sku][$p]);
                    break;
                }
            }
        }
    }

    /** The units our warehouses' on-hand rows of $sku moved at places $from < place <= $to of its commit order. */
    private function ownSum(int $sku, int $from, int $to): int
    {
        $own = $this->ownWarehouses();
        if ($own === [] || $to <= $from) {
            return 0;
        }
        return (int) $this->db->value('SELECT STRAIGHT_JOIN COALESCE(SUM(l.qty_delta), 0) FROM stock_value_seq s FORCE INDEX (PRIMARY) JOIN stock_ledger l ON l.id = s.stock_ledger_id '
            . "WHERE s.sku_id = ? AND s.seq > ? AND s.seq <= ? AND l.bucket = 'on_hand' AND l.warehouse_id IN (" . implode(', ', array_fill(0, count($own), '?')) . ')',
            [$sku, $from, $to, ...$own]);
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
        $shares = ProfitMath::chargeShares($charges, array_map(static fn (array $g): int => $g['e2'], $goods), array_map(static fn (array $g): int => $g['units'], $goods));
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

    /** The timeline of an item that events are read from and written to: the one a rebuild of it is writing, else the one in force. */
    private function tl(int $sku): int
    {
        return $this->timelineOf[$sku] ?? (int) ($this->db->value('SELECT timeline FROM item_cost WHERE sku_id = ?', [$sku]) ?? 0);
    }

    /**
     * Writes one event and the item's new price in a transaction of its own; `skipped` when that source was written before (in the item's
     * timeline). The move is ProfitMath::step on the price of the item's last event. An event older than the item's last one (a booking
     * that committed late, one the look back recovered, the cancellation of one) is never put in at its place or adjusted in place: the
     * item's whole history is written again from its start, in time order, with it in its place, the same way every event is written in
     * order (rebuild(); the coordinator and the Codex check of 51417a3, PR79).
     *
     * @param array<string, mixed> $e the event's columns (sku_id, source_at, kind, source_key, document_id, document_line, doc_ref,
     *        ref_document_id, ref_line, units, on_hand, price_e4, booked_e4, deferred?, undoes_event_id?, note)
     */
    private function write(array $e): string
    {
        $sku = (int) $e['sku_id'];
        $kind = $this->db->transaction(function (Db $db) use ($e, $sku): string {
            $now = Clock::db(($this->clock)());
            if ($db->value('SELECT 1 FROM item_cost WHERE sku_id = ? FOR UPDATE', [$sku]) === null) {
                // An item the start did not reach yet (made a moment ago): it starts with no price, at its first event.
                $this->startItem($db, $sku, null, 'none', (string) $e['source_at'], $now);
            }
            $tl = $this->tl($sku);
            if ($db->value('SELECT 1 FROM item_cost_event WHERE source_key = ? AND timeline = ?', [$e['source_key'], $tl]) !== null) {
                return 'skipped';
            }
            $at = (string) $e['source_at'];
            $last = $db->one('SELECT at, source_at, new_cost_e4 FROM item_cost_event FORCE INDEX (ix_item_cost_event_item) WHERE sku_id = ? AND timeline = ? '
                . 'ORDER BY source_at DESC, id DESC LIMIT 1', [$sku, $tl]);
            if ($last !== null && (string) $last['source_at'] > $at && $this->rebuilding !== $sku) {
                return 'late';
            }
            $old = $last === null || $last['new_cost_e4'] === null ? null : (int) $last['new_cost_e4'];
            $new = ProfitMath::step($old, $e);
            $when = $last !== null && (string) $last['at'] > $at ? (string) $last['at'] : $at;
            $id = $db->insert('INSERT INTO item_cost_event (sku_id, timeline, at, source_at, kind, source_key, document_id, document_line, doc_ref, ref_document_id, ref_line, '
                . 'units, on_hand, price_e4, booked_e4, deferred, undoes_event_id, old_cost_e4, new_cost_e4, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$sku, $tl, $when, $at, $e['kind'], $e['source_key'], $e['document_id'] ?? null, $e['document_line'] ?? null,
                    isset($e['doc_ref']) ? mb_substr((string) $e['doc_ref'], 0, 64) : null, $e['ref_document_id'] ?? null, $e['ref_line'] ?? null, (int) $e['units'],
                    $e['on_hand'], $e['price_e4'], $e['booked_e4'], (int) ($e['deferred'] ?? 0), $e['undoes_event_id'] ?? null, $old, $new,
                    mb_substr((string) $e['note'], 0, 255)]);
            if ($this->rebuilding !== $sku) {
                $db->exec('UPDATE item_cost SET unit_cost_e4 = ?, last_event_id = ?, updated_at = ? WHERE sku_id = ?', [$new, $id, $now, $sku]);
            }
            return (string) $e['kind'];
        });
        if ($kind === 'late') {
            // Its rows are counted by the rebuild (rebuildWrites), the event itself not again (the Codex check of e1f075c).
            $this->rebuild($sku, [(string) $e['source_key']]);
            return 'rebuilt';
        }
        return $kind;
    }

    /**
     * The item's whole price history written again, as its next timeline, from its start, in time order: every delivery, invoice, credit
     * note and cancellation of the timeline in force, and the sources named in $extra (one that came late), each worked out again by the
     * same rules the job applies to an event read in order (their units on hand, a credit of goods on their way, what a receipt took in,
     * what a cancellation undoes: all as of their own time). The start is first (placed just before the first of them when it was not).
     * The timeline in force is kept as it was (append only); item_cost then names the new one and its price. One transaction.
     *
     * @param list<string> $extra source keys (ledger:<id>, doc:<id>:<line>:<item>) besides those of the timeline in force
     * @return ?int the item's price after it
     */
    public function rebuild(int $sku, array $extra = []): ?int
    {
        return $this->db->transaction(function (Db $db) use ($sku, $extra): ?int {
            $ic = $db->one('SELECT timeline FROM item_cost WHERE sku_id = ? FOR UPDATE', [$sku]);
            if ($ic === null) {
                return null;
            }
            $events = $db->all('SELECT * FROM item_cost_event WHERE sku_id = ? AND timeline = ? ORDER BY source_at, id', [$sku, (int) $ic['timeline']]);
            $next = 1 + (int) $db->value('SELECT COALESCE(MAX(timeline), 0) FROM item_cost_event WHERE sku_id = ?', [$sku]);
            $start = null;
            $ledger = [];
            $docs = [];
            foreach ($events as $ev) {
                $start ??= $ev['kind'] === 'start' ? $ev : null;
            }
            foreach ([...array_map(static fn (array $ev): string => (string) $ev['source_key'], $events), ...$extra] as $key) {
                if (preg_match('/^ledger:([0-9]+)$/D', $key, $m) === 1) {
                    $ledger[(int) $m[1]] = true;
                } elseif (preg_match('/^doc:([0-9]+):/', $key, $m) === 1) {
                    $docs[(int) $m[1]] = true;
                }
            }
            $sources = [];
            foreach (array_chunk(array_keys($ledger), 500) as $chunk) {
                foreach ($db->all("SELECT l.id, l.sku_id, l.qty_delta, CAST(l.unit_cost AS CHAR) AS unit_cost, l.document_id, l.document_line, l.doc_ref, l.created_at, "
                    . 'COALESCE(q.created_at, l.created_at) AS seq_at, d.reverses_id FROM stock_ledger l JOIN document d ON d.id = l.document_id '
                    . 'LEFT JOIN stock_value_seq q ON q.stock_ledger_id = l.id WHERE l.id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') AND l.sku_id = ?',
                    [...$chunk, $sku]) as $r) {
                    $sources[] = [(string) $r['seq_at'], 0, (int) $r['id'], 'ledger', $r];
                }
            }
            foreach (array_chunk(array_keys($docs), 500) as $chunk) {
                foreach ($db->all('SELECT d.id, d.doc_type, d.number, d.posted_at, d.reverses_id FROM document d WHERE d.id IN (' . implode(', ', array_fill(0, count($chunk), '?'))
                    . ')', $chunk) as $d) {
                    $sources[] = [(string) $d['posted_at'], 1, (int) $d['id'], 'document', $d];
                }
            }
            usort($sources, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
            $startAt = $start === null ? null : (string) $start['source_at'];
            if ($sources !== [] && ($startAt === null || $sources[0][0] <= $startAt)) {
                $startAt = Clock::db(Clock::fromDb($sources[0][0])->modify('-1 usec'));
            }
            $startAt ??= Clock::db(($this->clock)());
            $db->insert("INSERT INTO item_cost_event (sku_id, timeline, at, source_at, kind, source_key, new_cost_e4, note) VALUES (?, ?, ?, ?, 'start', ?, ?, ?)",
                [$sku, $next, $startAt, $startAt, 'start:' . $sku, $start['new_cost_e4'] ?? null, (string) ($start['note'] ?? 'start: none')]);
            $before = [$this->timelineOf[$sku] ?? null, $this->rebuilding];
            $this->timelineOf[$sku] = $next;
            $this->rebuilding = $sku;
            try {
                foreach ($sources as [, , , $kind, $r]) {
                    $kind === 'ledger' ? $this->ledgerEvent($r) : $this->documentEvents($r, $sku);
                }
            } finally {
                if ($before[0] === null) {
                    unset($this->timelineOf[$sku]);
                } else {
                    $this->timelineOf[$sku] = $before[0];
                }
                $this->rebuilding = $before[1];
            }
            $this->rebuildWrites += (int) $db->value('SELECT COUNT(*) FROM item_cost_event WHERE sku_id = ? AND timeline = ?', [$sku, $next]);
            $last = (array) $db->one('SELECT id, new_cost_e4 FROM item_cost_event WHERE sku_id = ? AND timeline = ? ORDER BY source_at DESC, id DESC LIMIT 1', [$sku, $next]);
            $db->exec('UPDATE item_cost SET timeline = ?, unit_cost_e4 = ?, last_event_id = ?, updated_at = ? WHERE sku_id = ?',
                [$next, $last['new_cost_e4'], (int) $last['id'], Clock::db(($this->clock)()), $sku]);
            return $last['new_cost_e4'] === null ? null : (int) $last['new_cost_e4'];
        });
    }

    /**
     * Units of $sku on hand in our warehouses just before ledger row $ledgerId, in its item's commit order (the rows of its own operation
     * before it included: a receipt's second portion averages with its first).
     */
    public function onHandBefore(int $sku, int $ledgerId): int
    {
        $s = $this->seqOf($ledgerId);
        if ($s > 0) {
            return $this->levelAt($sku, $s - 1);
        }
        $sum = 0;
        foreach ($this->ownWarehouses() as $wh) {
            $sum += (int) ($this->db->value("SELECT balance_after FROM stock_ledger WHERE warehouse_id = ? AND sku_id = ? AND bucket = 'on_hand' AND id < ? "
                . 'ORDER BY id DESC LIMIT 1', [$wh, $sku, $ledgerId]) ?? 0);
        }
        return $sum;
    }

    /**
     * Units of $sku on hand in our warehouses at a moment: every operation made final by then, whole (an operation is never cut in two by a
     * moment, however long it took: the Codex checks of e5d2e5a and 51417a3).
     */
    public function onHandAt(int $sku, string $at): int
    {
        return $this->levelAt($sku, $this->position($sku, $at));
    }

    /** @return list<int> */
    private function ownWarehouses(): array
    {
        return $this->own ??= array_map('intval', $this->db->column("SELECT id FROM warehouse WHERE stock_owner = 'own' ORDER BY id"));
    }
}
