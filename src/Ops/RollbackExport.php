<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Clock;
use CW\Db;
use CW\Movements;
use CW\Output\CsvWriter;
use CW\Queues\Corrections;
use CW\Queues\Suspense;
use CW\Settings;
use CW\Ui\Words;

/**
 * The rollback file (IM15; go-live plan step 5.2 and "the ERP way back" step 4; docs/decisions.md RB1-RB19): what CW booked since the
 * switch that ERPNext does not have, in ERPNext's Data Import layout, and the lists ERPNext needs to be made level if the switch is
 * rolled back. run() reads everything, the settings too, in ONE consistent snapshot (Snapshot::read) and writes nothing to the
 * database; bin/rollback_export.php writes the files.
 *
 * Which stock rows are carried (RB2, RB11, RB12, RB14, RB15): every on_hand row booked since the cut-off at a warehouse of
 * rollback.cw_warehouses, except
 *  - the website's order flow (reserve ... return, WEBSITE_TYPES): the website sends its own sales and returns to ERPNext; these rows
 *    are not even read, only counted;
 *  - rows a website (channel) booked: the ERP relay (goods in, supplier returns, sales ERPNext booked) and the website's cancels into
 *    VERIFY (`in_erpnext`, `from_website`);
 *  - a person's booking OF an ERPNext document waiting in a queue (its Idempotency-Key names the queue row, Corrections::KEY,
 *    Suspense::KEY, for the same item) (`in_erpnext`);
 *  - opening rows (actor system:opening_estimate) (`opening`); a join of duplicates and its undoing (JOIN_TYPES): ERPNext keeps both
 *    items and its website sales go on to the one it knows (`cw_join`);
 *  - a row booked at or before an ERPNext "set to this number" of its item and warehouse: ERPNext's figure was made whole then
 *    (`before_erp_correction`);
 *  - a type this file does not know (`unknown_type`): listed, never guessed.
 * A website's dispatch (or reset) that a count already took in books no on_hand row ("pre_count", Reservations::ship/unship), but the
 * website still tells ERPNext: its units are given back to the count that took them in (RB11), so the two add up to nothing.
 * The rows of one document and its reversal (or of one movement a person typed: its booker, type and Idempotency-Key) are added up per
 * item (RB4): what nets to nothing is not carried (`netted_out`); the rest becomes one line of a Material Receipt (more) or a Material
 * Issue (less), or of a Sales Invoice for a hand-over (RB5). Every row read is in movements.csv, with its entry or why it is not carried.
 *
 * Which ERPNext item a CW item is (RB3, RB10), never a guess: each ERPNext item code CW knows (its ERPNext links, sku_erp_item, and the
 * item list when given) is resolved exactly as the opening import resolves it (ErpItemResolver: the link first, else the item list's
 * Product ID through the store's match), with its units per ERPNext item. One code for the item: that one; several: the one of one unit,
 * if exactly one; else `ambiguous_erp_item`; none: `no_erp_item`. Units that are not a whole number of ERPNext items: `fractional_qty`.
 * A code the spreadsheet guard would change (it starts like a formula): `unsafe_text`. Each is listed with its units; nothing is carried.
 *
 * Memory (RB19): the rows are read twice in the same snapshot, by id in chunks: once to add them up per document and item, once to write
 * movements.csv; no row is kept between the two, only the sums.
 */
final class RollbackExport
{
    public const FORMAT = 1;
    /** The website's order flow (Words::MOVEMENT): not read, only counted. */
    public const WEBSITE_TYPES = ['reserve', 'release', 'expire', 'commit', 'commit_release', 'adopt', 'uncancel', 'cancel', 'ship', 'unship', 'return'];
    /** The types a person (or a CW job) books that ERPNext does not have. */
    public const CARRIED_TYPES = ['goods_in', 'supplier_return', 'adjustment', 'count', 'write_off', 'transfer_out', 'transfer_in', 'trade_sale', 'stock_out'];
    /** A join of duplicate items and its undoing (DecisionService::MERGE_MOVEMENTS): CW's own, never carried (RB14). */
    public const JOIN_TYPES = ['merge_in', 'merge_out', 'split_in', 'split_out'];
    /** Why a row is not carried (Words::ROLLBACK_REASON says each in words), in the order the counts list them. */
    public const REASONS = ['from_website', 'in_erpnext', 'opening', 'cw_join', 'other_warehouse', 'unknown_type', 'before_erp_correction',
        'count_before_cut', 'netted_out', 'no_erp_item', 'ambiguous_erp_item', 'fractional_qty', 'unsafe_text'];
    /** ERPNext posts in UK time. */
    public const ERP_ZONE = 'Europe/London';
    /** The ledger note of a dispatch or reset a count already took in (Reservations::ship, unship). */
    public const PRE_COUNT = 'pre_count';
    /** The hand-over document type (Sales Invoice in ERPNext). */
    private const HANDOVER_DOC = 'TRD';
    private const CHUNK = 5000;

    public const FILES = ['erpnext_stock_entry.csv', 'erpnext_sales_invoice.csv', 'to_set_by_hand.csv', 'movements.csv', 'selling_modes.csv',
        'purchase_orders.csv', 'suppliers.csv', 'customers.csv', 'items.csv'];
    /** The most stock rows one file reads when rollback.max_rows is not set (RB28). */
    public const DEFAULT_MAX_ROWS = 200_000;
    /** ERPNext's Data Import (Insert New Records): parent fields on an entry's first row, child fields as "Field (Items)". */
    public const STOCK_ENTRY_COLUMNS = [['Stock Entry Type', 'text'], ['Company', 'text'], ['Posting Date', 'text'], ['Posting Time', 'text'],
        ['Set Posting Time', 'number'], ['Remarks', 'text'], ['Item Code (Items)', 'text'], ['Qty (Items)', 'number'], ['Basic Rate (Items)', 'number'],
        ['Source Warehouse (Items)', 'text'], ['Target Warehouse (Items)', 'text']];
    public const SALES_INVOICE_COLUMNS = [['Customer', 'text'], ['Company', 'text'], ['Posting Date', 'text'], ['Posting Time', 'text'],
        ['Set Posting Time', 'number'], ['Update Stock', 'number'], ['Remarks', 'text'], ['Item Code (Items)', 'text'], ['Qty (Items)', 'number'],
        ['Rate (Items)', 'number'], ['Warehouse (Items)', 'text']];

