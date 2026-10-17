<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Movements;
use CW\StockOps\CostHints;

/**
 * CW's opening stock and opening average cost, migrated FROM ERPNext (pack I; the owner's decision of 14 Oct 2026, which replaces the
 * opening from the stores' own figures of D40a; docs/decisions.md PI1-PI16). bin/import_erp_opening.php drives it; ErpOpeningFiles
 * reads the files; ErpOpeningRules holds the settings.
 *
 * Per CW item, at the warehouse erp_opening.cw_warehouse:
 *
 *     target = Σ ERPNext Balance Qty × u over the item's ERPNext lines in the warehouses (and companies) the rules allow
 *            − Σ units sold but not yet in ERPNext × u (the pending-sales file; when it is not given the report says the correction is open)
 *
 *   u: the ERPNext item's CW link (sku_erp_item.units_per_item), else the store listing its Product ID names (× its units_per_item).
 *   A target below zero opens at zero, or carries the minus (erp_opening.below_zero).
 *   The opening cost of a central unit: the ERPNext Valuation Rate ÷ u, averaged over the item's lines weighted by their units (the
 *   lines on the target's side, else any line with a real rate); blank or placeholder rates (erp_opening.placeholder_costs) are never
 *   used: an item with no other rate opens WITHOUT a cost and is listed for re-costing.
 *
 * Booked as ONE `adjustment` per item through Movements::recordOpening (actor system:opening_estimate, like every opening, so the
 * other opening tools see it as an earlier opening; cost_source `estimate`; Idempotency-Key "<doc_ref>:<sku_id>"), never through any
 * other writer: Stock.php's locks and buckets are untouched.
 *
 * Never guessed, listed instead: an ERPNext line that does not lead to exactly one CW item (unresolved, by reason); an item that was
 * counted, carries an opening under another doc_ref, or moved before the as-of moment (skipped: its ERPNext figure would double-count
 * or overwrite what CW knows). --replaces names ONE earlier opening (the stores' estimate of D40a) whose rows the ERPNext figure
 * replaces: the item's row then also takes them back (line 1, no cost) and an item of that opening the ERPNext file does not have is
 * skipped as `not_in_erp_file`.
 *
 * Re-runs: an item already booked under this doc_ref with the same figure and cost is left alone; a different figure or cost under
 * the same doc_ref refuses the whole run (opening_conflict): a different file is a different opening.
 *
 * The plan reads only (no write). apply() books item by item, each in its own transaction (a stop leaves whole items), and checks each item
 * again inside it after its balance is locked (OpeningGuard, PI17: another opening or a count since the plan refuses it, 409
 * opening_changed); a real run of the command holds the one opening lock all opening tools share (OpeningLock). At a store's T0 the
 * open paid units are added by the units-only rebase (OpeningRebase, PI14), which keeps this opening's figure.
 */
final class ErpOpening
{
    public const ACTOR_JOB = OpeningEstimate::ACTOR_JOB;
    public const DOC_TYPE = 'erp_opening';
    public const MOVEMENT_TYPE = 'adjustment';
    public const UNRESOLVED_REASONS = ['no_item_code', 'bad_qty', 'fractional_qty', 'ambiguous_erp_link', 'no_item_list', 'no_product_id', 'ambiguous_product_id',
        'no_listing_store', 'unknown_listing', 'unlinked_listing', 'quarantined_listing', 'merged_item', 'not_above_zero', 'no_reference'];
    public const SKIP_REASONS = ['counted', 'earlier_opening', 'moved_before_as_of', 'not_in_erp_file'];
    public const EXCLUDED_REASONS = ['blocked', 'other_company', 'other_warehouse'];
    /** Rows of each list kept in the report (all are counted). */
    public const DETAIL_ROWS = 500;
    private const CHUNK = 2000;

    public function __construct(private readonly Db $db, private readonly Movements $moves, private readonly ErpOpeningRules $rules)
    {
    }

    public static function actor(): string
    {
        return 'system:' . self::ACTOR_JOB;
    }

