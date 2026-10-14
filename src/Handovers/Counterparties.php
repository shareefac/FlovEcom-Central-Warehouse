<?php

declare(strict_types=1);

namespace CW\Handovers;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\Company\CompanyDetails;
use CW\CwException;
use CW\Db;
use CW\Matching\Gtin;
use CW\Output\CsvReader;
use CW\Settings;
use CW\Staff\StaffRoles;

/**
 * Who we hand stock to (pack H, IM11; docs/decisions.md PH2, PH6, PH7): a sister shop (another company: Electrofag, Vape Big), one of
 * our shop units, or a trade customer. One list, `counterparty`: the name printed on the invoice, the address, company and VAT numbers,
 * an optional VAT code of their own (empty: our company's), contact and a note. Added and changed by the people who keep the customer
 * list (handovers.customers, never admin), with the version the form was drawn with (409 customer_changed when stale); switched off,
 * never deleted. Every change is audited with what changed.
 *
 * Prices remembered (E47): `counterparty_price` holds the price a customer last paid for an item. A hand-over made final writes it
 * (HandoverHandler); the past ERPNext trade-invoice lines are loaded here from a CSV (importPrices): rows that cannot be read for sure are
 * skipped and listed (the owner's rule: skip the doubtful, finish the rest, show the skipped at the end), never guessed. A price is only
 * replaced by a newer one (its day).
 *
 * Field problems come back together: 422 customer_invalid with detail.errors = field => a problem CODE (the screens word them:
 * Ui\Words::CUSTOMER_ERROR) and detail.values = what was typed, tidied.
 */
final class Counterparties
{
    public const KINDS = ['sister', 'unit', 'trade'];
    public const FIELDS = ['kind', 'name', 'address', 'company_number', 'vat_number', 'vat_code', 'email', 'phone', 'note'];
    public const PERMISSION = 'handovers.customers';
    public const NAME_MIN = 2;
    public const NAME_MAX = 160;
    public const ADDRESS_LINES = 8;
    public const ADDRESS_LINE_MAX = 100;
    public const EMAIL_MAX = 191;
    public const PHONE_MAX = 32;
    public const NOTE_MAX = 500;
    public const LIST_LIMIT = 500;
    public const PRICE_LIMIT = 500;
    /** The longest identifiers an import compares, as their columns hold them (sku_erp_item.item_code, channel_listing.external_variant_id). */
    public const ERP_CODE_MAX = 140;
    public const VARIANT_ID_MAX = 64;
    /** The columns a file of past prices may name, each with the spellings accepted (header names as CsvReader gives them). */
    public const IMPORT_COLUMNS = [
        'customer' => ['customer', 'customer_name', 'name_of_customer'],
        'item' => ['item', 'item_code', 'cw_number', 'barcode', 'product'],
        'price' => ['price', 'rate', 'unit_price', 'price_per_unit'],
        'date' => ['date', 'posting_date', 'invoice_date'],
        'invoice' => ['invoice', 'invoice_number', 'sales_invoice', 'reference'],
    ];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock (the day an import without dates is priced) */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    // ------------------------------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------------------------------

