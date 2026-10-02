<?php

declare(strict_types=1);

namespace CW\PurchaseOrders;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Idempotency;
use CW\Matching\Gtin;
use CW\Output\CsvReader;
use CW\Settings;
use CW\Suppliers\ErpSeedImport;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;

/**
 * The ERPNext open purchase orders, brought across ONCE from a CSV exported from the RESTORED BACKUP COPY of ERPNext (owner
 * decision 4; CW never connects to ERPNext): spec §6.8, docs/decisions.md I56, docs/ops.md ("ERPNext open POs").
 * bin/import_erp_open_pos.php drives it.
 *
 * open_pos.csv, one row per PO line (* required): erp_po*, supplier* (ERPNext name or CW code), order_date* (Y-m-d),
 * expected_date, line_no*, item_ref_type* + item_ref* (cw_code, vpg_variant, vpg_code, barcode, erp_item: as the supplier
 * items seed, spec §5.7), supplier_code, purchase_unit, units_per_pack (in the referenced item's units, default 1),
 * packs_ordered*, packs_received (default 0), pack_price*, vat_code.
 *
 *  - One CW PO per erp_po holding only the OUTSTANDING packs (ordered − received > 0); a PO with nothing outstanding is
 *    skipped.
 *  - external_ref = "ERPNext <erp_po>"; a live CW PO (draft, waiting or posted) with that reference: skipped (a re-run is
 *    safe).
 *  - Any unresolved line, a supplier that is not active, a bad value: the WHOLE PO is refused (status failed, "whole PO
 *    skipped: ...") — never a partial PO.
 *  - Created as --staff (doc.PO.post), source erp_seed, doc_date = order_date; approved through the document base (above
 *    the value limit it waits for a reviewer: reported); an approved one is then marked sent (via imported, to ERPNext).
 *  - One import_run row per file (kind erp_open_pos; a file already imported is not imported again); ONE transaction per PO;
 *    a dry run does everything in transactions that are rolled back (it writes only its import_run row).
 */
final class ErpOpenPoImport
{
    public const COLUMNS = ['erp_po', 'supplier', 'order_date', 'expected_date', 'line_no', 'item_ref_type', 'item_ref', 'supplier_code', 'purchase_unit',
        'units_per_pack', 'packs_ordered', 'packs_received', 'pack_price', 'vat_code'];
    public const REQUIRED = ['erp_po', 'supplier', 'order_date', 'line_no', 'item_ref_type', 'item_ref', 'packs_ordered', 'pack_price'];
    public const MAX_FILE_BYTES = 33_554_432;
    public const ACTOR = 'system:import_erp_open_pos';
    private const DRY_RUN = 'erp_open_po_dry_run';