    /** @var array<int, string> warehouse id => code */
    private array $warehouses = [];
    /** @var array<int, true> the carried warehouses by id */
    private array $carried = [];
    /** @var array<int, list<array{code: string, u: int, via: string, pid: ?string}>> sku_id => the ERPNext items the opening import makes it */
    private array $candidates = [];
    /** @var array<string, array<int, true>> ErpOpeningRules::itemKey(code) => the CW items CW's own links give it (two: a conflict, RB30) */
    private array $linkOwners = [];
    /** @var array<string, int> a store variant => the joined item it came with in a join (its link opened by that join, RB29) */
    private array $cameWith = [];
    /** @var array<string, true> a store variant whose join history cannot be followed (a split to a new item, a join undone not found): RB34 */
    private array $historyUnclear = [];
    /** @var array<int, list<array{code: string, u: int}>> a joined (merged) item => its own ERPNext links, which the opening no longer reads */
    private array $mergedLinks = [];
    /** @var array<int, string> sku_id => why ERPNext's figure of it cannot be made level by entries: it is listed to be set by hand (RB24) */
    private array $settle = [];
    /** @var array<int, list<string>> sku_id => the store's variants matched to it */
    private array $variants = [];
    /** @var array<int, array{code: ?string, name: string}> */
    private array $skus = [];
    /** @var array<string, array{sku: int, actor: string, type: string, wh: ?int}> a queue booking's Idempotency-Key => what that booking is */
    private array $queueKeys = [];
    /** @var array<int, array<int, string>> warehouse id => sku_id => the latest moment ERPNext set the item's figure (UTC) */
    private array $erpSet = [];
    /** @var array<int, array<int, array{family: ?string, reason: ?string, at: string}>> while reading: the latest count of each item */
    private array $lastCount = [];
    /** @var array<string, array{types: array<string, true>, docs: array<string, true>}> each carried family's movement types and documents */
    private array $families = [];

    /**
     * @param list<string> $cwWarehouses   CW warehouse codes whose stock ERPNext holds (rollback.cw_warehouses)
     * @param array{map: array<string, string>, codes: array<string, string>, ambiguous: array<string, list<string>>}|null $itemMap
     *        the ERPNext item list (ErpOpeningFiles::itemMap); null = not given (only CW's ERPNext links name ERPNext items)
     */
    public function __construct(private readonly Db $db, public readonly array $cwWarehouses, public readonly string $erpWarehouse,
        public readonly string $erpCompany, public readonly string $store, private readonly ?array $itemMap, public readonly int $maxRows = self::DEFAULT_MAX_ROWS)
    {
        if ($cwWarehouses === [] || $erpWarehouse === '' || $erpCompany === '') {
            throw new \InvalidArgumentException('the rollback file needs rollback.cw_warehouses, rollback.erp_warehouse and rollback.erp_company');
        }
        foreach ([$erpWarehouse, $erpCompany] as $name) {
            if (CsvWriter::safeText($name) !== $name) {
                throw new \InvalidArgumentException('rollback.erp_warehouse and rollback.erp_company cannot start like a spreadsheet formula');
            }
        }
    }

    /** @param array<string, mixed>|null $itemMap see the constructor */
    public static function fromSettings(Db $db, Settings $s, ?array $itemMap): self
    {
        $text = static fn (string $k): string => $s->has($k) ? trim((string) $s->get($k)) : '';
        $codes = array_values(array_filter(array_map('trim', explode(',', $text('rollback.cw_warehouses'))), static fn (string $c): bool => $c !== ''));
        $max = $s->has('rollback.max_rows') ? (int) $s->get('rollback.max_rows') : self::DEFAULT_MAX_ROWS;
        return new self($db, $codes, $text('rollback.erp_warehouse'), $text('rollback.erp_company'), $text('erp_opening.listing_store'), $itemMap, $max);
    }

    /**
     * The whole export in ONE consistent snapshot (RB1, RB17): the moment, the cut-off, the settings, the files.
     *
     * @param array<string, mixed>|null $itemMap ErpOpeningFiles::itemMap(), or null
     * @param ?string $since UTC 'Y-m-d H:i:s.u'; null = the as-of moment of the EARLIEST finished ERPNext opening (RB13)
     * @return array{made_at: string, cut: array{since: string, from: string, runs: list<array{id: int, doc_ref: string, as_of: string}>},
     *               settings: array{cw_warehouses: list<string>, erp_warehouse: string, erp_company: string, store: string},
     *               files: array<string, string>, rows: array<string, int>, counts: array<string, mixed>}
     */
    public static function run(Db $db, ?array $itemMap, ?string $since): array
    {
        return Snapshot::read($db, static function (Db $db) use ($itemMap, $since): array {
            $madeAt = (string) $db->value('SELECT NOW(6)');
            $runs = self::openingRuns($db);
            if ($since === null && $runs === []) {
                throw new \InvalidArgumentException('no ERPNext opening has finished: give --since=<UTC time>');
            }
            $cut = $since !== null ? ['since' => $since, 'from' => 'given'] : ['since' => $runs[0]['as_of'], 'from' => 'erp_opening_run ' . $runs[0]['id']];
            $x = self::fromSettings($db, new Settings($db), $itemMap);
            return ['made_at' => $madeAt, 'cut' => $cut + ['runs' => $runs], 'settings' => ['cw_warehouses' => $x->cwWarehouses,
                'erp_warehouse' => $x->erpWarehouse, 'erp_company' => $x->erpCompany, 'store' => $x->store]] + $x->build($cut['since']);
        });
    }

