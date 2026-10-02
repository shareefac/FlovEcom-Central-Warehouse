<?php

declare(strict_types=1);

namespace CW\Suppliers;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Matching\Gtin;
use CW\Output\CsvReader;
use CW\Output\CsvWriter;
use CW\Settings;

/**
 * The ERPNext seed import of suppliers and supplier items (IM15 for IM4; docs/decisions.md I45, docs/ops.md "ERPNext
 * supplier seed"): CSV files exported from the RESTORED BACKUP COPY of ERPNext (owner decision 4) — CW never connects to
 * ERPNext. bin/import_erp_suppliers.php drives it.
 *
 * Each file is one import_run row (kind, file name, sha256, dry_run, counts, a summary). A file whose sha256 was already
 * imported (a done, non-dry run of the same kind) is not imported again: "already imported (run N)". A dry run processes
 * every row inside a transaction that is rolled back, so it writes only its import_run row. A real run is ONE
 * transaction with a savepoint per row: a row that is refused (unresolved, invalid, duplicate) is rolled back to its
 * savepoint and reported `failed`, the others stand; an unexpected error rolls the whole file back (run `failed`).
 *
 * Suppliers (suppliers.csv): new rows are created as DRAFT by the --staff buyer (who can then never approve them); an
 * existing erp_name is skipped, or with $updateBlank only its empty fields are filled, and only while it is draft or
 * inactive (an active or pending supplier is never changed by an import; the differences are reported).
 * $requestActivation asks for the activation of every supplier of the file that is draft or inactive and complete
 * (Suppliers::missing), as --staff.
 *
 * Supplier items (supplier_items.csv): the item is resolved by item_ref_type (cw_code, vpg_variant, vpg_code, barcode,
 * erp_item) and the file's units_per_pack (in the referenced item's units) becomes central units (× the listing's
 * units_per_item, the barcode's units_per_scan, the ERP item's units_per_item); rows are upserted by (supplier, item,
 * central pack); a price becomes an `import` history row (the last price moves when it is the newest). Two rows of one
 * file making the same item preferred both fail.
 */
final class ErpSeedImport
{
    public const SUPPLIER_COLUMNS = ['erp_name', 'name', 'code', 'legal_name', 'company_number', 'vat_number', 'address_line1', 'address_line2', 'city', 'postcode',
        'country', 'contact_name', 'email', 'phone', 'payment_terms', 'payment_terms_days', 'default_lead_days', 'review_days', 'is_overseas', 'default_vat_code',
        'notes'];
    public const ITEM_COLUMNS = ['supplier', 'item_ref_type', 'item_ref', 'supplier_code', 'supplier_description', 'purchase_unit', 'units_per_pack', 'moq_packs',
        'order_multiple_packs', 'lead_days', 'is_preferred', 'last_pack_price', 'last_price_date', 'last_price_ref'];
    public const ITEM_REF_TYPES = ['cw_code', 'vpg_variant', 'vpg_code', 'barcode', 'erp_item'];
    /** The channel code of Vape and Go (vpg_variant / vpg_code rows resolve through its listings). */
    public const VPG_CHANNEL = 'vapeandgo';
    public const MAX_FILE_BYTES = 33_554_432;
    public const ACTOR = 'system:import_erp_suppliers';
    /** The code of the exception that unwinds a dry run's transaction. */
    private const DRY_RUN = 'erp_import_dry_run';