    /**
     * @param array<string, mixed> $stock   ErpOpeningFiles::stock()
     * @param array<string, mixed>|null $itemMap ErpOpeningFiles::itemMap()
     * @param array<string, mixed>|null $pending ErpOpeningFiles::pendingSales(); null = not given (the correction stays open)
     * @return array{items: array<int, array{target: int, earlier: int, unit_cost: ?string}>, report: array<string, mixed>,
     *               lines: list<array<string, mixed>>}  items: what is still to book, per sku_id; lines: every stock line's outcome
     */
    public function plan(array $stock, ?array $itemMap, ?array $pending, string $docRef, \DateTimeImmutable $asOf, ?string $replaces = null): array
    {
        if ($replaces !== null && $replaces === $docRef) {
            throw new CwException('bad_replaces', 'an opening cannot replace itself: --replaces names the EARLIER opening', 400);
        }
        $whId = $this->db->value('SELECT id FROM warehouse WHERE code = ?', [$this->rules->cwWarehouse]);
        if ($whId === null) {
            throw new CwException('bad_setting', "there is no CW warehouse {$this->rules->cwWarehouse} (erp_opening.cw_warehouse)", 409);
        }
        $this->checkFilters($stock['filters'] ?? [], $asOf);
        $resolver = new ErpItemResolver($this->db, $this->rules->listingStore, $itemMap === null ? null : $itemMap['map'], $itemMap['ambiguous'] ?? []);

        $report = [
            'file' => ['name' => $stock['file'], 'sha256' => $stock['sha256'], 'header_row' => $stock['header_row'], 'lines' => count($stock['lines']),
                'filters' => $stock['filters'], 'total_line' => $stock['total'] === null ? 'none' : $stock['total']['check'],
                'company_column' => in_array('company', $stock['columns'], true), 'value_column' => in_array('value', $stock['columns'], true)],
            'item_list' => $itemMap === null ? null : ['name' => $itemMap['file'], 'sha256' => $itemMap['sha256'], 'rows' => $itemMap['rows'],
                'with_product_id' => count($itemMap['map']), 'without_product_id' => $itemMap['without_product'], 'ambiguous' => count($itemMap['ambiguous'])],
            'rules' => $this->rules->toArray(), 'doc_ref' => $docRef, 'as_of' => $asOf->setTimezone(Clock::utc())->format('Y-m-d\TH:i:s\Z'),
            'replaces' => $replaces,
            'included' => ['rows' => 0, 'units' => 0, 'below_zero_rows' => 0, 'below_zero_units' => 0],
            'excluded' => array_fill_keys(self::EXCLUDED_REASONS, ['rows' => 0, 'units' => 0, 'names' => []]),
            'resolved' => ['rows' => 0, 'units' => 0, 'by_erp_link' => 0, 'by_listing' => 0],
            'unresolved' => ['rows' => 0, 'units' => 0, 'by_reason' => [], 'details' => []],
            'zero_lines' => 0,
            'uom' => [],
        ];
        $lineOut = [];
        /** @var array<int, list<array{q: int, u: int, rate: ?string, flag: ?string, row: int}>> $perSku */
        $perSku = [];
        $erpValue = '0';
        foreach ($stock['lines'] as $l) {
            $units = $l['qty'] ?? 0;
            $out = ['row' => $l['row'], 'item_code' => $l['item_code'], 'warehouse' => $l['warehouse'], 'company' => $l['company'],
                'qty' => $l['qty_raw'], 'rate' => $l['rate_raw']];
            $why = $this->excluded($l);
            if ($why !== null) {
                $e = &$report['excluded'][$why];
                $e['rows']++;
                $e['units'] += $units;
                $name = $why === 'other_company' ? (string) $l['company'] : $l['warehouse'];
                $e['names'][$name] = ($e['names'][$name] ?? 0) + 1;
                unset($e);
                $lineOut[] = $out + ['status' => 'excluded', 'reason' => $why];
                continue;
            }
            $report['included']['rows']++;
            $report['included']['units'] += $units;
            if ($units < 0) {
                $report['included']['below_zero_rows']++;
                $report['included']['below_zero_units'] += $units;
            }
            $res = $l['qty_error'] !== null ? ['reason' => $l['qty_error']] : $resolver->resolve($l['item_code']);
            if (isset($res['reason'])) {
                $this->unresolved($report['unresolved'], $res['reason'], ['row' => $l['row'], 'item_code' => $l['item_code'], 'warehouse' => $l['warehouse'],
                    'qty' => $l['qty_raw']] + (isset($res['product_id']) ? ['product_id' => $res['product_id']] : []), $units);
                $lineOut[] = $out + ['status' => 'unresolved', 'reason' => $res['reason']];
                continue;
            }
            $report['resolved']['rows']++;
            $report['resolved']['units'] += $units;
            $report['resolved'][$res['via'] === 'erp_link' ? 'by_erp_link' : 'by_listing']++;
            if ($l['uom'] !== null && $l['uom'] !== '') {
                $report['uom'][$l['uom']] = ($report['uom'][$l['uom']] ?? 0) + 1;
            }
            if ($l['value'] !== null) {
                $erpValue = bcadd($erpValue, $l['value'], 6);
            }
            $sku = $res['sku_id'];
            $perSku[$sku][] = ['q' => (int) $l['qty'], 'u' => $res['u'], 'rate' => $l['rate'], 'flag' => $l['rate_flag'], 'row' => $l['row'], 'code' => $l['item_code']];
            if ((int) $l['qty'] === 0) {
                $report['zero_lines']++;
            }
            $lineOut[] = $out + ['status' => 'resolved', 'reason' => '', 'sku_id' => $sku, 'u' => $res['u'], 'via' => $res['via']];
        }
        $report['erp_value'] = $report['file']['value_column'] ? bcadd($erpValue, '0', 2) : null;
        $report['unresolved']['details'] = array_slice($report['unresolved']['details'], 0, self::DETAIL_ROWS);

        // ---- the sales still on their way to ERPNext ------------------------------------------------------------------------------
        $pendingPerSku = [];
        if ($pending === null) {
            $report['pending_sales'] = ['given' => false, 'open' => true];
        } else {
            $p = ['given' => true, 'open' => false, 'name' => $pending['file'], 'sha256' => $pending['sha256'], 'by' => $pending['by'], 'rows' => 0, 'units' => 0,
                'subtracted' => ['rows' => 0, 'units' => 0, 'central_units' => 0, 'items' => 0],
                'unresolved' => ['rows' => 0, 'units' => 0, 'by_reason' => [], 'details' => []]];
            foreach ($pending['rows'] as $r) {
                $p['rows']++;
                $q = (int) ($r['qty'] ?? 0);
                $p['units'] += $q;
                $res = $r['error'] !== null ? ['reason' => $r['error']]
                    : ($pending['by'] === 'variant' ? $resolver->listing($r['ref']) : $resolver->resolve($r['ref']));
                if (isset($res['reason'])) {
                    $this->unresolved($p['unresolved'], $res['reason'], ['row' => $r['row'], 'ref' => $r['ref'], 'qty' => $r['qty']], $q);
                    continue;
                }
                $p['subtracted']['rows']++;
                $p['subtracted']['units'] += $q;
                $p['subtracted']['central_units'] += $q * $res['u'];
                $pendingPerSku[$res['sku_id']] = ($pendingPerSku[$res['sku_id']] ?? 0) + $q * $res['u'];
            }
            $p['subtracted']['items'] = count($pendingPerSku);
            $p['unresolved']['details'] = array_slice($p['unresolved']['details'], 0, self::DETAIL_ROWS);
            $report['pending_sales'] = $p;
        }

        // ---- per item: target, below zero, cost -----------------------------------------------------------------------------------
        $items = [];
        $below = ['lines' => ['rows' => 0, 'units' => 0], 'items' => ['items' => 0, 'units' => 0], 'policy' => $this->rules->belowZero,
            'wiped_units' => 0, 'carried_units' => 0, 'details' => []];
        $costs = ['lines_blank' => 0, 'lines_placeholder' => 0, 'lines_bad' => 0, 'recost' => ['items' => 0, 'units' => 0, 'details' => []],
            'mixed_items' => 0];
        $several = 0;
        foreach ($perSku as $lines) {
            foreach ($lines as $x) {
                if ($x['q'] < 0) {
                    $below['lines']['rows']++;
                    $below['lines']['units'] += $x['q'];
                }
                match (true) {
                    $x['flag'] === 'blank' => $costs['lines_blank']++,
                    $x['flag'] === 'bad' => $costs['lines_bad']++,
                    $x['rate'] !== null && $this->rules->isPlaceholder($x['rate']) => $costs['lines_placeholder']++,
                    default => null,
                };
            }
            if (count(array_unique(array_map(static fn (array $x): string => ErpOpeningRules::itemKey($x['code']), $lines))) > 1) {
                $several++;
            }
        }
        foreach (array_unique([...array_keys($perSku), ...array_keys($pendingPerSku)]) as $sku) {
            $lines = $perSku[$sku] ?? [];
            $raw = 0;
            foreach ($lines as $x) {
                $raw += $x['q'] * $x['u'];
            }
            $raw -= $pendingPerSku[$sku] ?? 0;
            $target = $raw;
            if ($raw < 0) {
                $below['items']['items']++;
                $below['items']['units'] += $raw;
                if ($this->rules->belowZero === 'zero') {
                    $below['wiped_units'] += -$raw;
                    $target = 0;
                } else {
                    $below['carried_units'] += $raw;
                }
                if (count($below['details']) < self::DETAIL_ROWS) {
                    $below['details'][] = ['sku_id' => $sku, 'erp_units' => $raw + ($pendingPerSku[$sku] ?? 0), 'pending' => $pendingPerSku[$sku] ?? 0,
                        'opens_at' => $target];
                }
            }
            [$cost, $mixed] = $this->cost($lines, $target);
            if ($target !== 0 && $cost === null) {
                $costs['recost']['items']++;
                $costs['recost']['units'] += $target;
                if (count($costs['recost']['details']) < self::DETAIL_ROWS) {
                    $costs['recost']['details'][] = ['sku_id' => $sku, 'units' => $target,
                        'lines' => array_map(static fn (array $x): array => ['row' => $x['row'], 'item_code' => $x['code'], 'rate' => $x['rate'] ?? $x['flag']], $lines)];
                }
            } elseif ($target !== 0 && $mixed) {
                $costs['mixed_items']++;
            }
            $items[$sku] = ['target' => $target, 'earlier' => 0, 'unit_cost' => $target === 0 ? null : $cost];
        }
        foreach ($items as $sku => $it) {
            if (abs($it['target']) > OpeningEstimate::MAX_ITEM_UNITS) {
                throw new CwException('too_many_units', "item {$sku} would open at {$it['target']} units (at most " . OpeningEstimate::MAX_ITEM_UNITS
                    . ' either way): look at its ERPNext lines', 422);
            }
        }
        $report['below_zero'] = $below;
        $report['costs'] = $costs;
        $report['items'] = ['items' => count($items), 'fed_by_several_erp_items' => $several];

        // ---- what CW already knows about each item ---------------------------------------------------------------------------------
        [$items, $report['skipped'], $report['already_booked'], $report['no_change']] = $this->history($items, $docRef, $asOf, $replaces, (int) $whId);

        $toBook = ['items' => 0, 'up' => 0, 'down' => 0, 'net' => 0, 'value' => '0.00', 'items_without_cost' => 0];
        foreach ($items as $sku => $it) {
            $net = $it['target'] - $it['earlier'];
            $toBook['items']++;
            $toBook[$net > 0 ? 'up' : 'down'] += abs($net);
            $toBook['net'] += $net;
            if ($it['target'] !== 0 && $it['unit_cost'] === null) {
                $toBook['items_without_cost']++;
            } elseif ($it['unit_cost'] !== null) {
                $toBook['value'] = bcadd($toBook['value'], CostHints::amount($it['target'], $it['unit_cost']), 2);
            }
        }
        $report['to_book'] = $toBook;
        $report['opening'] = ['items' => $toBook['items'] + $report['already_booked']['items'],
            'units' => array_sum(array_column($items, 'target')) + $report['already_booked']['units'],
            'value' => bcadd($toBook['value'], $report['already_booked']['value'], 2)];

        foreach ($lineOut as &$o) {
            $sku = $o['sku_id'] ?? null;
            if ($sku === null) {
                continue;
            }
            $o['outcome'] = isset($items[$sku]) ? 'to_book' : ($report['skipped']['why_by_item'][$sku] ?? (isset($report['already_booked']['skus'][$sku]) ? 'already_booked' : 'no_change'));
        }
        unset($o, $report['skipped']['why_by_item'], $report['already_booked']['skus']);
        ksort($items);
        return ['items' => $items, 'report' => $report, 'lines' => $lineOut, 'replaces' => $replaces];
    }