    /**
     * Every finished ERPNext opening (pack I), the earliest moment first (RB13): the first is the switch; a later one only books items
     * that had not moved, so it never moves the cut-off.
     *
     * @return list<array{id: int, doc_ref: string, as_of: string}>
     */
    public static function openingRuns(Db $db): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'doc_ref' => (string) $r['doc_ref'], 'as_of' => (string) $r['as_of']],
            $db->all("SELECT id, doc_ref, as_of FROM erp_opening_run WHERE status = 'done' ORDER BY as_of, id"));
    }

    /**
     * Everything the files hold. Read only; run() calls it inside its snapshot.
     *
     * @param string $since UTC 'Y-m-d H:i:s[.u]': rows booked at or after it
     * @return array{files: array<string, string>, rows: array<string, int>, counts: array<string, mixed>}
     */
    public function build(string $since): array
    {
        foreach ($this->db->all('SELECT id, code FROM warehouse') as $w) {
            $this->warehouses[(int) $w['id']] = (string) $w['code'];
        }
        foreach ($this->cwWarehouses as $code) {
            $id = array_search($code, $this->warehouses, true);
            if ($id === false) {
                throw new \InvalidArgumentException("rollback.cw_warehouses names {$code}, which is not a CW warehouse");
            }
            $this->carried[(int) $id] = true;
        }
        $this->settle = [];
        $this->loadItems();
        $notTaken = $this->loadErpnextFacts($since);
        $website = [];
        $web = implode(', ', array_fill(0, count(self::WEBSITE_TYPES), '?'));
        foreach ($this->db->all("SELECT movement_type t, COUNT(*) n FROM stock_ledger WHERE created_at >= ? AND bucket = 'on_hand' AND movement_type IN ({$web}) "
            . 'GROUP BY movement_type ORDER BY movement_type', [$since, ...self::WEBSITE_TYPES]) as $r) {
            $website[(string) $r['t']] = (int) $r['n'];
        }
        [$sums, $read] = $this->addUp($since);
        [$entries, $outcome] = $this->entries($sums);
        // The rows of each family's item share its outcome: carried in an entry, or the reason it is not.
        foreach ($sums as $f => $items) {
            foreach ($items as $sku => $s) {
                isset($outcome[$f][$sku]['reason']) ? $read['reasons'][$outcome[$f][$sku]['reason']] += $s['rows'] : $read['carried'] += $s['rows'];
            }
        }
        $rowCount = [];
        $files = [];
        foreach ([
            'erpnext_stock_entry.csv' => $this->stockEntryFile($entries),
            'erpnext_sales_invoice.csv' => $this->salesInvoiceFile($entries),
            'to_set_by_hand.csv' => $this->handFile(),
            'movements.csv' => $this->movementsFile($since, $outcome),
            'selling_modes.csv' => $this->modesFile(),
            'purchase_orders.csv' => $this->ordersFile($since),
            'suppliers.csv' => $this->suppliersFile($since),
            'customers.csv' => $this->customersFile($since),
            'items.csv' => $this->itemsFile($since),
        ] as $name => [$csv, $n]) {
            $files[$name] = $csv;
            $rowCount[$name] = $n;
        }
        $kinds = ['material_receipt' => 0, 'material_issue' => 0, 'sales_invoice' => 0, 'receipt_lines_without_rate' => 0];
        foreach ($entries as $e) {
            $kinds[$e['kind'] === 'si' ? 'sales_invoice' : ($e['in'] ? 'material_receipt' : 'material_issue')]++;
            foreach ($e['lines'] as $l) {
                $kinds['receipt_lines_without_rate'] += $e['kind'] === 'se' && $e['in'] && $l['rate'] === null ? 1 : 0;
            }
        }
        return ['files' => $files, 'rows' => $rowCount, 'counts' => ['rows_read' => $read['rows'], 'rows_carried' => $read['carried'],
            'not_carried' => $read['reasons'], 'website_rows' => $website, 'entries' => $kinds, 'erp_corrections_not_taken' => $notTaken,
            'to_set_by_hand' => $rowCount['to_set_by_hand.csv']]];
    }

    /**
     * The ERPNext items of each CW item (RB3, RB10): every code CW knows, resolved as the opening import resolves it (one code, one
     * CW item and its units); and the store's matched variants (the modes and items lists).
     */
    private function loadItems(): void
    {
        $codes = [];
        foreach ($this->db->all('SELECT item_code, sku_id FROM sku_erp_item ORDER BY item_code') as $r) {
            $key = ErpOpeningRules::itemKey((string) $r['item_code']);
            $codes[$key] ??= trim((string) $r['item_code']);
            $this->linkOwners[$key][(int) $r['sku_id']] = true;
        }
        foreach ($this->itemMap['codes'] ?? [] as $key => $code) {
            $codes[(string) $key] ??= trim((string) $code);
        }
        $resolver = new ErpItemResolver($this->db, $this->store, $this->itemMap['map'] ?? null, $this->itemMap['ambiguous'] ?? []);
        foreach ($codes as $key => $code) {
            $r = $key === '' ? ['reason' => 'no_item_code'] : $resolver->resolve($code);
            if (isset($r['sku_id'])) {
                $this->candidates[(int) $r['sku_id']][] = ['code' => $code, 'u' => (int) $r['u'], 'via' => (string) $r['via'],
                    'pid' => $r['via'] === 'listing' ? ($this->itemMap['map'][$key] ?? null) : null];
            }
        }
        foreach ($this->db->all('SELECT e.item_code, e.sku_id, e.units_per_item FROM sku_erp_item e JOIN sku s ON s.id = e.sku_id '
            . 'WHERE s.merged_into_sku_id IS NOT NULL ORDER BY TRIM(e.item_code), e.item_code') as $r) {
            $this->mergedLinks[(int) $r['sku_id']][] = ['code' => trim((string) $r['item_code']), 'u' => (int) $r['units_per_item']];
        }
        if ($this->store === '') {
            return;
        }
        foreach ($this->db->all("SELECT l.external_variant_id v, l.sku_id FROM channel_listing l JOIN channel c ON c.id = l.channel_id "
            . "WHERE c.code = ? AND l.status = 'mapped' AND l.sku_id IS NOT NULL ORDER BY l.id", [$this->store]) as $r) {
            $this->variants[(int) $r['sku_id']][] = trim((string) $r['v']);
        }
        // A listing whose present link a join opened (listing_map_history, DecisionService::openPeriod) belonged to the joined item before;
        // through a chain of joins (A into B, then B into C) it goes back, period by period, to the item of the first join (RB29, RB33),
        // stepping over a join that was undone (a split back to the former item: back to the period before the join it undoes, RB34).
        $periods = [];
        foreach ($this->db->all("SELECT l.external_variant_id v, l.sku_id now, h.sku_id, h.decision_id, CAST(d.action AS CHAR) action, CAST(d.state AS CHAR) state, "
            . 'd.merge_from_sku_id f, CAST(d.detail AS CHAR) detail FROM channel_listing l JOIN channel c ON c.id = l.channel_id '
            . 'JOIN listing_map_history h ON h.listing_id = l.id LEFT JOIN match_decision d ON d.id = h.decision_id WHERE c.code = ? AND l.id IN '
            . "(SELECT h2.listing_id FROM listing_map_history h2 JOIN match_decision d2 ON d2.id = h2.decision_id WHERE d2.action IN ('merge_skus', 'split')) "
            . 'ORDER BY l.id, h.valid_from DESC, h.id DESC', [$this->store]) as $r) {
            $periods[trim((string) $r['v'])][] = $r;
        }
        foreach ($periods as $v => $ps) {
            [$origin, $clear] = self::firstOwner($ps);
            if (!$clear) {
                $this->historyUnclear[$v] = true;
            } elseif ($origin !== null && $origin !== (int) $ps[0]['now']) {
                $this->cameWith[$v] = $origin;
            }
        }
    }

    /**
     * What ERPNext made (RB12, RB15): the Idempotency-Keys of the people's bookings of ERPNext queue rows, and each item's latest
     * "set to this number" from ERPNext at a carried warehouse. ERPNext's figure is whole from that moment whatever CW answered; the ones
     * CW did not take (still open, or dismissed) are named, since ERPNext's figure of that item is then not CW's.
     *
     * @return list<array{ref: string, item: string, warehouse: string, at: string, status: string}> the ones CW did not take
     */
    private function loadErpnextFacts(string $since): array
    {
        // A queue booking is the person who settled the row, booking its type for its item (and place): a job or another booking under
        // the same key is not it (RB21).
        foreach ($this->db->all("SELECT id, sku_id, warehouse_id, resolved_by FROM count_review WHERE source = ? AND status = 'resolved' AND resolved_by IS NOT NULL",
            [Corrections::SOURCE]) as $r) {
            $this->queueKeys[Corrections::KEY . (int) $r['id']] = ['sku' => (int) $r['sku_id'], 'actor' => 'staff:' . (int) $r['resolved_by'], 'type' => 'adjustment',
                'wh' => (int) $r['warehouse_id']];
        }
        foreach ($this->db->all("SELECT id, resolved_sku_id, resolved_by, movement_type FROM goods_in_suspense WHERE source = 'erp_relay' AND status = 'resolved' "
            . 'AND resolved_sku_id IS NOT NULL AND resolved_by IS NOT NULL') as $r) {
            $this->queueKeys[Suspense::KEY . (int) $r['id']] = ['sku' => (int) $r['resolved_sku_id'], 'actor' => 'staff:' . (int) $r['resolved_by'],
                'type' => (string) $r['movement_type'], 'wh' => null];
        }
        $notTaken = [];
        foreach ($this->db->all('SELECT r.warehouse_id, r.sku_id, r.ref, CAST(r.status AS CHAR) status, CAST(r.detail AS CHAR) detail, r.created_at, s.code '
            . 'FROM count_review r JOIN sku s ON s.id = r.sku_id WHERE r.source = ? ORDER BY r.id', [Corrections::SOURCE]) as $r) {
            $wh = (int) $r['warehouse_id'];
            if (!isset($this->carried[$wh])) {
                continue;
            }
            $detail = json_decode((string) $r['detail'], true);
            $at = Corrections::at(['detail' => is_array($detail) ? $detail : [], 'created_at' => $r['created_at']]);
            $sku = (int) $r['sku_id'];
            if (!isset($this->erpSet[$wh][$sku]) || $at > $this->erpSet[$wh][$sku]) {
                $this->erpSet[$wh][$sku] = $at;
            }
            if ($r['status'] !== 'resolved' && $at >= $since) {
                $this->settle[$sku] ??= 'erp_correction_not_taken';
                $notTaken[] = ['ref' => (string) $r['ref'], 'item' => (string) $r['code'], 'warehouse' => $this->warehouses[$wh], 'at' => (string) Clock::iso($at),
                    'status' => (string) $r['status']];
            }
        }
        return $notTaken;
    }

    /**
     * The rows since the cut-off, by id in chunks (the same reading for both passes): the on_hand rows outside the website's order flow,
     * and the website's dispatches and resets a count already took in (RB11).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function read(string $since): \Generator
    {
        $web = implode(', ', array_fill(0, count(self::WEBSITE_TYPES), '?'));
        $first = $this->db->value('SELECT MIN(id) FROM stock_ledger WHERE created_at >= ?', [$since]);
        $after = $first === null ? null : (int) $first - 1;
        while ($after !== null) {
            $chunk = $this->db->all('SELECT l.id, l.warehouse_id, l.sku_id, CAST(l.bucket AS CHAR) bucket, l.qty_delta, l.movement_type, l.doc_ref, l.idem_key, '
                . 'l.effective_at, l.actor, l.unit_cost, l.document_id, l.created_at, d.number, d.doc_type, d.reverses_id, v.unit_cost AS value_cost, '
                . 'dl.unit_cost AS line_price FROM stock_ledger l LEFT JOIN document d ON d.id = l.document_id '
                . 'LEFT JOIN stock_value_ledger v ON v.movement_ledger_id = l.id '
                . 'LEFT JOIN document_line dl ON dl.document_id = l.document_id AND dl.line_no = l.document_line '
                . "WHERE l.id > ? AND l.created_at >= ? AND ((l.bucket = 'on_hand' AND l.movement_type NOT IN ({$web})) "
                . "OR (l.bucket = 'allocated' AND l.movement_type IN ('ship', 'unship') AND l.note = ?)) ORDER BY l.id LIMIT " . self::CHUNK,
                [$after, $since, ...self::WEBSITE_TYPES, self::PRE_COUNT]);
            foreach ($chunk as $r) {
                yield $r;
            }
            $after = count($chunk) === self::CHUNK ? (int) $chunk[count($chunk) - 1]['id'] : null;
        }
    }

    /**
     * One row's fate (RB2): why it is not carried, or the family (one document and its reversal, or one typed movement) and the
     * units it carries. Rows are given in id order; a count is remembered for the website events it took in (RB11).
     *
     * @param array<string, mixed> $r
     * @return array{reason: ?string, family: ?string, qty: int, pre: bool}
     */
    private function classify(array $r): array
    {
        $type = (string) $r['movement_type'];
        $actor = (string) $r['actor'];
        $wh = (int) $r['warehouse_id'];
        $sku = (int) $r['sku_id'];
        $pre = $r['bucket'] === 'allocated';
        if ($pre) {
            // A dispatch (allocated -u) or reset (+u) the count took in: the count's on_hand change holds its units, the website tells
            // ERPNext itself, so the units go back to that count (they add up to nothing with it).
            $count = $this->lastCount[$wh][$sku] ?? null;
            $at = (string) ($r['effective_at'] ?? $r['created_at']);
            $reason = match (true) {
                !isset($this->carried[$wh]) => 'other_warehouse',
                $count === null || $count['at'] < $at => 'count_before_cut',
                default => $count['reason'],
            };
            if ($reason === 'count_before_cut') {
                $this->settle[$sku] ??= 'count_before_cut';
            }
            return ['reason' => $reason, 'family' => $reason === null ? $count['family'] : null, 'qty' => -(int) $r['qty_delta'], 'pre' => true];
        }
        $family = $r['document_id'] !== null ? 'd' . (int) ($r['reverses_id'] ?? $r['document_id'])
            : 'k' . $actor . "\n" . $type . "\n" . ($r['idem_key'] ?? 'row' . (int) $r['id']);
        $reason = match (true) {
            str_starts_with($actor, 'channel:') => in_array($type, Movements::CHANNEL_TYPES, true) ? 'in_erpnext' : 'from_website',
            $type === 'erp_sale', $r['document_id'] === null && $this->isQueueBooking($r) => 'in_erpnext',
            $actor === 'system:' . OpeningEstimate::ACTOR_JOB, $type === 'opening' => 'opening',
            in_array($type, self::JOIN_TYPES, true) => 'cw_join',
            !isset($this->carried[$wh]) => 'other_warehouse',
            !in_array($type, self::CARRIED_TYPES, true) => 'unknown_type',
            (string) $r['created_at'] <= ($this->erpSet[$wh][$sku] ?? '') => 'before_erp_correction',
            default => null,
        };
        if ($reason === 'cw_join') {
            $this->settle[$sku] ??= 'cw_join';
        }
        if ($type === 'count' && isset($this->carried[$wh])) {
            $this->lastCount[$wh][$sku] = ['family' => $reason === null ? $family : null, 'reason' => $reason,
                'at' => (string) ($r['effective_at'] ?? $r['created_at'])];
        }
        return ['reason' => $reason, 'family' => $reason === null ? $family : null, 'qty' => (int) $r['qty_delta'], 'pre' => false];
    }

    /** @param array<string, mixed> $r a ledger row without a document: is it a person's booking of an ERPNext queue row (RB15, RB21)? */
    private function isQueueBooking(array $r): bool
    {
        $q = $this->queueKeys[(string) $r['idem_key']] ?? null;
        return $q !== null && $q['sku'] === (int) $r['sku_id'] && $q['actor'] === (string) $r['actor'] && $q['type'] === (string) $r['movement_type']
            && ($q['wh'] === null || $q['wh'] === (int) $r['warehouse_id']);
    }

    /**
     * The first pass: each family's units per item, with what its entry needs (RB4), and what was read.
     *
     * @return array{0: array<string, array<int, array<string, mixed>>>, 1: array{rows: int, carried: int, reasons: array<string, int>}}
     */
    private function addUp(string $since): array
    {
        $this->lastCount = [];
        $this->families = [];
        $sums = [];
        $read = ['rows' => 0, 'carried' => 0, 'reasons' => array_fill_keys(self::REASONS, 0)];
        $skus = [];
        // Counted first, so a window too big stops before a row is read (RB31); the rows it allows get the memory they need.
        $web = implode(', ', array_fill(0, count(self::WEBSITE_TYPES), '?'));
        $rows = (int) $this->db->value("SELECT COUNT(*) FROM stock_ledger l WHERE l.created_at >= ? AND ((l.bucket = 'on_hand' AND l.movement_type NOT IN ({$web})) "
            . "OR (l.bucket = 'allocated' AND l.movement_type IN ('ship', 'unship') AND l.note = ?))", [$since, ...self::WEBSITE_TYPES, self::PRE_COUNT]);
        if ($rows > $this->maxRows) {
            throw new \RuntimeException("the window holds {$rows} stock rows, more than rollback.max_rows ({$this->maxRows}): raise the setting if the machine "
                . 'has the memory (about 5 KB a carried row, RB22), or give a later --since');
        }
        self::makeRoom($rows);
        foreach ($this->read($since) as $r) {
            if (++$read['rows'] > $this->maxRows) {
                throw new \RuntimeException("the window holds more than rollback.max_rows ({$this->maxRows}) stock rows: raise the setting if the machine has "
                    . 'the memory (about 5 KB a carried row, RB22), or give a later --since');
            }
            $skus[(int) $r['sku_id']] = true;
            $c = $this->classify($r);
            if ($c['reason'] !== null) {
                $read['reasons'][$c['reason']]++;
                continue;
            }
            // One small array per family and item (the sums), the family's types and documents once (RB19).
            $f = (string) $c['family'];
            $this->families[$f]['types'][(string) $r['movement_type'] . ($c['pre'] ? ' (' . self::PRE_COUNT . ')' : '')] = true;
            if (!$c['pre'] && (string) ($r['number'] ?? $r['doc_ref'] ?? '') !== '') {
                $this->families[$f]['docs'][(string) ($r['number'] ?? $r['doc_ref'])] = true;
            }
            $s = &$sums[$f][(int) $r['sku_id']];
            $s ??= ['net' => 0, 'first' => (int) $r['id'], 'at' => '', 'doc_type' => $r['doc_type'], 'rows' => 0,
                'in_units' => 0, 'in_value' => '0', 'out_units' => 0, 'out_value' => '0'];
            $s['net'] += $c['qty'];
            $s['rows']++;
            $at = (string) ($r['effective_at'] ?? $r['created_at']);
            $s['at'] = $s['at'] === '' || $at < $s['at'] ? $at : $s['at'];
            // A receipt's rate: the cost of the units that came in; a hand-over's price: the units that went out (RB4, RB5).
            $cost = $c['qty'] > 0 ? ($r['value_cost'] ?? $r['unit_cost']) : $r['line_price'];
            if ($cost !== null && !$c['pre']) {
                $side = $c['qty'] > 0 ? 'in' : 'out';
                $s[$side . '_units'] += abs($c['qty']);
                $s[$side . '_value'] = bcadd($s[$side . '_value'], bcmul((string) abs($c['qty']), (string) $cost, 6), 6);
            }
            unset($s);
        }
        $this->loadSkus(array_keys($skus));
        return [$sums, $read];
    }

    /**
     * The item a listing's code belonged to before its joins (RB33, RB34): its periods, newest first, walked back while each was opened by
     * an applied join (it names the item it came from) and follows on from the one before it; a split back to the former item steps over
     * the periods back to the join it undoes. A period a person's decision opened ends the walk. Not clear (false): a split to a new item,
     * or the join a split undoes not found among the periods.
     *
     * @param list<array<string, mixed>> $ps the listing's periods, newest first
     * @return array{0: ?int, 1: bool} the first owner (null: no join), and whether the history was followed to its end
     */
    private static function firstOwner(array $ps): array
    {
        $origin = null;
        $expect = null;
        $skip = null;
        foreach ($ps as $h) {
            $sku = (int) $h['sku_id'];
            if ($skip !== null) {
                if ((int) $h['decision_id'] === $skip) {
                    $skip = null;
                }
                continue;
            }
            if ($expect !== null && $sku !== $expect) {
                break;
            }
            if ($h['action'] === 'split' && $h['state'] === 'applied') {
                $d = json_decode((string) $h['detail'], true);
                $undoes = is_array($d) ? (int) ($d['undoes_decision_id'] ?? 0) : 0;
                if (!is_array($d) || ($d['split_to'] ?? 'former') !== 'former' || $undoes === 0) {
                    return [$origin, false];
                }
                $skip = $undoes;
                $expect = $sku;
                continue;
            }
            if ($h['action'] !== 'merge_skus' || $h['state'] !== 'applied' || $h['f'] === null) {
                break;
            }
            $origin = (int) $h['f'];
            $expect = $origin;
        }
        return [$origin, $skip === null];
    }

    /** PHP's memory limit raised, when it is set lower, to what $rows rows may need (RB22: about 5 KB a carried row, and room to start). */
    private static function makeRoom(int $rows): void
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return;
        }
        $unit = strtoupper(substr($limit, -1));
        $bytes = (int) $limit * match ($unit) { 'G' => 1 << 30, 'M' => 1 << 20, 'K' => 1 << 10, default => 1 };
        $need = (256 << 20) + $rows * 6144;
        if ($bytes < $need) {
            ini_set('memory_limit', (string) $need);
        }
    }

    /** @param list<int> $ids */
    private function loadSkus(array $ids): void
    {
        $ids = array_values(array_diff($ids, array_keys($this->skus)));
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->db->all('SELECT id, code, name FROM sku WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $s) {
                $this->skus[(int) $s['id']] = ['code' => $s['code'] === null ? null : (string) $s['code'], 'name' => (string) $s['name']];
            }
        }
    }

    /**
     * The ERPNext item of a CW item for stock lines (RB3).
     *
     * @return array{code: string, u: int}|array{reason: string}
     */
    private function erpItem(int $sku): array
    {
        $c = $this->candidates[$sku] ?? [];
        if ($c === []) {
            return ['reason' => 'no_erp_item'];
        }
        if (count($c) > 1) {
            // Of several: the one of one unit, when exactly one is. A link says how ITS code maps into CW, not which of two ERPNext items
            // of one unit is the item's: two of one unit are never chosen between (RB25).
            $c = array_values(array_filter($c, static fn (array $x): bool => $x['u'] === 1));
            if (count($c) !== 1) {
                return ['reason' => 'ambiguous_erp_item'];
            }
        }
        return CsvWriter::safeText($c[0]['code']) === $c[0]['code'] ? ['code' => $c[0]['code'], 'u' => $c[0]['u']] : ['reason' => 'unsafe_text'];
    }

    /**
     * The entries of the two ERPNext files (RB4, RB5) and each family's outcome per item (its entry, or why not).
     *
     * @param array<string, array<int, array<string, mixed>>> $sums
     * @return array{0: list<array{no: int, kind: string, in: bool, at: string, remarks: string, customer: ?string,
     *                                lines: list<array{code: string, qty: int, rate: ?string}>}>,
     *               1: array<string, array<int, array{reason?: string, entry?: int, code?: string, u?: int}>>}
     */
    private function entries(array $sums): array
    {
        $customers = $this->customers(array_keys($sums));
        $outcome = [];
        $entries = [];
        foreach ($sums as $f => $items) {
            $label = implode(', ', array_keys($this->families[$f]['docs'] ?? []));
            $types = $this->families[$f]['types'] ?? [];
            foreach ($items as $sku => $s) {
                $net = (int) $s['net'];
                $item = $net === 0 ? ['reason' => 'netted_out'] : $this->erpItem($sku);
                if (!isset($item['reason']) && $net % $item['u'] !== 0) {
                    $item = ['reason' => 'fractional_qty'];
                }
                if (isset($item['reason'])) {
                    $outcome[$f][$sku] = $item;
                    continue;
                }
                $in = $net > 0;
                // A hand-over's units out are a Sales Invoice to its customer (RB5); a name the spreadsheet guard would change is not.
                $customer = !$in && $s['doc_type'] === self::HANDOVER_DOC ? ($customers[$f] ?? null) : null;
                $kind = $customer !== null && CsvWriter::safeText($customer) === $customer ? 'si' : 'se';
                $key = $f . '|' . $kind . '|' . ($in ? 'in' : 'out');
                $entries[$key] ??= ['no' => 0, 'kind' => $kind, 'in' => $in, 'at' => '', 'remarks' => '', 'customer' => $kind === 'si' ? $customer : null,
                    'lines' => [], 'first' => PHP_INT_MAX, 'label' => $label, 'types' => $types];
                $e = &$entries[$key];
                $rate = match (true) {
                    $kind === 'si' => self::average((int) $s['out_units'], (string) $s['out_value'], $item['u']),
                    $in => self::average((int) $s['in_units'], (string) $s['in_value'], $item['u']),
                    default => null,
                };
                $e['lines'][] = ['code' => $item['code'], 'qty' => intdiv(abs($net), $item['u']), 'sku' => $sku, 'rate' => $rate];
                $e['at'] = $e['at'] === '' || $s['at'] < $e['at'] ? $s['at'] : $e['at'];
                $e['first'] = min($e['first'], (int) $s['first']);
                unset($e);
                $outcome[$f][$sku] = ['entry' => $key, 'code' => $item['code'], 'u' => $item['u']];
            }
        }
        // Numbered in the order CW booked them (the oldest row of each), receipts before issues of one document.
        uasort($entries, static fn (array $a, array $b): int => [$a['first'], $a['kind'], !$a['in']] <=> [$b['first'], $b['kind'], !$b['in']]);
        $no = 0;
        foreach ($entries as &$e) {
            $e['no'] = ++$no;
            usort($e['lines'], static fn (array $a, array $b): int => [$a['code'], $a['sku']] <=> [$b['code'], $b['sku']]);
            $e['remarks'] = mb_substr("CW rollback entry {$no}: " . ($e['label'] === '' ? 'movement' : $e['label']) . ' (' . implode(', ', array_keys($e['types'])) . ')', 0, 255);
        }
        unset($e);
        foreach ($outcome as &$items) {
            foreach ($items as &$o) {
                if (isset($o['entry'])) {
                    $o['entry'] = $entries[$o['entry']]['no'];
                }
            }
            unset($o);
        }
        unset($items);
        return [array_values($entries), $outcome];
    }

    /**
     * The customer of each hand-over family (the original document's).
     *
     * @param list<string> $families
     * @return array<string, string>
     */
    private function customers(array $families): array
    {
        $ids = [];
        foreach ($families as $f) {
            if (str_starts_with($f, 'd')) {
                $ids[] = (int) substr($f, 1);
            }
        }
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->db->all('SELECT h.document_id, c.name FROM handover h JOIN counterparty c ON c.id = h.counterparty_id WHERE h.document_id IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $out['d' . (int) $r['document_id']] = (string) $r['name'];
            }
        }
        return $out;
    }

    /** A cost (or price) a central unit, averaged over $units worth $value, per ERPNext item of $u units, to 6 decimals; null without units. */
    private static function average(int $units, string $value, int $u): ?string
    {
        if ($units === 0) {
            return null;
        }
        return self::plain(bcadd(bcdiv(bcmul($value, (string) $u, 6), (string) $units, 7), '0.0000005', 6));
    }

    /** "12.500000" => "12.5", "3.000000" => "3". */
    private static function plain(string $v): string
    {
        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }

    /** @return array{0: string, 1: string} UK date and time of a UTC moment */
    private static function uk(string $utc): array
    {
        $t = (new \DateTimeImmutable($utc, Clock::utc()))->setTimezone(new \DateTimeZone(self::ERP_ZONE));
        return [$t->format('Y-m-d'), $t->format('H:i:s')];
    }

    /**
     * The items whose ERPNext figure entries cannot make level (RB24): both items of a join (ERPNext keeps them apart, and a later ERPNext
     * correction of one leaves the other's units in ERPNext), an item ERPNext set to a number CW did not take, an item whose website events a
     * count before the cut-off took in. Nothing sets them by itself: a figure written by a program erases what it does not see (held units
     * left on a joined item, an entry dated later, a sale between two clocks: the third review). Each is listed with CW's figure as ERPNext
     * counts it (its units at the carried warehouses less the paid units not yet sent: ERPNext takes a sale off when it is paid, as the
     * opening's units-only rebase relates the two, PI14), every ERPNext item known for it, and the item it was joined with, for a person to
     * set in ERPNext after the entries are imported.
     *
     * @return array{0: string, 1: int}
     */
    private function handFile(): array
    {
        $csv = new CsvWriter([['Item', 'text'], ['Item name', 'text'], ['Why', 'text'], ['ERPNext figure', 'number'], ['Units on hand', 'number'],
            ['Paid, not sent', 'number'], ['ERPNext items', 'text'], ['Joined with', 'text']]);
        $ids = array_keys($this->settle);
        if ($ids === []) {
            return [$csv->output(), 0];
        }
        $joined = [];
        $pairs = [];
        // In chunks, each side on its own (a window of many joins stays under MySQL's limit of bound values, RB32).
        foreach (array_chunk($ids, 1000) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            foreach (['s.id', 's.merged_into_sku_id'] as $side) {
                foreach ($this->db->all("SELECT s.id, s.merged_into_sku_id FROM sku s WHERE s.merged_into_sku_id IS NOT NULL AND {$side} IN ({$in})", $chunk) as $r) {
                    $pairs[(int) $r['id']] = (int) $r['merged_into_sku_id'];
                }
            }
        }
        ksort($pairs);
        foreach ($pairs as $from => $into) {
            // A joined item marks the one it went into (and back): the pair is set together.
            $this->settle[$into] ??= $this->settle[$from] ?? 'cw_join';
            $this->settle[$from] ??= $this->settle[$into];
            $joined[$from][] = $into;
            $joined[$into][] = $from;
        }
        $ids = array_keys($this->settle);
        sort($ids);
        $this->loadSkus($ids);
        $wh = array_keys($this->carried);
        $bal = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->db->all('SELECT sku_id, SUM(on_hand) h, SUM(allocated) a FROM stock_balance WHERE sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?'))
                . ') AND warehouse_id IN (' . implode(', ', array_fill(0, count($wh), '?')) . ') GROUP BY sku_id', [...$chunk, ...$wh]) as $r) {
                $bal[(int) $r['sku_id']] = ['h' => (int) $r['h'], 'a' => (int) $r['a']];
            }
        }
        $codeOf = fn (int $id): string => (string) ($this->skus[$id]['code'] ?? $id);
        // The ERPNext items of each listed item, each with what a person setting it must know (RB29, RB30): the item a code came with in a
        // join (it reaches the kept item through the joined item's listing), a joined item's own codes from before, a code another CW item
        // also links.
        $labels = [];
        foreach ($ids as $sku) {
            foreach ($this->candidates[$sku] ?? [] as $c) {
                $from = $c['pid'] !== null ? ($this->cameWith[$c['pid']] ?? null) : null;
                if ($c['pid'] !== null && isset($this->historyUnclear[$c['pid']])) {
                    $labels[$sku][] = [$c, 'its joins cannot be followed: check which item it belongs to'];
                } elseif ($from !== null && $from !== $sku) {
                    $labels[$sku][] = [$c, 'came with ' . $codeOf($from) . ' in the join'];
                    $labels[$from][] = [$c, 'its own before the join'];
                } else {
                    $labels[$sku][] = [$c, null];
                }
            }
            foreach ($this->mergedLinks[$sku] ?? [] as $c) {
                $labels[$sku][] = [$c, null];
            }
        }
        $owners = [];
        foreach ($labels as $ls) {
            foreach ($ls as [$c]) {
                $owners += $this->linkOwners[ErpOpeningRules::itemKey($c['code'])] ?? [];
            }
        }
        $this->loadSkus(array_keys($owners));
        foreach ($ids as $sku) {
            $b = $bal[$sku] ?? ['h' => 0, 'a' => 0];
            $codes = [];
            foreach ($labels[$sku] ?? [] as [$c, $note]) {
                $key = ErpOpeningRules::itemKey($c['code']);
                $others = array_values(array_diff(array_keys($this->linkOwners[$key] ?? []), [$sku]));
                $notes = array_filter([$note, $others === [] ? null
                    : 'also linked to ' . implode(', ', array_map($codeOf, $others)) . ': settle which item it is first']);
                $codes[] = $c['code'] . ($c['u'] === 1 ? '' : ' (' . $c['u'] . ')') . ($notes === [] ? '' : ' (' . implode('; ', $notes) . ')');
            }
            $csv->add([$codeOf($sku), (string) ($this->skus[$sku]['name'] ?? ''), Words::ROLLBACK_SET[$this->settle[$sku]],
                $b['h'] - $b['a'], $b['h'], $b['a'], implode(' | ', array_values(array_unique($codes))), implode(' | ', array_map($codeOf, $joined[$sku] ?? []))]);
        }
        return [$csv->output(), count($ids)];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array{0: string, 1: int}
     */
    private function stockEntryFile(array $entries): array
    {
        $csv = new CsvWriter(self::STOCK_ENTRY_COLUMNS);
        $n = 0;
        foreach ($entries as $e) {
            if ($e['kind'] !== 'se') {
                continue;
            }
            [$date, $time] = self::uk($e['at']);
            foreach ($e['lines'] as $i => $l) {
                $head = $i === 0 ? [$e['in'] ? 'Material Receipt' : 'Material Issue', $this->erpCompany, $date, $time, 1, $e['remarks']] : ['', '', '', '', null, ''];
                $csv->add([...$head, $l['code'], $l['qty'], $e['in'] ? $l['rate'] : null, $e['in'] ? '' : $this->erpWarehouse, $e['in'] ? $this->erpWarehouse : '']);
                $n++;
            }
        }
        return [self::forErpnext($csv), $n];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array{0: string, 1: int}
     */
    private function salesInvoiceFile(array $entries): array
    {
        $csv = new CsvWriter(self::SALES_INVOICE_COLUMNS);
        $n = 0;
        foreach ($entries as $e) {
            if ($e['kind'] !== 'si') {
                continue;
            }
            [$date, $time] = self::uk($e['at']);
            foreach ($e['lines'] as $i => $l) {
                $head = $i === 0 ? [(string) $e['customer'], $this->erpCompany, $date, $time, 1, 1, $e['remarks']] : ['', '', '', '', null, null, ''];
                $csv->add([...$head, $l['code'], $l['qty'], $l['rate'], $this->erpWarehouse]);
                $n++;
            }
        }
        return [self::forErpnext($csv), $n];
    }

    /** ERPNext's importer reads UTF-8 and does not expect Excel's byte-order mark. */
    private static function forErpnext(CsvWriter $csv): string
    {
        return substr($csv->output(), strlen(CsvWriter::BOM));
    }

    /**
     * The second pass (RB19): every row read, in id order, with its entry or why it is not carried. A dispatch or reset a count took in
     * shows the units it gives back to that count.
     *
     * @param array<string, array<int, array<string, mixed>>> $outcome
     * @return array{0: string, 1: int}
     */
    private function movementsFile(string $since, array $outcome): array
    {
        $csv = new CsvWriter([['Row', 'number'], ['Booked (UTC)', 'text'], ['Happened (UTC)', 'text'], ['Warehouse', 'text'], ['Item', 'text'],
            ['Item name', 'text'], ['Type', 'text'], ['Units', 'number'], ['Document', 'text'], ['Booked by', 'text'], ['Cost a unit (GBP)', 'number'],
            ['Carried', 'text'], ['Entry', 'number'], ['ERPNext item', 'text'], ['Units per ERPNext item', 'number'], ['Why not carried', 'text']]);
        $this->lastCount = [];
        $n = 0;
        foreach ($this->read($since) as $r) {
            $c = $this->classify($r);
            $o = $c['reason'] !== null ? ['reason' => $c['reason']] : $outcome[$c['family']][(int) $r['sku_id']];
            $sku = $this->skus[(int) $r['sku_id']] ?? ['code' => null, 'name' => ''];
            $cost = $c['pre'] ? null : ($r['value_cost'] ?? $r['unit_cost']);
            $csv->add([(int) $r['id'], (string) $r['created_at'], $r['effective_at'] === null ? '' : (string) $r['effective_at'],
                $this->warehouses[(int) $r['warehouse_id']] ?? '', $sku['code'] ?? '', $sku['name'],
                (string) $r['movement_type'] . ($c['pre'] ? ' (' . self::PRE_COUNT . ')' : ''), $c['qty'],
                (string) ($r['number'] ?? $r['doc_ref'] ?? ''), (string) $r['actor'], $cost === null ? null : self::plain((string) $cost),
                isset($o['entry']) ? 'yes' : 'no', $o['entry'] ?? null, $o['code'] ?? '', $o['u'] ?? null,
                isset($o['reason']) ? Words::ROLLBACK_REASON[$o['reason']] : '']);
            $n++;
        }
        return [$csv->output(), $n];
    }

    /**
     * The store's selling modes and low-stock figures as CW last wrote them (RB6): one row per ERPNext item of each CW item.
     *
     * @return array{0: string, 1: int}
     */
    private function modesFile(): array
    {
        $csv = new CsvWriter([['ERPNext item', 'text'], ['Units per ERPNext item', 'number'], ['Item', 'text'], ['Item name', 'text'], ['Selling mode', 'text'],
            ['Mode before Out-Of-Stock', 'text'], ['Low-stock figure', 'number'], ['Set by', 'text'], ['Changed (UTC)', 'text'], ['Website variants', 'text'],
            ['Why no ERPNext item', 'text']]);
        $n = 0;
        if ($this->store === '') {
            return [$csv->output(), 0];
        }
        $rows = $this->db->all('SELECT m.sku_id, CAST(m.mode AS CHAR) mode, CAST(m.previous_mode AS CHAR) previous_mode, m.low_stock_threshold, '
            . 'CAST(m.source AS CHAR) source, m.updated_at, s.code, s.name FROM item_channel_mode m JOIN channel c ON c.id = m.channel_id JOIN sku s ON s.id = m.sku_id '
            . 'WHERE c.code = ? ORDER BY m.sku_id', [$this->store]);
        foreach ($rows as $r) {
            $sku = (int) $r['sku_id'];
            $items = array_values(array_filter($this->candidates[$sku] ?? [], static fn (array $c): bool => CsvWriter::safeText($c['code']) === $c['code']));
            $why = ($this->candidates[$sku] ?? []) === [] ? Words::ROLLBACK_REASON['no_erp_item'] : Words::ROLLBACK_REASON['unsafe_text'];
            foreach ($items === [] ? [null] : $items as $c) {
                $csv->add([$c['code'] ?? '', $c['u'] ?? null, (string) $r['code'], (string) $r['name'], (string) $r['mode'], (string) ($r['previous_mode'] ?? ''),
                    $r['low_stock_threshold'] === null ? null : (int) $r['low_stock_threshold'], (string) $r['source'], (string) $r['updated_at'],
                    implode(' | ', $this->variants[$sku] ?? []), $c === null ? $why : '']);
                $n++;
            }
        }
        return [$csv->output(), $n];
    }

    /**
     * The purchase orders made or approved since the cut-off, or received against since (RB7, RB18): one row per item line, with what
     * is still to come.
     *
     * @return array{0: string, 1: int}
     */
    private function ordersFile(string $since): array
    {
        $csv = new CsvWriter([['Order', 'text'], ['State', 'text'], ['Supplier', 'text'], ['Supplier name', 'text'], ['Made (UTC)', 'text'],
            ['Expected', 'text'], ['Line', 'number'], ['Item', 'text'], ['Item name', 'text'], ['ERPNext item', 'text'], ['Units per ERPNext item', 'number'],
            ['Ordered units', 'number'], ['Received units', 'number'], ['Still to come', 'number'], ['Pack price (GBP)', 'number'], ['VAT code', 'text']]);
        $rows = $this->db->all('SELECT d.number, CAST(po.state AS CHAR) state, s.code supplier_code, s.name supplier_name, d.created_at, po.expected_date, '
            . 'dl.line_no, dl.sku_id, dl.qty, pl.received_units, pl.pack_price, pl.vat_code, k.code sku_code, k.name sku_name '
            . 'FROM document d JOIN purchase_order po ON po.document_id = d.id JOIN supplier s ON s.id = po.supplier_id '
            . 'JOIN document_line dl ON dl.document_id = d.id JOIN po_line pl ON pl.document_id = dl.document_id AND pl.line_no = dl.line_no '
            . 'LEFT JOIN sku k ON k.id = dl.sku_id '
            . "WHERE d.doc_type = 'PO' AND po.state IS NOT NULL AND pl.kind = 'item' AND (d.created_at >= ? OR d.posted_at >= ? OR EXISTS (SELECT 1 "
            . 'FROM goods_receipt g JOIN document gd ON gd.id = g.document_id WHERE g.po_document_id = d.id AND gd.posted_at >= ?)) ORDER BY d.id, dl.line_no',
            [$since, $since, $since]);
        foreach ($rows as $r) {
            $item = $r['sku_id'] === null ? ['reason' => 'no_erp_item'] : $this->erpItem((int) $r['sku_id']);
            $ordered = (int) $r['qty'];
            $received = (int) $r['received_units'];
            $toCome = in_array($r['state'], ['approved', 'sent', 'part_received'], true) ? max(0, $ordered - $received) : 0;
            $csv->add([(string) $r['number'], (string) $r['state'], (string) $r['supplier_code'], (string) $r['supplier_name'], (string) $r['created_at'],
                (string) ($r['expected_date'] ?? ''), (int) $r['line_no'], (string) ($r['sku_code'] ?? ''), (string) ($r['sku_name'] ?? ''),
                $item['code'] ?? '', $item['u'] ?? null, $ordered, $received, $toCome, self::plain((string) $r['pack_price']), (string) $r['vat_code']]);
        }
        return [$csv->output(), count($rows)];
    }

    /** @return array{0: string, 1: int} */
    private function suppliersFile(string $since): array
    {
        $csv = new CsvWriter([['Supplier', 'text'], ['Name', 'text'], ['Legal name', 'text'], ['State', 'text'], ['Company number', 'text'],
            ['VAT number', 'text'], ['Address line 1', 'text'], ['Address line 2', 'text'], ['Town', 'text'], ['Postcode', 'text'], ['Country', 'text'],
            ['Contact', 'text'], ['E-mail', 'text'], ['Phone', 'text'], ['Payment terms', 'text'], ['Made (UTC)', 'text']]);
        $rows = $this->db->all('SELECT code, name, legal_name, CAST(status AS CHAR) status, company_number, vat_number, address_line1, address_line2, city, postcode, '
            . 'country, contact_name, email, phone, payment_terms, created_at FROM supplier WHERE created_at >= ? ORDER BY id', [$since]);
        foreach ($rows as $r) {
            $csv->add(array_map(static fn (mixed $v): string => (string) ($v ?? ''), array_values($r)));
        }
        return [$csv->output(), count($rows)];
    }

    /** @return array{0: string, 1: int} */
    private function customersFile(string $since): array
    {
        $csv = new CsvWriter([['Name', 'text'], ['Kind', 'text'], ['Address', 'text'], ['Company number', 'text'], ['VAT number', 'text'],
            ['E-mail', 'text'], ['Phone', 'text'], ['Made (UTC)', 'text']]);
        $rows = $this->db->all('SELECT name, CAST(kind AS CHAR) kind, address, company_number, vat_number, email, phone, created_at FROM counterparty '
            . 'WHERE created_at >= ? ORDER BY id', [$since]);
        foreach ($rows as $r) {
            $csv->add(array_map(static fn (mixed $v): string => (string) ($v ?? ''), array_values($r)));
        }
        return [$csv->output(), count($rows)];
    }

    /** The items made since the cut-off, with their ERPNext items (none: ERPNext needs the item made first). @return array{0: string, 1: int} */
    private function itemsFile(string $since): array
    {
        $csv = new CsvWriter([['Item', 'text'], ['Name', 'text'], ['Brand', 'text'], ['Made (UTC)', 'text'], ['ERPNext items', 'text'],
            ['Website variants', 'text'], ['Joined into', 'text']]);
        $rows = $this->db->all('SELECT s.id, s.code, s.name, s.brand, s.created_at, m.code merged_code FROM sku s LEFT JOIN sku m ON m.id = s.merged_into_sku_id '
            . 'WHERE s.created_at >= ? ORDER BY s.id', [$since]);
        foreach ($rows as $r) {
            $sku = (int) $r['id'];
            $codes = array_map(static fn (array $c): string => $c['u'] === 1 ? $c['code'] : $c['code'] . ' (' . $c['u'] . ')', $this->candidates[$sku] ?? []);
            $csv->add([(string) ($r['code'] ?? ''), (string) $r['name'], (string) ($r['brand'] ?? ''), (string) $r['created_at'], implode(' | ', $codes),
                implode(' | ', $this->variants[$sku] ?? []), (string) ($r['merged_code'] ?? '')]);
        }
        return [$csv->output(), count($rows)];
    }
}