    private readonly Suppliers $suppliers;
    private readonly SupplierItems $items;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?Settings $settings = null, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $this->suppliers = new Suppliers($db, $settings, $this->clock);
        $this->items = new SupplierItems($db, $this->clock);
    }

    /**
     * Imports a suppliers.csv.
     *
     * @return array{run_id: ?int, already: ?int, dry_run: bool, rows: list<array{row: int, key: string, status: string, reason: string}>, counts: array<string, int>}
     */
    public function suppliers(Caller $staff, string $path, bool $updateBlank = false, bool $requestActivation = false, bool $dryRun = false): array
    {
        return $this->run('erp_suppliers', $staff, $path, $dryRun, ['erp_name'], self::SUPPLIER_COLUMNS,
            function (array $rows, int $runId) use ($staff, $updateBlank, $requestActivation): array {
                $out = [];
                $seen = [];
                foreach ($rows as $n => $r) {
                    $key = trim($r['erp_name'] ?? '');
                    $out[] = $this->row($n, $key, function () use ($staff, $r, $n, $key, &$seen, $updateBlank, $requestActivation, $runId): array {
                        if ($key === '') {
                            throw new CwException('bad_field', 'erp_name is empty', 422);
                        }
                        $lower = mb_strtolower($key);
                        if (isset($seen[$lower])) {
                            throw new CwException('duplicate_row', "the file names this supplier twice (row {$seen[$lower]})", 422);
                        }
                        $seen[$lower] = $n;
                        return $this->supplierRow($staff, $r, $key, $updateBlank, $requestActivation, $runId);
                    });
                }
                return $out;
            });
    }

    /**
     * Imports a supplier_items.csv. $vpgCodes: the first-match listings export of Vape and Go (.jsonl.gz, one listing per
     * line with `code` and `variant_id`), needed by vpg_code rows.
     *
     * @return array{run_id: ?int, already: ?int, dry_run: bool, rows: list<array{row: int, key: string, status: string, reason: string}>, counts: array<string, int>}
     */
    public function items(Caller $staff, string $path, ?string $vpgCodes = null, bool $dryRun = false): array
    {
        $codes = $vpgCodes === null ? null : self::vpgCodes($vpgCodes);
        return $this->run('erp_supplier_items', $staff, $path, $dryRun, ['supplier', 'item_ref_type', 'item_ref'], self::ITEM_COLUMNS,
            function (array $rows, int $runId) use ($staff, $codes): array {
                // Pass 1: resolve every row (no writes); pass 2: two preferred rows for one item fail together; pass 3: write.
                $resolved = [];
                $failed = [];
                foreach ($rows as $n => $r) {
                    try {
                        $resolved[$n] = $this->resolveItemRow($r, $codes);
                    } catch (CwException $e) {
                        $failed[$n] = $e->getMessage();
                    }
                }
                $preferred = [];
                foreach ($resolved as $n => $x) {
                    if ($x['preferred']) {
                        $preferred[$x['sku_id']][] = $n;
                    }
                }
                foreach ($preferred as $sku => $ns) {
                    if (count($ns) > 1) {
                        foreach ($ns as $n) {
                            $failed[$n] = 'rows ' . implode(', ', $ns) . ' all make item ' . $resolved[$n]['sku_code'] . ' preferred: the file must name one';
                            unset($resolved[$n]);
                        }
                    }
                }
                $out = [];
                foreach ($rows as $n => $r) {
                    $key = trim($r['supplier'] ?? '') . ' / ' . trim($r['item_ref_type'] ?? '') . ':' . trim($r['item_ref'] ?? '');
                    if (isset($failed[$n])) {
                        $out[] = ['row' => $n, 'key' => $key, 'status' => 'failed', 'reason' => $failed[$n]];
                        continue;
                    }
                    $x = $resolved[$n];
                    $out[] = $this->row($n, $key, fn (): array => $this->itemRow($staff, $x, $runId));
                }
                return $out;
            });
    }

    /**
     * Writes the report CSV (row, key, status, reason) of an import.
     *
     * @param list<array{row: int, key: string, status: string, reason: string}> $rows
     */
    public static function writeReport(string $path, array $rows): void
    {
        $csv = new CsvWriter([['row', 'number'], ['key', 'text'], ['status', 'text'], ['reason', 'text']]);
        foreach ($rows as $r) {
            $csv->add([$r['row'], $r['key'], $r['status'], $r['reason']]);
        }
        if (@file_put_contents($path, $csv->output()) === false) {
            throw new \RuntimeException('cannot write the report to ' . $path);
        }
    }

    // ------------------------------------------------------------------------------------------

    /**
     * The import_run frame (class docblock).
     *
     * @param list<string> $required
     * @param list<string> $known
     * @param \Closure(array<int, array<string, string>>, int): list<array{row: int, key: string, status: string, reason: string}> $process
     * @return array{run_id: ?int, already: ?int, dry_run: bool, rows: list<array{row: int, key: string, status: string, reason: string}>, counts: array<string, int>}
     */
    private function run(string $kind, Caller $staff, string $path, bool $dryRun, array $required, array $known, \Closure $process): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new CwException('bad_file', 'there is no readable file ' . basename($path), 400);
        }
        if ($this->db->inTransaction()) {
            throw new \LogicException('an import owns its transactions');
        }
        $sha = (string) hash_file('sha256', $path);
        $already = $this->db->value("SELECT id FROM import_run WHERE kind = ? AND file_sha256 = ? AND dry_run = 0 AND status = 'done' ORDER BY id LIMIT 1",
            [$kind, $sha]);
        if ($already !== null) {
            return ['run_id' => null, 'already' => (int) $already, 'dry_run' => $dryRun, 'rows' => [], 'counts' => []];
        }
        $runId = $this->db->insert('INSERT INTO import_run (kind, file_name, file_sha256, dry_run, status, actor, staff_user_id, started_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$kind, mb_substr(basename($path), 0, 255), $sha, $dryRun ? 1 : 0, 'running', self::ACTOR, $staff->staffUserId, Clock::db(($this->clock)())]);
        try {
            $table = CsvReader::table($path, self::MAX_FILE_BYTES, null);
            $missing = array_values(array_diff($required, $table['header']));
            if ($missing !== []) {
                throw new CwException('bad_file', 'the file has no column ' . implode(', ', $missing) . ' (header: ' . implode(', ', $table['header']) . ')', 400);
            }
            $ignored = array_values(array_diff($table['header'], $known));
            $rows = iterator_to_array($table['rows'], true);
            $result = null;
            try {
                $this->db->transaction(function () use ($process, $rows, $runId, $dryRun, &$result): void {
                    $result = $process($rows, $runId);
                    if ($dryRun) {
                        throw new CwException(self::DRY_RUN, 'a dry run: everything it did is rolled back', 200);
                    }
                });
            } catch (CwException $e) {
                if ($e->errorCode !== self::DRY_RUN) {
                    throw $e;
                }
            }
            /** @var list<array{row: int, key: string, status: string, reason: string}> $result */
            $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
            foreach ($result as $r) {
                $counts[$r['status']]++;
            }
            $summary = ['counts' => $counts, 'ignored_columns' => $ignored,
                'failed' => array_slice(array_values(array_filter($result, static fn (array $r): bool => $r['status'] === 'failed')), 0, 200)];
            $this->db->exec("UPDATE import_run SET status = 'done', rows_read = ?, created = ?, updated = ?, skipped = ?, failed = ?, summary = CAST(? AS JSON), finished_at = ? "
                . 'WHERE id = ?', [count($rows), $counts['created'], $counts['updated'], $counts['skipped'], $counts['failed'], Idempotency::json($summary),
                    Clock::db(($this->clock)()), $runId]);
            return ['run_id' => $runId, 'already' => null, 'dry_run' => $dryRun, 'rows' => $result, 'counts' => $counts + ['rows_read' => count($rows)]];
        } catch (\Throwable $e) {
            try {
                $this->db->exec("UPDATE import_run SET status = 'failed', summary = CAST(? AS JSON), finished_at = ? WHERE id = ?",
                    [Idempotency::json(['error' => $e instanceof CwException ? $e->errorCode : get_class($e), 'message' => mb_substr($e->getMessage(), 0, 500)]),
                        Clock::db(($this->clock)()), $runId]);
            } catch (\Throwable) {
                // the connection is gone: the run stays `running`, which says it did not finish
            }
            throw $e;
        }
    }

    /**
     * One row inside a savepoint: a refusal (CwException) rolls the row back and reports it failed.
     *
     * @param \Closure(): array{status: string, reason: string} $fn
     * @return array{row: int, key: string, status: string, reason: string}
     */
    private function row(int $n, string $key, \Closure $fn): array
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT erp_row');
        try {
            $r = $fn();
            $pdo->exec('RELEASE SAVEPOINT erp_row');
            return ['row' => $n, 'key' => mb_substr($key, 0, 300)] + $r;
        } catch (CwException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT erp_row');
            return ['row' => $n, 'key' => mb_substr($key, 0, 300), 'status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, string> $r
     * @return array{status: string, reason: string}
     */
    private function supplierRow(Caller $staff, array $r, string $erpName, bool $updateBlank, bool $requestActivation, int $runId): array
    {
        $given = [];
        foreach (Suppliers::FIELDS as $f => $_) {
            if (isset($r[$f]) && trim($r[$f]) !== '') {
                $given[$f] = $r[$f];
            }
        }
        if (!isset($given['is_overseas']) && isset($given['country']) && !in_array(strtoupper(trim($given['country'])), ['GB', 'UK'], true)) {
            $given['is_overseas'] = '1'; // a supplier outside GB is overseas (I72); an explicit 0 fails the row
        }
        $v = $this->suppliers->normalise($given);
        $existing = $this->db->one('SELECT * FROM supplier WHERE erp_name = ?', [$erpName]);
        if ($existing === null) {
            // A new supplier is named after its ERPNext name unless the file gives a name.
            $s = $this->suppliers->create($staff, $given + ['name' => $erpName, 'erp_name' => $erpName], true);
            return ['status' => 'created', 'reason' => "created {$s['code']} (draft)" . $this->activation($staff, $s, $requestActivation)];
        }
        $differs = [];
        $blank = [];
        foreach ($v as $f => $val) {
            if ($f === 'code') {
                continue;
            }
            $cur = Suppliers::str($existing[$f]);
            if ($cur === null || trim($cur) === '') {
                $blank[$f] = $given[$f];
            } elseif ($cur !== Suppliers::str($val)) {
                $differs[] = $f;
            }
        }
        $diffText = $differs === [] ? '' : ' (differs in CW: ' . implode(', ', $differs) . ')';
        if (!in_array($existing['status'], ['draft', 'inactive'], true)) {
            return ['status' => 'skipped', 'reason' => "{$existing['code']} is " . str_replace('_', ' ', (string) $existing['status'])
                . ': an import never changes it' . $diffText];
        }
        if (!$updateBlank || $blank === []) {
            $s = $existing;
            $reason = "already in CW as {$existing['code']}" . ($updateBlank ? ', nothing blank to fill' : '') . $diffText;
            $status = 'skipped';
        } else {
            $s = $this->suppliers->update($staff, (int) $existing['id'], (int) $existing['version'], $blank);
            $reason = "{$existing['code']}: filled " . implode(', ', array_keys($blank)) . $diffText;
            $status = 'updated';
        }
        return ['status' => $status, 'reason' => $reason . $this->activation($staff, $s, $requestActivation)];
    }

    /** @param array<string, mixed> $s */
    private function activation(Caller $staff, array $s, bool $requested): string
    {
        if (!$requested || !in_array($s['status'], ['draft', 'inactive'], true)) {
            return '';
        }
        $missing = Suppliers::missing($s);
        if ($missing !== []) {
            return '; not complete for activation (missing: ' . Suppliers::labels($missing) . ')';
        }
        $this->suppliers->requestActivation($staff, (int) $s['id'], (int) $s['version']);
        return '; activation requested';
    }

    /**
     * Pass 1 of an item row: the supplier, the item and the central pack, the fields and the price, checked; nothing written.
     *
     * @param array<string, string> $r
     * @param array<string, list<string>>|null $codes
     * @return array<string, mixed>
     */
    private function resolveItemRow(array $r, ?array $codes): array
    {
        $ref = trim($r['supplier'] ?? '');
        $s = $ref === '' ? null : $this->suppliers->findByCodeOrErpName($ref);
        if ($s === null) {
            throw new CwException('unknown_supplier', $ref === '' ? 'supplier is empty' : "no supplier has the ERPNext name or CW code {$ref}", 422);
        }
        $type = strtolower(trim($r['item_ref_type'] ?? ''));
        $itemRef = trim($r['item_ref'] ?? '');
        if (!in_array($type, self::ITEM_REF_TYPES, true)) {
            throw new CwException('bad_field', 'item_ref_type must be one of ' . implode(', ', self::ITEM_REF_TYPES), 422);
        }
        if ($itemRef === '') {
            throw new CwException('bad_field', 'item_ref is empty', 422);
        }
        [$skuId, $factor, $via] = $this->resolveItem($type, $itemRef, $codes);
        $sku = $this->db->one('SELECT id, code, merged_into_sku_id FROM sku WHERE id = ?', [$skuId])
            ?? throw new CwException('unknown_sku', "{$via} names item {$skuId}, which does not exist", 422);
        if ($sku['merged_into_sku_id'] !== null) {
            $into = $this->db->value('SELECT code FROM sku WHERE id = ?', [(int) $sku['merged_into_sku_id']]);
            throw new CwException('merged_item', "{$via} is item {$sku['code']}, which was merged into {$into}", 422);
        }
        $fields = [];
        foreach (['supplier_code', 'supplier_description', 'purchase_unit', 'moq_packs', 'order_multiple_packs', 'lead_days', 'is_preferred'] as $f) {
            if (isset($r[$f]) && trim($r[$f]) !== '') {
                $fields[$f] = $r[$f];
            }
        }
        $fileUpp = trim($r['units_per_pack'] ?? '') === '' ? 1 : (SupplierItems::normalise(['units_per_pack' => $r['units_per_pack']], null)['units_per_pack']);
        $central = (int) $fileUpp * $factor;
        if ($central > SupplierItems::MAX_UNITS_PER_PACK) {
            throw new CwException('bad_field', "units_per_pack {$fileUpp} × {$factor} central units is more than " . SupplierItems::MAX_UNITS_PER_PACK, 422);
        }
        $fields['units_per_pack'] = (string) $central;
        $norm = SupplierItems::normalise($fields, null);
        if (!isset($fields['is_preferred'])) {
            $fields['is_preferred'] = SupplierItems::PREFERRED_AUTO; // an empty column: preferred when the item has none yet (I75)
        }
        $price = trim($r['last_pack_price'] ?? '');
        $date = trim($r['last_price_date'] ?? '');
        if ($price !== '') {
            $price = SupplierItems::packPrice($price, 'last_pack_price');
        }
        if ($date !== '') {
            $date = Suppliers::date($date) ?? throw new CwException('bad_field', 'last_price_date must be a date, YYYY-MM-DD', 422);
        }
        return ['supplier_id' => (int) $s['id'], 'supplier_code' => (string) $s['code'], 'sku_id' => (int) $sku['id'], 'sku_code' => (string) $sku['code'],
            'fields' => $fields, 'central' => $central, 'preferred' => ($norm['is_preferred'] ?? 0) === 1, 'price' => $price === '' ? null : $price,
            'price_date' => $date === '' ? null : $date, 'price_ref' => trim($r['last_price_ref'] ?? '') === '' ? null : trim($r['last_price_ref']), 'via' => $via];
    }

    /**
     * @param array<string, list<string>>|null $codes
     * @return array{0: int, 1: int, 2: string} [sku id, central units per file unit, what resolved it]
     */
    private function resolveItem(string $type, string $ref, ?array $codes): array
    {
        switch ($type) {
            case 'cw_code':
                $id = preg_match('/^(?:cw-?)?0*([0-9]{1,10})$/iD', $ref, $m) === 1 ? (int) $m[1] : null;
                $sku = $id === null ? null : $this->db->value('SELECT id FROM sku WHERE id = ?', [$id]);
                if ($sku === null) {
                    throw new CwException('unknown_sku', "no item has the CW code {$ref}", 422);
                }
                return [(int) $sku, 1, "CW code {$ref}"];
            case 'vpg_code':
                if ($codes === null) {
                    throw new CwException('no_vpg_codes', 'vpg_code rows need --vpg-codes (the Vape and Go listings export)', 422);
                }
                $variants = $codes[mb_strtolower($ref)] ?? [];
                if ($variants === []) {
                    throw new CwException('unknown_vpg_code', "no Vape and Go variant has the code {$ref}", 422);
                }
                if (count($variants) > 1) {
                    throw new CwException('ambiguous_vpg_code', "the code {$ref} names " . count($variants) . ' Vape and Go variants (' . implode(', ', array_slice($variants, 0, 5)) . ')', 422);
                }
                return $this->listing($variants[0], "Vape and Go code {$ref} (variant {$variants[0]})");
            case 'vpg_variant':
                return $this->listing($ref, "Vape and Go variant {$ref}");
            case 'barcode':
                $key = Gtin::key($ref);
                $b = $this->db->one('SELECT sku_id, units_per_scan FROM sku_barcode WHERE barcode IN (?, ?) AND is_usable = 1 ORDER BY barcode = ? DESC LIMIT 1',
                    [$ref, $key ?? $ref, $ref]);
                if ($b === null) {
                    throw new CwException('unknown_barcode', "no item has the usable barcode {$ref}", 422);
                }
                return [(int) $b['sku_id'], (int) $b['units_per_scan'], "barcode {$ref}"];
            case 'erp_item':
                $e = $this->db->one('SELECT sku_id, units_per_item FROM sku_erp_item WHERE item_code = ?', [$ref]);
                if ($e === null) {
                    throw new CwException('unknown_erp_item', "the ERPNext item {$ref} is not linked to a CW item (sku_erp_item)", 422);
                }
                return [(int) $e['sku_id'], (int) $e['units_per_item'], "ERPNext item {$ref}"];
        }
        throw new \LogicException("unknown item_ref_type {$type}");
    }

    /** @return array{0: int, 1: int, 2: string} a Vape and Go listing's item: it must be linked (status mapped) */
    private function listing(string $variant, string $via): array
    {
        $l = $this->db->one('SELECT l.sku_id, l.units_per_item, l.status FROM channel_listing l JOIN channel c ON c.id = l.channel_id '
            . 'WHERE c.code = ? AND l.external_variant_id = ?', [self::VPG_CHANNEL, $variant]);
        if ($l === null) {
            throw new CwException('unknown_listing', "{$via}: CW has no such Vape and Go listing", 422);
        }
        if ($l['status'] !== 'mapped' || $l['sku_id'] === null) {
            throw new CwException('unlinked_listing', "{$via}: the listing is not linked to an item (status {$l['status']})", 422);
        }
        return [(int) $l['sku_id'], (int) $l['units_per_item'], $via];
    }

    /**
     * Pass 3: upserts one resolved row by (supplier, item, central pack), then its price.
     *
     * @param array<string, mixed> $x
     * @return array{status: string, reason: string}
     */
    private function itemRow(Caller $staff, array $x, int $runId): array
    {
        $existing = $this->db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND units_per_pack = ?',
            [$x['supplier_id'], $x['sku_id'], $x['central']]);
        $fields = $x['fields'];
        unset($fields['units_per_pack']);
        if ($existing !== null && ($fields['is_preferred'] ?? null) === SupplierItems::PREFERRED_AUTO) {
            unset($fields['is_preferred']); // "auto" applies to a new supplier item only
        }
        if ($existing === null) {
            $i = $this->items->create($staff, $x['supplier_id'], $x['sku_id'], $fields + ['units_per_pack' => (string) $x['central']]);
            $status = 'created';
            $reason = "{$x['supplier_code']} {$x['sku_code']} in packs of {$x['central']}";
        } else {
            $i = $this->items->update($staff, (int) $existing['id'], (int) $existing['version'], $fields);
            $status = (int) $i['version'] === (int) $existing['version'] ? 'skipped' : 'updated';
            $reason = "{$x['supplier_code']} {$x['sku_code']} in packs of {$x['central']}" . ($status === 'skipped' ? ': already in CW, unchanged' : ': updated');
        }
        if ($x['price'] !== null) {
            $this->items->importPrice($staff, (int) $i['id'], $x['price'], $x['price_date'], $x['price_ref'] ?? "import_run:{$runId}");
            $status = $status === 'skipped' ? 'updated' : $status;
            $reason .= "; price {$x['price']}" . ($x['price_date'] !== null ? " on {$x['price_date']}" : '');
        }
        return ['status' => $status, 'reason' => $reason . " ({$x['via']})"];
    }

    /**
     * The code -> variant ids map of a Vape and Go listings export (.jsonl or .jsonl.gz: one JSON object per line with
     * `code` and `variant_id`); codes compare trimmed and case-insensitively.
     *
     * @return array<string, list<string>>
     */
    public static function vpgCodes(string $path): array
    {
        $h = @gzopen($path, 'rb');
        if ($h === false) {
            throw new CwException('bad_file', 'cannot read the listings export ' . basename($path), 400);
        }
        $map = [];
        try {
            while (($line = gzgets($h)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $o = json_decode($line, true);
                if (!is_array($o) || !isset($o['variant_id'])) {
                    continue;
                }
                $code = is_scalar($o['code'] ?? null) ? mb_strtolower(trim((string) $o['code'])) : '';
                if ($code === '') {
                    continue;
                }
                $v = (string) $o['variant_id'];
                if (!in_array($v, $map[$code] ?? [], true)) {
                    $map[$code][] = $v;
                }
            }
        } finally {
            gzclose($h);
        }
        return $map;
    }
}