    /**
     * Books the plan: one recordOpening() per item (Idempotency-Key "<doc_ref>:<sku_id>"), the target as line 0 with its cost, the
     * earlier opening it replaces taken back as line 1.
     *
     * @param array{items: array<int, array{target: int, earlier: int, unit_cost: ?string}>, replaces?: ?string} $plan
     * @param ?\Closure(int, int): void $progress
     * @return array{booked_items: int, booked_units: int, replayed_items: int}
     */
    public function apply(Caller $caller, array $plan, string $docRef, string $note, ?\Closure $progress = null): array
    {
        $out = ['booked_items' => 0, 'booked_units' => 0, 'replayed_items' => 0];
        $n = 0;
        $total = count($plan['items']);
        // Re-checked inside each item's transaction (PI17): another opening or a count since the plan stops the run at that item.
        $warehouseId = (int) $this->db->value('SELECT id FROM warehouse WHERE code = ?', [$this->rules->cwWarehouse]);
        $replaces = $plan['replaces'] ?? null;
        $check = OpeningGuard::closure($this->db, [$docRef, $replaces], $warehouseId);
        $guard = function (array $skuIds) use ($check, $warehouseId, $docRef, $replaces): void {
            $check($skuIds);
            if ($replaces !== null) {
                foreach ($skuIds as $sku) {
                    OpeningLineage::record($this->db, $warehouseId, $sku, $docRef, $replaces);
                }
            }
        };
        foreach ($plan['items'] as $sku => $it) {
            $lines = [];
            if ($it['target'] !== 0) {
                $lines[] = ['sku_id' => (int) $sku, 'qty' => $it['target'], 'line_index' => 0] + ($it['unit_cost'] === null ? [] : ['unit_cost' => $it['unit_cost']]);
            }
            if ($it['earlier'] !== 0) {
                $lines[] = ['sku_id' => (int) $sku, 'qty' => -$it['earlier'], 'line_index' => 1];
            }
            if ($lines === []) {
                continue;
            }
            $r = $this->moves->recordOpening($caller, ['type' => self::MOVEMENT_TYPE, 'doc_ref' => $docRef, 'doc_type' => self::DOC_TYPE,
                'warehouse' => $this->rules->cwWarehouse, 'note' => $note, 'lines' => $lines], $docRef . ':' . $sku, $guard);
            if (!$r->ok()) {
                throw new CwException((string) ($r->body['error'] ?? 'opening_failed'),
                    sprintf('item %d (after %d booked): %s', $sku, $out['booked_items'], (string) ($r->body['message'] ?? json_encode($r->body))), $r->status);
            }
            if ($r->replayed) {
                $out['replayed_items']++;
            } else {
                $out['booked_items']++;
                $out['booked_units'] += $it['target'] - $it['earlier'];
            }
            $n++;
            if ($progress !== null && ($n % 1000 === 0 || $n === $total)) {
                $progress($n, $total);
            }
        }
        return $out;
    }