    /**
     * The customers for the list (switched-on first, by name; at most LIST_LIMIT): id, kind, name, is_active, vat_code, how many
     * hand-overs are final, the balance owed, the last hand-over's day. f: q (name, company or VAT number, e-mail), kind, off (show
     * the switched-off ones too).
     *
     * @param array{q?: string, kind?: ?string, off?: bool} $f
     * @return list<array<string, mixed>>
     */
    public function list(array $f = []): array
    {
        $where = [];
        $params = [];
        if (!($f['off'] ?? false)) {
            $where[] = 'c.is_active = 1';
        }
        if (($f['kind'] ?? null) !== null && in_array($f['kind'], self::KINDS, true)) {
            $where[] = 'c.kind = ?';
            $params[] = $f['kind'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(c.name LIKE ? OR c.company_number LIKE ? OR c.vat_number LIKE ? OR c.email LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        return $this->db->all(
            'SELECT c.id, c.kind, c.name, c.is_active, c.vat_code, c.company_number, c.vat_number, c.email, c.version, '
            . '(SELECT COUNT(*) FROM handover h JOIN document d ON d.id = h.document_id WHERE h.counterparty_id = c.id AND d.status IN (\'posted\', \'reversed\') '
            . 'AND d.reverses_id IS NULL) AS handovers, '
            . '(SELECT COALESCE(SUM(e.amount), 0) FROM counterparty_entry e WHERE e.counterparty_id = c.id) AS balance, '
            . '(SELECT MAX(d.doc_date) FROM handover h JOIN document d ON d.id = h.document_id WHERE h.counterparty_id = c.id AND d.status IN (\'posted\', \'reversed\') '
            . 'AND d.reverses_id IS NULL) AS last_handover '
            . 'FROM counterparty c' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY c.is_active DESC, c.name LIMIT ' . self::LIST_LIMIT,
            $params,
        );
    }

    /** @return array<string, mixed>|null one customer with who made and last changed it */
    public function get(int $id): ?array
    {
        return $this->db->one('SELECT c.*, cb.display_name AS created_by_name, ub.display_name AS updated_by_name FROM counterparty c '
            . 'LEFT JOIN staff_user cb ON cb.id = c.created_by LEFT JOIN staff_user ub ON ub.id = c.updated_by WHERE c.id = ?', [$id]);
    }

    /**
     * The prices a customer last paid, newest first (at most PRICE_LIMIT): sku_id, code, name, unit_price, priced_on, source, the hand-over
     * (id, number) or the old invoice it came from.
     *
     * @return list<array<string, mixed>>
     */
    public function prices(int $id, int $limit = self::PRICE_LIMIT): array
    {
        return $this->db->all(
            'SELECT p.sku_id, s.code, s.name, p.unit_price, p.priced_on, p.source, p.document_id, d.number, p.source_ref FROM counterparty_price p '
            . 'LEFT JOIN sku s ON s.id = p.sku_id LEFT JOIN document d ON d.id = p.document_id WHERE p.counterparty_id = ? '
            . 'ORDER BY p.priced_on DESC, p.sku_id LIMIT ' . max(1, min(5000, $limit)),
            [$id],
        );
    }

    /**
     * The remembered price of each item for this customer (sku id => pounds per unit, 6 decimals); items without one are left out.
     *
     * @param list<int> $skuIds
     * @return array<int, string>
     */
    public function lastPrices(int $counterpartyId, array $skuIds): array
    {
        $skuIds = array_values(array_unique(array_map('intval', $skuIds)));
        if ($skuIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($skuIds, 500) as $chunk) {
            foreach ($this->db->all('SELECT sku_id, unit_price FROM counterparty_price WHERE counterparty_id = ? AND sku_id IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')', [$counterpartyId, ...$chunk]) as $r) {
                $out[(int) $r['sku_id']] = (string) $r['unit_price'];
            }
        }
        return $out;
    }

    /** @return list<array{code: string, label: string, rate: string}> the VAT codes in use, in their order */
    public function vatCodes(): array
    {
        return array_map(static fn (array $r): array => ['code' => (string) $r['code'], 'label' => (string) $r['label'], 'rate' => (string) $r['rate_percent']],
            $this->db->all('SELECT code, label, rate_percent FROM vat_code WHERE is_active = 1 ORDER BY sort_order, code'));
    }

    // ------------------------------------------------------------------------------------------
    // Changing the list
    // ------------------------------------------------------------------------------------------

    /**
     * Adds a customer (FIELDS; kind and name required). 409 customer_exists (the name is taken, whatever its case). Audit
     * counterparty.add. Returns the new id.
     *
     * @param array<string, mixed> $in
     */
    public function add(Caller $caller, array $in): int
    {
        $v = $this->checked($in);
        return $this->db->transaction(function (Db $db) use ($caller, $v): int {
            $this->authorise($db, $caller);
            $now = Clock::db(($this->clock)());
            try {
                $id = $db->insert('INSERT INTO counterparty (kind, name, address, company_number, vat_number, vat_code, email, phone, note, created_by, created_actor, '
                    . 'created_at, updated_by, updated_actor, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$v['kind'], $v['name'], $v['address'], $v['company_number'], $v['vat_number'], $v['vat_code'], $v['email'], $v['phone'], $v['note'],
                        $caller->staffUserId, $caller->actor, $now, $caller->staffUserId, $caller->actor, $now]);
            } catch (\PDOException $e) {
                if (Db::driverCode($e) === 1062) {
                    throw new CwException('customer_exists', "there is a customer called {$v['name']} already", 409, ['field' => 'name']);
                }
                throw $e;
            }
            Audit::write($db, $caller, 'counterparty.add', 'counterparty', (string) $id, null, $v);
            return $id;
        });
    }

    /**
     * Saves a customer's details as typed ($version: the one the form was drawn with). Returns `saved` (with the fields changed) or
     * `unchanged` (nothing written). 404 unknown_customer; 409 customer_changed / customer_exists; 422 customer_invalid. Audit
     * counterparty.change {version, changed, before, after}.
     *
     * @param array<string, mixed> $in
     * @return array{result: string, version: int, changed: list<string>}
     */
    public function save(Caller $caller, int $id, int $version, array $in): array
    {
        $v = $this->checked($in);
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $v): array {
            $this->authorise($db, $caller);
            $cur = $this->locked($id, $version);
            $changed = [];
            foreach (self::FIELDS as $k) {
                if ($v[$k] !== ($cur[$k] === null ? null : (string) $cur[$k])) {
                    $changed[] = $k;
                }
            }
            if ($changed === []) {
                return ['result' => 'unchanged', 'version' => (int) $cur['version'], 'changed' => []];
            }
            try {
                $db->exec('UPDATE counterparty SET kind = ?, name = ?, address = ?, company_number = ?, vat_number = ?, vat_code = ?, email = ?, phone = ?, note = ?, '
                    . 'version = version + 1, updated_by = ?, updated_actor = ?, updated_at = ? WHERE id = ?',
                    [$v['kind'], $v['name'], $v['address'], $v['company_number'], $v['vat_number'], $v['vat_code'], $v['email'], $v['phone'], $v['note'],
                        $caller->staffUserId, $caller->actor, Clock::db(($this->clock)()), $id]);
            } catch (\PDOException $e) {
                if (Db::driverCode($e) === 1062) {
                    throw new CwException('customer_exists', "there is a customer called {$v['name']} already", 409, ['field' => 'name']);
                }
                throw $e;
            }
            $only = array_flip($changed);
            Audit::write($db, $caller, 'counterparty.change', 'counterparty', (string) $id, null, ['version' => $version + 1, 'changed' => $changed,
                'before' => array_intersect_key(array_intersect_key($cur, array_flip(self::FIELDS)), $only), 'after' => array_intersect_key($v, $only)]);
            return ['result' => 'saved', 'version' => $version + 1, 'changed' => $changed];
        });
    }