    private readonly PurchaseOrders $pos;
    private readonly Suppliers $suppliers;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?Settings $settings = null, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $settings ??= new Settings($db);
        $this->pos = new PurchaseOrders($db, new Documents($db, DocumentHandlers::all($db), $this->clock), $settings, null, $this->clock);
        $this->suppliers = new Suppliers($db, $settings, $this->clock);
    }

    /**
     * Imports an open_pos.csv. $vpgCodes: the Vape and Go listings export, needed by vpg_code lines.
     *
     * @return array{run_id: ?int, already: ?int, dry_run: bool, rows: list<array{row: int, key: string, status: string, reason: string}>, counts: array<string, int>}
     */
    public function import(Caller $staff, string $path, ?string $vpgCodes = null, bool $dryRun = false): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new CwException('bad_file', 'there is no readable file ' . basename($path), 400);
        }
        if ($this->db->inTransaction()) {
            throw new \LogicException('an import owns its transactions');
        }
        $codes = $vpgCodes === null ? null : ErpSeedImport::vpgCodes($vpgCodes);
        $sha = (string) hash_file('sha256', $path);
        $already = $this->db->value("SELECT id FROM import_run WHERE kind = 'erp_open_pos' AND file_sha256 = ? AND dry_run = 0 AND status = 'done' ORDER BY id LIMIT 1", [$sha]);
        if ($already !== null) {
            return ['run_id' => null, 'already' => (int) $already, 'dry_run' => $dryRun, 'rows' => [], 'counts' => []];
        }
        $runId = $this->db->insert('INSERT INTO import_run (kind, file_name, file_sha256, dry_run, status, actor, staff_user_id, started_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            ['erp_open_pos', mb_substr(basename($path), 0, 255), $sha, $dryRun ? 1 : 0, 'running', self::ACTOR, $staff->staffUserId, Clock::db(($this->clock)())]);
        try {
            $table = CsvReader::table($path, self::MAX_FILE_BYTES, null);
            $missing = array_values(array_diff(self::REQUIRED, $table['header']));
            if ($missing !== []) {
                throw new CwException('bad_file', 'the file has no column ' . implode(', ', $missing) . ' (header: ' . implode(', ', $table['header']) . ')', 400);
            }
            $ignored = array_values(array_diff($table['header'], self::COLUMNS));
            $groups = [];
            $rowsRead = 0;
            foreach ($table['rows'] as $n => $r) {
                $rowsRead++;
                $groups[trim($r['erp_po'] ?? '')][$n] = $r;
            }
            $out = [];
            foreach ($groups as $erpPo => $rows) {
                $out[] = $this->one($staff, (string) $erpPo, $rows, $codes, $dryRun);
            }
            $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
            foreach ($out as $r) {
                $counts[$r['status']]++;
            }
            $summary = ['counts' => $counts, 'pos' => count($out), 'ignored_columns' => $ignored,
                'failed' => array_slice(array_values(array_filter($out, static fn (array $r): bool => $r['status'] === 'failed')), 0, 200)];
            $this->db->exec("UPDATE import_run SET status = 'done', rows_read = ?, created = ?, updated = 0, skipped = ?, failed = ?, summary = CAST(? AS JSON), finished_at = ? "
                . 'WHERE id = ?', [$rowsRead, $counts['created'], $counts['skipped'], $counts['failed'], Idempotency::json($summary), Clock::db(($this->clock)()), $runId]);
            return ['run_id' => $runId, 'already' => null, 'dry_run' => $dryRun, 'rows' => $out, 'counts' => $counts + ['rows_read' => $rowsRead, 'pos' => count($out)]];
        } catch (\Throwable $e) {
            try {
                $this->db->exec("UPDATE import_run SET status = 'failed', summary = CAST(? AS JSON), finished_at = ? WHERE id = ?",
                    [Idempotency::json(['error' => $e instanceof CwException ? $e->errorCode : get_class($e), 'message' => mb_substr($e->getMessage(), 0, 500)]),
                        Clock::db(($this->clock)()), $runId]);
            } catch (\Throwable) {
                // the connection is gone: the run stays `running`
            }
            throw $e;
        }
    }

    /** The report CSV (row, key, status, reason). @param list<array{row: int, key: string, status: string, reason: string}> $rows */
    public static function writeReport(string $path, array $rows): void
    {
        ErpSeedImport::writeReport($path, $rows);
    }

    /**
     * One ERPNext PO: checked whole, then created, approved and marked sent in ONE transaction.
     *
     * @param array<int, array<string, string>> $rows
     * @param array<string, list<string>>|null $codes
     * @return array{row: int, key: string, status: string, reason: string}
     */
    private function one(Caller $staff, string $erpPo, array $rows, ?array $codes, bool $dryRun): array
    {
        $first = (int) array_key_first($rows);
        $res = static fn (string $status, string $reason): array => ['row' => $first, 'key' => mb_substr($erpPo, 0, 300), 'status' => $status, 'reason' => $reason];
        if ($erpPo === '') {
            return $res('failed', 'whole PO skipped: erp_po is empty on row(s) ' . implode(', ', array_keys($rows)));
        }
        $ref = mb_substr('ERPNext ' . $erpPo, 0, 191);
        $live = $this->db->one("SELECT id, number, status FROM document WHERE doc_type = 'PO' AND external_ref = ? AND reverses_id IS NULL "
            . "AND status IN ('draft', 'awaiting_approval', 'posted') ORDER BY id LIMIT 1", [$ref]);
        if ($live !== null) {
            return $res('skipped', 'already in CW as ' . ($live['number'] ?? str_replace('_', ' ', (string) $live['status']) . ' #' . $live['id']));
        }
        try {
            $plan = $this->plan($rows, $codes);
        } catch (CwException $e) {
            return $res('failed', 'whole PO skipped: ' . $e->getMessage());
        }
        if ($plan['lines'] === []) {
            return $res('skipped', 'nothing outstanding (every line received)');
        }
        try {
            $reason = '';
            $this->db->transaction(function () use ($staff, $plan, $ref, $dryRun, &$reason): void {
                $d = $this->pos->createDraft($staff, $plan['supplier_id'], ['doc_date' => $plan['order_date'], 'expected_date' => $plan['expected_date'],
                    'external_ref' => $ref], 'erp_seed');
                $d = $this->pos->saveDraft($staff, $d->id, $d->version, [], $plan['lines']);
                $net = (string) $this->db->value('SELECT net_total FROM purchase_order WHERE document_id = ?', [$d->id]);
                $d = $this->pos->approve($staff, $d->id, $d->version);
                if ($d->status === 'awaiting_approval') {
                    $reason = "draft #{$d->id} waits for a reviewer's approval (net £{$net} is above the limit), " . count($plan['lines']) . ' lines';
                } else {
                    $this->pos->markSent($staff, $d->id, $d->version, 'imported', 'ERPNext', true); // ERPNext sent it already (I86)
                    $reason = "{$d->number} approved and marked sent (imported), net £{$net}, " . count($plan['lines']) . ' lines';
                }
                if ($dryRun) {
                    throw new CwException(self::DRY_RUN, 'a dry run: everything it did is rolled back', 200);
                }
            });
        } catch (CwException $e) {
            if ($e->errorCode !== self::DRY_RUN) {
                return $res('failed', 'whole PO skipped: ' . $e->getMessage());
            }
            $reason = 'would be ' . $reason;
        }
        return $res('created', $reason);
    }

    /**
     * Resolves and checks every row of one PO (no writes): the supplier (active), the dates, the outstanding lines.
     *
     * @param array<int, array<string, string>> $rows
     * @param array<string, list<string>>|null $codes
     * @return array{supplier_id: int, order_date: string, expected_date: ?string, lines: list<array<string, mixed>>}
     */
    private function plan(array $rows, ?array $codes): array
    {
        $firstRow = $rows[array_key_first($rows)];
        $supRef = trim($firstRow['supplier'] ?? '');
        $s = $this->suppliers->findByCodeOrErpName($supRef) ?? throw new CwException('unknown_supplier', "no supplier has the ERPNext name or CW code {$supRef}", 422);
        if ($s['status'] !== 'active') {
            throw new CwException('supplier_not_active', "supplier {$s['code']} is " . str_replace('_', ' ', (string) $s['status']), 422);
        }
        if ((int) $s['is_overseas'] === 1 && $s['import_route_approved_at'] === null) {
            throw new CwException('import_route_not_approved', "supplier {$s['code']} is overseas and its import route is not approved", 422);
        }
        $orderDate = Suppliers::date(trim($firstRow['order_date'] ?? '')) ?? throw new CwException('bad_field', 'order_date must be a date, YYYY-MM-DD', 422);
        $exp = trim($firstRow['expected_date'] ?? '');
        $expected = $exp === '' ? null : (Suppliers::date($exp) ?? throw new CwException('bad_field', 'expected_date must be a date, YYYY-MM-DD', 422));
        $vat = [];
        foreach ($this->db->all('SELECT code, is_active FROM vat_code') as $v) {
            $vat[(string) $v['code']] = (int) $v['is_active'] === 1;
        }
        $lines = [];
        $seen = [];
        foreach ($rows as $n => $r) {
            $where = "row {$n}";
            if (trim($r['supplier'] ?? '') !== $supRef || trim($r['order_date'] ?? '') !== trim($firstRow['order_date'] ?? '')) {
                throw new CwException('bad_field', "{$where}: the lines of one PO name one supplier and one order date", 422);
            }
            $no = PoLinesFile::wholeNumber($r['line_no'] ?? '');
            if ($no === null || isset($seen[$no])) {
                throw new CwException('bad_field', "{$where}: line_no must be a whole number, once per PO", 422);
            }
            $seen[$no] = true;
            $ordered = PoLinesFile::wholeNumber($r['packs_ordered'] ?? '');
            $receivedRaw = trim($r['packs_received'] ?? '');
            $received = $receivedRaw === '' ? 0 : PoLinesFile::wholeNumber($receivedRaw);
            if ($ordered === null || $received === null) {
                throw new CwException('bad_field', "{$where}: packs_ordered and packs_received are whole numbers", 422);
            }
            $price = PoLinesFile::price($r['pack_price'] ?? '') ?? throw new CwException('bad_price', "{$where}: pack_price must be an amount with at most 4 decimals", 422);
            [$skuId, $factor, $via] = $this->resolveItem(strtolower(trim($r['item_ref_type'] ?? '')), trim($r['item_ref'] ?? ''), $codes, $where);
            $sku = $this->db->one('SELECT s.id, s.code, s.merged_into_sku_id, m.code AS into_code FROM sku s LEFT JOIN sku m ON m.id = s.merged_into_sku_id WHERE s.id = ?', [$skuId])
                ?? throw new CwException('unknown_sku', "{$where}: {$via} names item {$skuId}, which does not exist", 422);
            if ($sku['merged_into_sku_id'] !== null) {
                throw new CwException('merged_item', "{$where}: {$via} is item {$sku['code']}, which was merged into {$sku['into_code']}", 422);
            }
            $fileUpp = trim($r['units_per_pack'] ?? '') === '' ? 1 : PoLinesFile::wholeNumber($r['units_per_pack']);
            if ($fileUpp === null || $fileUpp < 1 || $fileUpp * $factor > PoMath::MAX_UNITS_PER_PACK) {
                throw new CwException('bad_field', "{$where}: units_per_pack must be a whole number of at least 1", 422);
            }
            $upp = $fileUpp * $factor;
            $code = trim($r['vat_code'] ?? '') === '' ? null : strtoupper(trim($r['vat_code']));
            if ($code !== null && !($vat[$code] ?? false)) {
                throw new CwException('bad_field', "{$where}: there is no VAT code {$code} in use", 422);
            }
            $outstanding = $ordered - $received;
            if ($outstanding <= 0) {
                continue;
            }
            $si = $this->db->one('SELECT id FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND units_per_pack = ? AND is_active = 1', [(int) $s['id'], $skuId, $upp]);
            $sc = trim($r['supplier_code'] ?? '');
            $unit = trim($r['purchase_unit'] ?? '');
            $lines[$no] = ['kind' => 'item', 'sku_id' => $skuId, 'supplier_item_id' => $si === null ? null : (int) $si['id'], 'units_per_pack' => $upp,
                'supplier_code' => $si === null && $sc !== '' ? mb_substr($sc, 0, 64) : null,
                'purchase_unit' => $si === null && $unit !== '' ? mb_substr($unit, 0, SupplierItems::PURCHASE_UNIT_MAX) : null,
                'packs' => $outstanding, 'pack_price' => $price, 'vat_code' => $code];
        }
        ksort($lines);
        return ['supplier_id' => (int) $s['id'], 'order_date' => $orderDate, 'expected_date' => $expected, 'lines' => array_values($lines)];
    }

    /**
     * An item reference as the supplier-items seed reads it (spec §5.7; the same rules as ErpSeedImport): the item, the
     * central units in one referenced unit, and what resolved it.
     *
     * @param array<string, list<string>>|null $codes
     * @return array{0: int, 1: int, 2: string}
     */
    private function resolveItem(string $type, string $ref, ?array $codes, string $where): array
    {
        if ($ref === '') {
            throw new CwException('bad_field', "{$where}: item_ref is empty", 422);
        }
        switch ($type) {
            case 'cw_code':
                $id = preg_match('/^(?:cw-?)?0*([0-9]{1,10})$/iD', $ref, $m) === 1 ? (int) $m[1] : null;
                if ($id === null || $this->db->value('SELECT id FROM sku WHERE id = ?', [$id]) === null) {
                    throw new CwException('unknown_sku', "{$where}: no item has the CW code {$ref}", 422);
                }
                return [$id, 1, "CW code {$ref}"];
            case 'vpg_code':
                if ($codes === null) {
                    throw new CwException('no_vpg_codes', "{$where}: vpg_code lines need --vpg-codes (the Vape and Go listings export)", 422);
                }
                $variants = $codes[mb_strtolower($ref)] ?? [];
                if (count($variants) !== 1) {
                    throw new CwException('unknown_vpg_code', "{$where}: the Vape and Go code {$ref} names " . count($variants) . ' variants', 422);
                }
                return $this->listing($variants[0], "Vape and Go code {$ref} (variant {$variants[0]})", $where);
            case 'vpg_variant':
                return $this->listing($ref, "Vape and Go variant {$ref}", $where);
            case 'barcode':
                $b = $this->db->one('SELECT sku_id, units_per_scan FROM sku_barcode WHERE barcode IN (?, ?) AND is_usable = 1 ORDER BY barcode = ? DESC LIMIT 1',
                    [$ref, Gtin::key($ref) ?? $ref, $ref]);
                if ($b === null) {
                    throw new CwException('unknown_barcode', "{$where}: no item has the usable barcode {$ref}", 422);
                }
                return [(int) $b['sku_id'], (int) $b['units_per_scan'], "barcode {$ref}"];
            case 'erp_item':
                $e = $this->db->one('SELECT sku_id, units_per_item FROM sku_erp_item WHERE item_code = ?', [$ref]);
                if ($e === null) {
                    throw new CwException('unknown_erp_item', "{$where}: the ERPNext item {$ref} is not linked to a CW item (sku_erp_item)", 422);
                }
                return [(int) $e['sku_id'], (int) $e['units_per_item'], "ERPNext item {$ref}"];
        }
        throw new CwException('bad_field', "{$where}: item_ref_type must be one of " . implode(', ', ErpSeedImport::ITEM_REF_TYPES), 422);
    }

    /** @return array{0: int, 1: int, 2: string} a Vape and Go listing's item (it must be linked) */
    private function listing(string $variant, string $via, string $where): array
    {
        $l = $this->db->one('SELECT l.sku_id, l.units_per_item, l.status FROM channel_listing l JOIN channel c ON c.id = l.channel_id '
            . 'WHERE c.code = ? AND l.external_variant_id = ?', [ErpSeedImport::VPG_CHANNEL, $variant]);
        if ($l === null) {
            throw new CwException('unknown_listing', "{$where}: {$via}: CW has no such Vape and Go listing", 422);
        }
        if ($l['status'] !== 'mapped' || $l['sku_id'] === null) {
            throw new CwException('unlinked_listing', "{$where}: {$via}: the listing is not linked to an item (status {$l['status']})", 422);
        }
        return [(int) $l['sku_id'], (int) $l['units_per_item'], $via];
    }
}