    // ---- the run record (erp_opening_run, 0039): written by --apply only; a dry run writes nothing -------------------------------

    /** @param array<string, string|null> $files */
    public function startRun(string $docRef, \DateTimeImmutable $asOf, array $files, string $actor): int
    {
        return $this->db->insert('INSERT INTO erp_opening_run (doc_ref, as_of, file_name, file_sha256, item_list_sha256, pending_sales_sha256, status, actor) '
            . "VALUES (?, ?, ?, ?, ?, ?, 'running', ?)",
            [$docRef, Clock::db($asOf), mb_substr((string) $files['name'], 0, 255), $files['sha256'], $files['item_list'], $files['pending_sales'], $actor]);
    }

    /** @param array<string, mixed> $summary */
    public function finishRun(int $id, string $status, array $summary): void
    {
        $this->db->exec('UPDATE erp_opening_run SET status = ?, summary = CAST(? AS JSON), finished_at = ? WHERE id = ?',
            [$status, Idempotency::json($summary), Clock::db(Clock::now()), $id]);
    }

    /** @return ?array<string, mixed> the last run that finished (or failed), with its summary decoded */
    public static function lastRun(Db $db): ?array
    {
        $r = $db->one("SELECT id, doc_ref, as_of, file_name, file_sha256, status, actor, started_at, finished_at, CAST(summary AS CHAR) s "
            . "FROM erp_opening_run WHERE status <> 'running' ORDER BY id DESC LIMIT 1");
        if ($r === null) {
            return null;
        }
        $r['summary'] = $r['s'] === null ? null : json_decode((string) $r['s'], true);
        unset($r['s']);
        return $r;
    }