    /**
     * Switches a customer off (no new hand-over names it; a draft that names it cannot be made final) or on again. Its hand-overs,
     * prices and balance stay. 409 customer_changed. Audit counterparty.switch_off / switch_on.
     *
     * @return array{result: string, version: int}
     */
    public function setActive(Caller $caller, int $id, int $version, bool $on): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $on): array {
            $this->authorise($db, $caller);
            $cur = $this->locked($id, $version);
            if ((int) $cur['is_active'] === ($on ? 1 : 0)) {
                return ['result' => 'unchanged', 'version' => (int) $cur['version']];
            }
            $db->exec('UPDATE counterparty SET is_active = ?, version = version + 1, updated_by = ?, updated_actor = ?, updated_at = ? WHERE id = ?',
                [$on ? 1 : 0, $caller->staffUserId, $caller->actor, Clock::db(($this->clock)()), $id]);
            Audit::write($db, $caller, $on ? 'counterparty.switch_on' : 'counterparty.switch_off', 'counterparty', (string) $id, null, ['version' => $version + 1]);
            return ['result' => $on ? 'on' : 'off', 'version' => $version + 1];
        });
    }

    // ------------------------------------------------------------------------------------------
    // Prices from the past trade invoices
    // ------------------------------------------------------------------------------------------

    /**
     * Loads past trade-invoice lines (a CSV: customer, item, price, and optionally date and invoice; IMPORT_COLUMNS) into the remembered
     * prices. Per row: the customer by its exact name (case does not matter); the item by its CW number, a usable barcode of one unit, or
     * an ERPNext item code (its override, else the one item every linked website variant with that code points at, one unit each); the
     * price per unit before VAT (pounds, up to 6 decimals); the day (2026-10-14 or 14/10/2026; none: today). A row that cannot be read
     * for sure is skipped with its reason; the rest are loaded. For one customer and item the newest row wins, and a price already
     * remembered from a later day is kept. Audit counterparty.price_import {file sha256, rows, imported, kept, skipped}.
     *
     * @return array{rows: int, imported: int, kept_newer: int, skipped: list<array{row: int, why: string, value: string}>}
     */
    public function importPrices(Caller $caller, string $path, Settings $settings): array
    {
        $this->authorise($this->db, $caller); // who first: a person who may not load prices learns nothing about the file
        $max = (int) ($settings->get('handovers.price_import_max_rows') ?? 5000);
        $table = CsvReader::table($path, CsvReader::DEFAULT_MAX_BYTES, max(1, $max));
        $cols = [];
        foreach (self::IMPORT_COLUMNS as $field => $names) {
            foreach ($names as $n) {
                if (in_array($n, $table['header'], true)) {
                    $cols[$field] = $n;
                    break;
                }
            }
        }
        foreach (['customer', 'item', 'price'] as $need) {
            if (!isset($cols[$need])) {
                throw new CwException('import_columns', 'the file needs the columns customer, item and price (date and invoice are optional)', 400,
                    ['missing' => $need]);
            }
        }
        // The whole file (at most the setting's rows) is read BEFORE the transaction: a deadlock retry runs the transaction again and
        // must see every row again (review finding 2 of 14 Oct: the rows were a generator, empty on the second run).
        $lines = [];
        foreach ($table['rows'] as $no => $row) {
            $lines[$no] = $row;
        }
        $today = ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
        $hash = hash_file('sha256', $path) ?: '';
        return $this->db->transaction(function (Db $db) use ($caller, $lines, $cols, $today, $hash): array {
            $this->authorise($db, $caller);
            $customers = [];
            foreach ($db->all('SELECT id, name FROM counterparty') as $c) {
                $customers[mb_strtolower(trim((string) $c['name']))] = (int) $c['id'];
            }
            $skipped = [];
            $best = [];
            $rows = 0;
            foreach ($lines as $no => $row) {
                $rows++;
                $name = mb_strtolower(trim((string) ($row[$cols['customer']] ?? '')));
                $customer = $customers[$name] ?? null;
                if ($customer === null) {
                    $skipped[] = ['row' => $no, 'why' => 'unknown_customer', 'value' => mb_substr(trim((string) ($row[$cols['customer']] ?? '')), 0, 60)];
                    continue;
                }
                $itemText = trim((string) ($row[$cols['item']] ?? ''));
                $item = $this->resolveItem($itemText);
                if (!is_int($item)) {
                    $skipped[] = ['row' => $no, 'why' => $item, 'value' => mb_substr($itemText, 0, 60)];
                    continue;
                }
                $priceText = trim(str_replace(['£', ',', ' '], '', (string) ($row[$cols['price']] ?? '')));
                if (preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,6}))?$/D', $priceText, $m) !== 1) {
                    $skipped[] = ['row' => $no, 'why' => 'bad_price', 'value' => mb_substr($priceText, 0, 30)];
                    continue;
                }
                $price = $m[1] . '.' . str_pad($m[2] ?? '', 6, '0');
                $day = $today;
                if (isset($cols['date']) && trim((string) ($row[$cols['date']] ?? '')) !== '') {
                    $day = self::day(trim((string) $row[$cols['date']]));
                    if ($day === null || $day > $today) {
                        $skipped[] = ['row' => $no, 'why' => 'bad_date', 'value' => mb_substr(trim((string) $row[$cols['date']]), 0, 30)];
                        continue;
                    }
                }
                $ref = isset($cols['invoice']) ? trim((string) ($row[$cols['invoice']] ?? '')) : '';
                if (mb_strlen($ref) > 100) {
                    $skipped[] = ['row' => $no, 'why' => 'bad_reference', 'value' => mb_substr($ref, 0, 60)];
                    continue;
                }
                $key = $customer . ':' . $item;
                if (!isset($best[$key]) || $best[$key]['day'] <= $day) {
                    $best[$key] = ['customer' => $customer, 'sku' => $item, 'price' => $price, 'day' => $day, 'ref' => $ref === '' ? null : $ref];
                }
            }
            $imported = 0;
            $kept = 0;
            $now = Clock::db(($this->clock)());
            // One order for every import (customer, then item): two imports at the same time lock the same rows in the same order.
            usort($best, static fn (array $a, array $b): int => [$a['customer'], $a['sku']] <=> [$b['customer'], $b['sku']]);
            foreach ($best as $b) {
                // The day is compared INSIDE the write (review finding 1 of 14 Oct): a read first locks nothing where no price is yet,
                // so a newer price another session wrote meanwhile was overwritten. A price from a later day is kept; the same day or
                // an earlier one is replaced. Then the row (now locked by this write) says which.
                $db->exec('INSERT INTO counterparty_price (counterparty_id, sku_id, unit_price, priced_on, source, document_id, source_ref, updated_by, updated_actor, updated_at) '
                    . "VALUES (?, ?, ?, ?, 'import', NULL, ?, ?, ?, ?) AS new ON DUPLICATE KEY UPDATE "
                    . 'unit_price = IF(new.priced_on >= counterparty_price.priced_on, new.unit_price, counterparty_price.unit_price), '
                    . 'source = IF(new.priced_on >= counterparty_price.priced_on, new.source, counterparty_price.source), '
                    . 'document_id = IF(new.priced_on >= counterparty_price.priced_on, NULL, counterparty_price.document_id), '
                    . 'source_ref = IF(new.priced_on >= counterparty_price.priced_on, new.source_ref, counterparty_price.source_ref), '
                    . 'updated_by = IF(new.priced_on >= counterparty_price.priced_on, new.updated_by, counterparty_price.updated_by), '
                    . 'updated_actor = IF(new.priced_on >= counterparty_price.priced_on, new.updated_actor, counterparty_price.updated_actor), '
                    . 'updated_at = IF(new.priced_on >= counterparty_price.priced_on, new.updated_at, counterparty_price.updated_at), '
                    . 'priced_on = GREATEST(new.priced_on, counterparty_price.priced_on)',
                    [$b['customer'], $b['sku'], $b['price'], $b['day'], $b['ref'], $caller->staffUserId, $caller->actor, $now]);
                $day = (string) $db->value('SELECT priced_on FROM counterparty_price WHERE counterparty_id = ? AND sku_id = ?', [$b['customer'], $b['sku']]);
                if ($day > $b['day']) {
                    $kept++;
                } else {
                    $imported++;
                }
            }
            Audit::write($db, $caller, 'counterparty.price_import', 'counterparty_price', null, null, ['sha256' => $hash, 'rows' => $rows, 'imported' => $imported,
                'kept_newer' => $kept, 'skipped' => count($skipped)]);
            return ['rows' => $rows, 'imported' => $imported, 'kept_newer' => $kept, 'skipped' => $skipped];
        });
    }

    /**
     * The one item a text of the import names, or why not (unknown_item, item_doubtful): a CW number; a usable barcode that stands for one
     * unit; an ERPNext item code's override (one unit); else every mapped website variant whose variant id is the code, when they all point
     * at the same item, one unit each.
     */
    public function resolveItem(string $text): int|string
    {
        if ($text === '') {
            return 'unknown_item';
        }
        if (preg_match('/^cw-?0*([0-9]{1,10})$/iD', $text, $m) === 1) {
            $id = $this->db->value('SELECT id FROM sku WHERE id = ? AND merged_into_sku_id IS NULL', [(int) $m[1]]);
            return $id === null ? 'unknown_item' : (int) $id;
        }
        if (preg_match('/^[0-9]{8,14}$/D', $text) === 1) {
            $rows = $this->db->all('SELECT b.sku_id, b.units_per_scan FROM sku_barcode b JOIN sku s ON s.id = b.sku_id WHERE b.barcode IN (?, ?) AND b.is_usable = 1 '
                . 'AND s.merged_into_sku_id IS NULL', [$text, Gtin::key($text) ?? $text]);
            if ($rows !== []) {
                $skus = array_unique(array_map(static fn (array $r): int => (int) $r['sku_id'], $rows));
                $units = array_unique(array_map(static fn (array $r): int => (int) $r['units_per_scan'], $rows));
                return count($skus) === 1 && $units === [1] ? (int) $skus[0] : 'item_doubtful';
            }
        }
        // Whole identifiers only (review finding 5 of 14 Oct): a text longer than the key it is compared with names nothing, and a
        // match must be the same text exactly (the columns' collation ignores case).
        if (mb_strlen($text) <= self::ERP_CODE_MAX) {
            $o = $this->db->one('SELECT e.item_code, e.sku_id, e.units_per_item FROM sku_erp_item e JOIN sku s ON s.id = e.sku_id '
                . 'WHERE e.item_code = ? AND s.merged_into_sku_id IS NULL', [$text]);
            if ($o !== null && (string) $o['item_code'] === $text) {
                return (int) $o['units_per_item'] === 1 ? (int) $o['sku_id'] : 'item_doubtful';
            }
        }
        if (mb_strlen($text) > self::VARIANT_ID_MAX) {
            return 'unknown_item';
        }
        $links = array_values(array_filter($this->db->all("SELECT external_variant_id, sku_id, units_per_item FROM channel_listing WHERE external_variant_id = ? "
            . "AND status = 'mapped' AND sku_id IS NOT NULL", [$text]), static fn (array $r): bool => (string) $r['external_variant_id'] === $text));
        if ($links === []) {
            return 'unknown_item';
        }
        $skus = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['sku_id'], $links)));
        $units = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['units_per_item'], $links)));
        return count($skus) === 1 && $units === [1] ? $skus[0] : 'item_doubtful';
    }

    // ------------------------------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------------------------------

    /**
     * What a customer form sent, checked and tidied (pure: the VAT code's existence is checked by checked()). One 422 customer_invalid
     * lists every problem (detail.errors: field => code; detail.values: the tidied values).
     *
     * @param array<string, mixed> $in
     * @return array{kind: string, name: string, address: string, company_number: string, vat_number: string, vat_code: ?string, email: string, phone: string, note: ?string}
     */
    public static function check(array $in): array
    {
        $errors = [];
        $text = static fn (string $k): string => is_string($in[$k] ?? null) ? (string) $in[$k] : '';
        $v = [];
        $kind = trim($text('kind'));
        if (!in_array($kind, self::KINDS, true)) {
            $errors['kind'] = 'kind';
        }
        $v['kind'] = $kind;
        $v['name'] = self::oneLine($text('name'), self::NAME_MAX, 'name', $errors);
        if (!isset($errors['name']) && mb_strlen($v['name']) < self::NAME_MIN) {
            $errors['name'] = 'name_short';
        }
        $v['address'] = self::address($text('address'), $errors);
        $raw = $text('company_number');
        $cn = mb_check_encoding($raw, 'UTF-8') ? strtoupper((string) preg_replace('/[\s\-]+/u', '', $raw)) : '';
        if ($cn !== '' && preg_match(CompanyDetails::COMPANY_NUMBER_PATTERN, $cn) !== 1) {
            $errors['company_number'] = 'company_number';
            $cn = mb_substr(trim($raw), 0, 16);
        }
        $v['company_number'] = $cn;
        $raw = $text('vat_number');
        $vn = mb_check_encoding($raw, 'UTF-8') ? strtoupper((string) preg_replace('/[\s.\-]+/u', '', $raw)) : '';
        if (preg_match('/^([0-9]{9}|[0-9]{12})$/D', $vn) === 1) {
            $vn = 'GB' . $vn;
        }
        if ($vn !== '' && preg_match('/^(GB|XI)([0-9]{9}|[0-9]{12})$/D', $vn) !== 1) {
            $errors['vat_number'] = 'vat_number';
            $vn = mb_substr(trim($raw), 0, 16);
        }
        $v['vat_number'] = $vn;
        $code = strtoupper(trim($text('vat_code')));
        if ($code !== '' && preg_match('/^[A-Z]{1,4}$/D', $code) !== 1) {
            $errors['vat_code'] = 'vat_code';
        }
        $v['vat_code'] = $code === '' ? null : $code;
        $email = trim($text('email'));
        if ($email !== '' && (!mb_check_encoding($email, 'UTF-8') || mb_strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'] = 'email';
        }
        $v['email'] = $email;
        $phone = mb_check_encoding($text('phone'), 'UTF-8') ? trim((string) preg_replace('/\s+/u', ' ', $text('phone'))) : '';
        if ($phone !== '' && (mb_strlen($phone) > self::PHONE_MAX || preg_match('/^\+?[0-9 ()\-.]+$/D', $phone) !== 1
            || !in_array(strlen((string) preg_replace('/\D/', '', $phone)), range(7, 15), true))) {
            $errors['phone'] = 'phone';
        }
        $v['phone'] = $phone;
        $note = self::oneLine($text('note'), self::NOTE_MAX, 'note', $errors, false);
        $v['note'] = $note === '' ? null : $note;
        if ($errors !== []) {
            throw new CwException('customer_invalid', 'some details need correcting: ' . implode(', ', array_keys($errors)), 422, ['errors' => $errors, 'values' => $v]);
        }
        return $v;
    }

    /** check() and the VAT code in use (detail.errors.vat_code = vat_code). @param array<string, mixed> $in @return array<string, mixed> */
    private function checked(array $in): array
    {
        $v = self::check($in);
        if ($v['vat_code'] !== null && $this->db->value('SELECT 1 FROM vat_code WHERE code = ? AND is_active = 1', [$v['vat_code']]) === null) {
            throw new CwException('customer_invalid', 'some details need correcting: vat_code', 422, ['errors' => ['vat_code' => 'vat_code'], 'values' => $v]);
        }
        return $v;
    }

    /** Whether these roles may keep the customer list (never admin). @param list<string> $roles */
    public static function mayManage(array $roles): bool
    {
        return !in_array('admin', $roles, true) && Permissions::can($roles, self::PERMISSION);
    }

    /** "2026-10-14" or "14/10/2026" as Y-m-d, or null. */
    public static function day(string $v): ?string
    {
        foreach (['!Y-m-d' => '/^\d{4}-\d{2}-\d{2}$/D', '!d/m/Y' => '/^\d{1,2}\/\d{1,2}\/\d{4}$/D'] as $format => $shape) {
            if (preg_match($shape, $v) === 1) {
                $d = \DateTimeImmutable::createFromFormat($format, $v, Clock::utc());
                if ($d !== false && \DateTimeImmutable::getLastErrors() === false) {
                    $out = $d->format('Y-m-d');
                    return $out >= '2000-01-01' ? $out : null;
                }
            }
        }
        return null;
    }

    /** The row locked, at the version the form was drawn with (404 unknown_customer, 409 customer_changed). @return array<string, mixed> */
    private function locked(int $id, int $version): array
    {
        $cur = $this->db->one('SELECT * FROM counterparty WHERE id = ? FOR UPDATE', [$id]) ?? throw new CwException('unknown_customer', 'there is no such customer', 404);
        if ((int) $cur['version'] !== $version) {
            throw new CwException('customer_changed', 'someone changed this customer while you had it open: nothing was saved', 409,
                ['version' => (int) $cur['version'], 'expected_version' => $version]);
        }
        return $cur;
    }

    private function authorise(Db $db, Caller $caller): void
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'the customer list is kept by staff', 403);
        }
        $roles = StaffRoles::active($db, $caller->staffUserId);
        if (!self::mayManage($roles)) {
            throw new CwException(in_array('admin', $roles, true) ? 'admin_cannot_post' : 'role_not_allowed', 'you cannot change the customer list', 403);
        }
    }

    /** @param array<string, string> $errors */
    private static function oneLine(string $raw, int $max, string $field, array &$errors, bool $printable = true): string
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $errors[$field] = 'not_text';
            return '';
        }
        $v = self::nfc($raw);
        $control = preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}]/u', $v) === 1;
        $v = trim((string) preg_replace('/[\t\r\n ]+/', ' ', $v));
        if ($control) {
            $errors[$field] = 'control';
        } elseif (mb_strlen($v) > $max) {
            $errors[$field] = $field === 'note' ? 'note_long' : 'name_long';
        } elseif ($printable && CompanyDetails::unprintable($v) !== null) {
            $errors[$field] = 'unprintable';
        }
        return $v;
    }

    /** An address: one line per line, blank lines dropped, at most ADDRESS_LINES of ADDRESS_LINE_MAX, printable. @param array<string, string> $errors */
    private static function address(string $raw, array &$errors): string
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $errors['address'] = 'not_text';
            return '';
        }
        $v = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], self::nfc($raw));
        $control = preg_match('/[\x00-\x09\x0B-\x1F\x7F\x{0080}-\x{009F}]/u', $v) === 1;
        $lines = [];
        foreach (explode("\n", $v) as $l) {
            $l = trim((string) preg_replace('/ {2,}/', ' ', $l));
            if ($l !== '') {
                $lines[] = $l;
            }
        }
        $out = implode("\n", $lines);
        if ($control) {
            $errors['address'] = 'control';
        } elseif (count($lines) > self::ADDRESS_LINES) {
            $errors['address'] = 'lines_many';
        } elseif (array_filter($lines, static fn (string $l): bool => mb_strlen($l) > self::ADDRESS_LINE_MAX) !== []) {
            $errors['address'] = 'line_long';
        } elseif (CompanyDetails::unprintable($out) !== null) {
            $errors['address'] = 'unprintable';
        }
        return $out;
    }

    private static function nfc(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            return is_string($n) ? $n : $s;
        }
        return $s;
    }
}