    // ---- inside the plan ---------------------------------------------------------------------------------------------------------

    /** Why a line of the stock file is not read (blocked > company > warehouse), or null when it is. @param array<string, mixed> $l */
    private function excluded(array $l): ?string
    {
        $company = (string) ($l['company'] ?? '');
        if ($this->rules->blockedIn($l['warehouse']) !== null || ($company !== '' && $this->rules->blockedIn($company) !== null)) {
            return 'blocked';
        }
        if ($l['company'] !== null && !$this->rules->allowsCompany($company)) {
            return 'other_company';
        }
        return $this->rules->allowsWarehouse($l['warehouse']) ? null : 'other_warehouse';
    }

    /**
     * The export's own filter lines, when it was exported with them: the company filter must be one the rules read, and the report's
     * To Date must be the as-of moment's UK day (the figures are the balance at the end of that day as far as it had happened).
     *
     * @param array<string, string> $filters
     */
    private function checkFilters(array $filters, \DateTimeImmutable $asOf): void
    {
        $company = $filters['company'] ?? '';
        if ($company !== '' && ($this->rules->blockedIn($company) !== null || !$this->rules->allowsCompany($company))) {
            throw new CwException('wrong_company', "the stock file was exported for the company \"{$company}\", which is not read (erp_opening.companies, "
                . 'erp_opening.blocked_names): export it for the main company', 409);
        }
        $to = $filters['to_date'] ?? '';
        if ($to !== '') {
            $day = $asOf->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
            $toDay = \DateTimeImmutable::createFromFormat('!Y-m-d', $to) ?: \DateTimeImmutable::createFromFormat('!d-m-Y', $to);
            if ($toDay === false || $toDay->format('Y-m-d') !== $day) {
                throw new CwException('as_of_mismatch', "the stock file is the balance up to {$to}, but --as-of is on {$day} (UK): give the moment the "
                    . 'export was taken, on its To Date', 409);
            }
        }
    }

    /**
     * @param array{rows: int, units: int, by_reason: array<string, array{rows: int, units: int}>, details: list<array<string, mixed>>} $u
     * @param array<string, mixed> $detail
     */
    private function unresolved(array &$u, string $reason, array $detail, int $units): void
    {
        $u['rows']++;
        $u['units'] += $units;
        $u['by_reason'][$reason] ??= ['rows' => 0, 'units' => 0];
        $u['by_reason'][$reason]['rows']++;
        $u['by_reason'][$reason]['units'] += $units;
        if (count($u['details']) < self::DETAIL_ROWS) {
            $u['details'][] = $detail + ['reason' => $reason];
        }
    }

    /**
     * The opening cost of one central unit of an item, and whether placeholder lines were passed over for it.
     *
     * @param list<array{q: int, u: int, rate: ?string, flag: ?string}> $lines
     * @return array{0: ?string, 1: bool}
     */
    private function cost(array $lines, int $target): array
    {
        $real = [];
        $passed = false;
        foreach ($lines as $x) {
            if ($x['rate'] === null || $this->rules->isPlaceholder($x['rate'])) {
                $passed = $passed || $x['q'] !== 0;
                continue;
            }
            if ($x['q'] !== 0) {
                $real[] = $x;
            }
        }
        $side = array_values(array_filter($real, static fn (array $x): bool => $target >= 0 ? $x['q'] > 0 : $x['q'] < 0));
        $use = $side !== [] ? $side : $real;
        $value = '0';
        $units = 0;
        foreach ($use as $x) {
            // |q| units of the ERPNext item at its rate = |q| x u central units: the value is |q| x rate.
            $value = bcadd($value, bcmul((string) abs($x['q']), (string) $x['rate'], 6), 6);
            $units += abs($x['q']) * $x['u'];
        }
        if ($units === 0) {
            return [null, $passed];
        }
        return [CostHints::six(bcdiv($value, (string) $units, 7)), $passed];
    }

    /**
     * Sorts the planned items by what CW already holds for them.
     *
     * @param array<int, array{target: int, earlier: int, unit_cost: ?string}> $items
     * @return array{0: array<int, array{target: int, earlier: int, unit_cost: ?string}>, 1: array<string, mixed>, 2: array<string, mixed>, 3: int}
     */
    private function history(array $items, string $docRef, \DateTimeImmutable $asOf, ?string $replaces, int $whId): array
    {
        $actor = self::actor();
        $asOfDb = Clock::db($asOf);
        $blocked = [];
        $booked = [];
        $earlier = [];
        $skus = array_keys($items);
        // Every item already booked under this doc_ref is compared too (review of 14 Oct 2026, PI19): a file that leaves one out is not
        // the same opening, and the whole run is refused below (opening_conflict).
        foreach ($this->db->column("SELECT DISTINCT sku_id FROM stock_ledger WHERE actor = ? AND bucket = 'on_hand' AND doc_ref COLLATE utf8mb4_0900_bin = ?",
            [$actor, $docRef]) as $s) {
            $skus[] = (int) $s;
        }
        $skus = array_values(array_unique($skus));
        if ($replaces !== null) {
            // Every item of the replaced opening is looked at, also those the ERPNext file does not have.
            foreach ($this->db->column("SELECT DISTINCT sku_id FROM stock_ledger WHERE actor = ? AND bucket = 'on_hand' AND doc_ref COLLATE utf8mb4_0900_bin = ?",
                [$actor, $replaces]) as $s) {
                $skus[] = (int) $s;
            }
            $skus = array_values(array_unique($skus));
        }
        foreach (array_chunk($skus, self::CHUNK) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $other = [$docRef, $replaces ?? $docRef];
            foreach ($this->db->all(
                "SELECT id sku_id, 'counted' why FROM sku WHERE id IN ({$in}) AND counted_at IS NOT NULL
                 UNION SELECT sku_id, 'counted' FROM stock_balance WHERE sku_id IN ({$in}) AND counted_at IS NOT NULL
                 UNION SELECT sku_id, 'counted' FROM stock_ledger WHERE sku_id IN ({$in}) AND movement_type = 'count'
                 UNION SELECT sku_id, 'earlier_opening' FROM stock_ledger WHERE sku_id IN ({$in}) AND actor = ?
                       AND doc_ref COLLATE utf8mb4_0900_bin NOT IN (?, ?)
                 UNION SELECT sku_id, 'earlier_opening' FROM stock_ledger WHERE sku_id IN ({$in}) AND actor = ? AND bucket = 'on_hand' AND warehouse_id <> ?
                 UNION SELECT sku_id, 'moved_before_as_of' FROM stock_ledger WHERE sku_id IN ({$in}) AND bucket = 'on_hand'
                       AND actor <> ? AND created_at < ?",
                [...$chunk, ...$chunk, ...$chunk, ...$chunk, $actor, ...$other, ...$chunk, $actor, $whId, ...$chunk, $actor, $asOfDb],
            ) as $r) {
                $blocked[(int) $r['sku_id']][(string) $r['why']] = true;
            }
            foreach ($this->db->all(
                "SELECT sku_id, SUM(qty_delta) q, COUNT(DISTINCT unit_cost) nc, MAX(unit_cost) c
                   FROM stock_ledger WHERE sku_id IN ({$in}) AND actor = ? AND bucket = 'on_hand' AND doc_ref COLLATE utf8mb4_0900_bin = ? GROUP BY sku_id",
                [...$chunk, $actor, $docRef],
            ) as $r) {
                $booked[(int) $r['sku_id']] = ['q' => (int) $r['q'], 'cost' => $r['c'] === null ? null : (string) $r['c'], 'costs' => (int) $r['nc']];
            }
            if ($replaces !== null) {
                foreach ($this->db->all(
                    "SELECT sku_id, SUM(qty_delta) q FROM stock_ledger WHERE sku_id IN ({$in}) AND actor = ? AND bucket = 'on_hand'
                       AND doc_ref COLLATE utf8mb4_0900_bin = ? GROUP BY sku_id",
                    [...$chunk, $actor, $replaces],
                ) as $r) {
                    $earlier[(int) $r['sku_id']] = (int) $r['q'];
                }
            }
        }

        $skipped = ['items' => 0, 'units' => 0, 'why' => [], 'details' => [], 'why_by_item' => []];
        $skip = function (int $sku, array $why, int $target, int $earlierUnits) use (&$skipped): void {
            $skipped['items']++;
            $skipped['units'] += $target;
            foreach ($why as $w) {
                $skipped['why'][$w] = ($skipped['why'][$w] ?? 0) + 1;
            }
            $skipped['why_by_item'][$sku] = 'skipped:' . implode(',', $why);
            if (count($skipped['details']) < self::DETAIL_ROWS) {
                $skipped['details'][] = ['sku_id' => $sku, 'why' => $why, 'erp_target' => $target] + ($earlierUnits !== 0 ? ['earlier' => $earlierUnits] : []);
            }
        };
        foreach ($earlier as $sku => $e) {
            if (!isset($items[$sku]) && !isset($booked[$sku])) {
                $skip($sku, ['not_in_erp_file'], 0, $e);
            }
        }
        $already = ['items' => 0, 'units' => 0, 'value' => '0.00', 'skus' => []];
        $conflicts = [];
        $noChange = 0;
        foreach ($items as $sku => $it) {
            $it['earlier'] = $earlier[$sku] ?? 0;
            $items[$sku] = $it;
            $net = $it['target'] - $it['earlier'];
            if (isset($booked[$sku])) {
                $b = $booked[$sku];
                if ($b['q'] !== $net || $b['costs'] > 1 || ($b['cost'] === null) !== ($it['unit_cost'] === null)
                    || ($b['cost'] !== null && bccomp($b['cost'], (string) $it['unit_cost'], 6) !== 0)) {
                    $conflicts[] = ['sku_id' => $sku, 'booked' => $b['q'], 'booked_cost' => $b['cost'], 'planned' => $net, 'planned_cost' => $it['unit_cost']];
                } else {
                    $already['items']++;
                    $already['units'] += $it['target'];
                    $already['skus'][$sku] = true;
                    if ($it['unit_cost'] !== null) {
                        $already['value'] = bcadd($already['value'], CostHints::amount($it['target'], $it['unit_cost']), 2);
                    }
                }
                unset($items[$sku]);
                continue;
            }
            if (isset($blocked[$sku])) {
                $skip($sku, array_keys($blocked[$sku]), $it['target'], $it['earlier']);
                unset($items[$sku]);
                continue;
            }
            if ($net === 0 && $it['earlier'] === 0) {
                $noChange++;
                unset($items[$sku]);
            }
        }
        foreach ($booked as $sku => $b) {
            if (!array_key_exists($sku, $items) && !isset($already['skus'][$sku]) && !in_array($sku, array_column($conflicts, 'sku_id'), true)) {
                $conflicts[] = ['sku_id' => $sku, 'booked' => $b['q'], 'booked_cost' => $b['cost'], 'planned' => 0, 'planned_cost' => null];
            }
        }
        if ($conflicts !== []) {
            throw new CwException('opening_conflict', sprintf('%d item(s) were booked under this doc_ref with other figures or costs than this file gives: '
                . 'it is not the same opening (a deliberate new opening uses another doc_ref)', count($conflicts)), 409,
                ['items' => array_slice($conflicts, 0, 50)]);
        }
        $skipped['details'] = array_slice($skipped['details'], 0, self::DETAIL_ROWS);
        return [$items, $skipped, $already, $noChange];
    }
}
