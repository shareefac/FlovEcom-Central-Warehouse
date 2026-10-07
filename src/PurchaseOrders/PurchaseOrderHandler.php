<?php

declare(strict_types=1);

namespace CW\PurchaseOrders;

use CW\Audit;
use CW\Caller;
use CW\Catalogue\ItemCompliance;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\DocumentHandler;
use CW\Idempotency;
use CW\Settings;

/**
 * The PO document type (IM5; docs/decisions.md I48-I59, spec §6.2): the first real DocumentHandler. A purchase order
 * BOOKS NO STOCK: approving one (Documents::post, the "posting") numbers it, fixes its content and opens its review; goods
 * arrive later on a GRN (Phase I-3), which reports its receipts through PurchaseOrders::applyReceipt.
 *
 *  validate()       the purchase_order row exists; its supplier (FOR SHARE: a deactivation cannot commit in between) is
 *                   active, has no open import-route approval (I72) and, overseas, has an approved import route; the
 *                   document's warehouse is sellable; every line
 *                   has its po_line and follows the PO formulas (item: qty = packs × units per pack, unit cost and amount
 *                   from PoMath; charge: no item, no qty, amount = pack price > 0); every VAT code is active at the rate
 *                   the line carries; at most 2,000 lines.
 *  approvalUnits()  ceil(net total) in whole GBP (over_value, decision 11: a blocking approval above £10,000).
 *  post()           purchase_order FOR UPDATE; totals; the company and supplier snapshots; state approved; a `po` price
 *                   history row per item line with a supplier item and its last_po_*; the write-once po_posting anchor
 *                   (the module content at approval, P2); audit po.approve. Returns ceil(net) (the review units).
 *  reverse()        the original's purchase_order FOR UPDATE: 409 po_has_receipts once a line has receipts, 409
 *                   po_completed when received or closed; otherwise state cancelled, and last_po_* of the supplier items
 *                   it set go back to their newest order that still stands (I81).
 *
 * Lock order (spec §6.9, extends I21): the document row (Documents) -> the supplier FOR SHARE (validate) -> number_series
 * (Documents) -> purchase_order -> supplier_item (id order) -> supplier_item_price -> po_posting. Never a stock lock.
 */
final class PurchaseOrderHandler implements DocumentHandler
{
    public const MAX_LINES = 2000;

    private readonly Settings $settings;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?Settings $settings = null, ?\Closure $clock = null)
    {
        $this->settings = $settings ?? new Settings($db);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    public function type(): string
    {
        return 'PO';
    }

    public function validate(Db $db, Document $doc, array $lines): void
    {
        $po = $db->one('SELECT document_id, supplier_id FROM purchase_order WHERE document_id = ?', [$doc->id]);
        if ($po === null) {
            throw new CwException('po_header_missing', "{$doc->label()} has no purchase order header", 422);
        }
        $s = $db->one('SELECT id, code, status, is_overseas, import_route_approved_at FROM supplier WHERE id = ? FOR SHARE', [(int) $po['supplier_id']])
            ?? throw new CwException('supplier_not_active', 'the supplier of this order does not exist', 422);
        if ($s['status'] !== 'active') {
            throw new CwException('supplier_not_active', "supplier {$s['code']} is " . str_replace('_', ' ', (string) $s['status'])
                . ': a purchase order is approved only for an active supplier (a second person approves the supplier first)', 422, ['status' => $s['status']]);
        }
        if ((int) $s['is_overseas'] === 1 && $s['import_route_approved_at'] === null) {
            throw new CwException('import_route_not_approved', "supplier {$s['code']} is overseas and its import route (where UK duty stamps are applied) "
                . 'is not approved: a second person approves it on the supplier\'s page first', 422);
        }
        // An open import-route approval blocks whatever is_overseas says now (I72): an overseas supplier made UK waits for
        // the second person too. READ COMMITTED: the supplier FOR SHARE above waited for any supplier write in flight, and
        // every task is opened by a transaction that wrote the supplier row first, so this read sees it.
        if ($db->value('SELECT 1 FROM review_task WHERE open_key = ? AND reason = ?', ['supplier:' . (int) $s['id'] . ':approval', 'import_route']) !== null) {
            throw new CwException('import_route_not_approved', "supplier {$s['code']}: a change of its import route or of its overseas status waits for a second "
                . 'person\'s approval on the supplier\'s page', 422);
        }
        $wh = $doc->warehouseId === null ? null : $db->one('SELECT code, is_sellable FROM warehouse WHERE id = ?', [$doc->warehouseId]);
        if ($wh === null || (int) $wh['is_sellable'] !== 1) {
            throw new CwException('bad_warehouse', 'a purchase order delivers to a sellable warehouse (MAIN)' . ($wh === null ? '' : ", not {$wh['code']}"), 422);
        }
        if (count($lines) > self::MAX_LINES) {
            throw new CwException('too_many_lines', 'a purchase order has at most ' . self::MAX_LINES . ' lines', 422);
        }
        $poLines = self::poLines($db, $doc->id);
        $vat = [];
        foreach ($db->all('SELECT code, rate_percent, is_active FROM vat_code') as $v) {
            $vat[(string) $v['code']] = $v;
        }
        foreach ($lines as $l) {
            $no = (int) $l['line_no'];
            $pl = $poLines[$no] ?? throw new CwException('po_line_missing', "line {$no} has no purchase order details", 422, ['line' => $no]);
            self::checkLine($l, $pl, $no);
            $code = (string) $pl['vat_code'];
            if (!isset($vat[$code]) || (int) $vat[$code]['is_active'] !== 1) {
                throw new CwException('vat_code_inactive', "line {$no}: the VAT code {$code} is no longer in use: choose another", 422, ['line' => $no]);
            }
            if (PoMath::e2((string) $vat[$code]['rate_percent']) !== PoMath::e2((string) $pl['vat_rate'])) {
                throw new CwException('vat_rate_changed', "line {$no}: the rate of VAT code {$code} changed since the line was saved: save the draft again", 422,
                    ['line' => $no]);
            }
        }
        if (count($poLines) !== count($lines)) {
            throw new CwException('po_line_missing', 'the purchase order details do not match its lines: save the draft again', 422);
        }
        // IM3 (I103, I113): an item a person confirmed breaks a TRPR or the single-use rule is not ordered (422 item_blocked). The cards
        // are read FOR SHARE (I122): a confirmation committing during the approval is seen, or waits until the approval is done.
        $skus = [];
        foreach ($lines as $l) {
            if (($l['sku_id'] ?? null) !== null) {
                $skus[] = (int) $l['sku_id'];
            }
        }
        if ($skus !== []) {
            (new ItemCompliance($db))->assertAllowed($skus, 'order', true);
        }
    }

    public function approvalUnits(Db $db, Document $doc, array $lines): int
    {
        $net = 0;
        foreach ($lines as $l) {
            $net += PoMath::e2((string) ($l['amount'] ?? '0'));
        }
        return PoMath::approvalUnits($net);
    }

    public function post(Db $db, Document $doc, array $lines, Caller $caller, string $opKey): int
    {
        $po = $db->one('SELECT * FROM purchase_order WHERE document_id = ? FOR UPDATE', [$doc->id])
            ?? throw new CwException('po_header_missing', "{$doc->label()} has no purchase order header", 422);
        $poLines = self::poLines($db, $doc->id);
        $calc = [];
        foreach ($lines as $l) {
            $pl = $poLines[(int) $l['line_no']];
            $calc[] = ['amount_e2' => PoMath::e2((string) $l['amount']), 'vat_code' => (string) $pl['vat_code'], 'rate_e2' => PoMath::e2((string) $pl['vat_rate'])];
        }
        $t = PoMath::totals($calc);
        $supplier = $db->one('SELECT * FROM supplier WHERE id = ?', [(int) $po['supplier_id']])
            ?? throw new \LogicException('the supplier vanished after validate()');
        $now = Clock::db(($this->clock)());
        $db->exec(
            "UPDATE purchase_order SET state = 'approved', net_total = ?, vat_total = ?, gross_total = ?, company_snapshot = CAST(? AS JSON), "
            . 'supplier_snapshot = CAST(? AS JSON), updated_at = ? WHERE document_id = ?',
            [PoMath::fromE2($t['net_e2']), PoMath::fromE2($t['vat_e2']), PoMath::fromE2($t['gross_e2']), Idempotency::json($this->settings->company()),
                Idempotency::json(self::supplierSnapshot($supplier)), $now, $doc->id],
        );
        // The PO price of each supplier item (history source `po`; only last_po_* moves, never the last price: I44, S4).
        $priced = [];
        foreach ($lines as $l) {
            $pl = $poLines[(int) $l['line_no']];
            if ($pl['kind'] === 'item' && $pl['supplier_item_id'] !== null) {
                $priced[(int) $pl['supplier_item_id']] = $pl; // one price per supplier item: the last line wins
            }
        }
        ksort($priced);
        if ($priced !== []) {
            // One statement for every supplier item of the order (the last line of a supplier item wins; postings of POs are
            // serialised by the PO number series, so two of them never meet here in opposite orders).
            $db->exec(
                'UPDATE supplier_item si JOIN (SELECT pl.supplier_item_id, pl.pack_price FROM po_line pl JOIN (SELECT supplier_item_id, MAX(line_no) AS ln FROM po_line '
                . "WHERE document_id = ? AND kind = 'item' AND supplier_item_id IS NOT NULL GROUP BY supplier_item_id) m "
                . '  ON m.supplier_item_id = pl.supplier_item_id AND m.ln = pl.line_no WHERE pl.document_id = ?) x ON x.supplier_item_id = si.id '
                . 'SET si.last_po_pack_price = x.pack_price, si.last_po_on = ?, si.last_po_document_id = ? WHERE si.last_po_on IS NULL OR si.last_po_on <= ?',
                [$doc->id, $doc->id, $doc->docDate, $doc->id, $doc->docDate],
            );
        }
        foreach (array_chunk($priced, 500, true) as $chunk) {
            $params = [];
            foreach ($chunk as $siId => $pl) {
                array_push($params, $siId, $pl['pack_price'], (int) $pl['units_per_pack'], PoMath::unitCost((string) $pl['pack_price'], (int) $pl['units_per_pack']),
                    $doc->number, $doc->id, $doc->docDate, $caller->staffUserId, $caller->actor, $now);
            }
            $db->exec('INSERT INTO supplier_item_price (supplier_item_id, pack_price, units_per_pack, unit_price, source, source_ref, document_id, effective_on, '
                . 'recorded_by, recorded_actor, recorded_at) VALUES ' . implode(', ', array_fill(0, count($chunk), "(?, ?, ?, ?, 'po', ?, ?, ?, ?, ?, ?)")), $params);
        }
        $after = $db->one('SELECT * FROM purchase_order WHERE document_id = ?', [$doc->id]) ?? throw new \LogicException('purchase_order vanished');
        $content = self::content($after, array_values($poLines));
        $db->exec('INSERT INTO po_posting (document_id, content_hash, content, created_at) VALUES (?, ?, ?, ?)', [$doc->id, hash('sha256', $content), $content, $now]);
        Audit::write($db, $caller, 'po.approve', 'document', (string) $doc->id, $opKey . ':po', ['number' => $doc->number, 'net' => PoMath::fromE2($t['net_e2']),
            'vat' => PoMath::fromE2($t['vat_e2']), 'gross' => PoMath::fromE2($t['gross_e2']), 'supplier' => (string) $supplier['code'], 'lines' => count($lines)]);
        return PoMath::approvalUnits($t['net_e2']);
    }

    public function reverse(Db $db, Document $original, Document $reversal, array $lines, Caller $caller, string $opKey): void
    {
        $po = $db->one('SELECT state FROM purchase_order WHERE document_id = ? FOR UPDATE', [$original->id])
            ?? throw new CwException('po_header_missing', "{$original->label()} has no purchase order header", 422);
        $received = (int) $db->value('SELECT COALESCE(SUM(received_units), 0) FROM po_line WHERE document_id = ?', [$original->id]);
        if ($received > 0) {
            throw new CwException('po_has_receipts', "{$original->label()} has goods received against it ({$received} units): it cannot be cancelled; "
                . 'close it instead so the rest is no longer expected', 409, ['received_units' => $received]);
        }
        if (in_array($po['state'], ['received', 'closed'], true)) {
            throw new CwException('po_completed', "{$original->label()} is {$po['state']}: there is nothing left to cancel", 409, ['state' => $po['state']]);
        }
        $db->exec("UPDATE purchase_order SET state = 'cancelled', updated_at = ? WHERE document_id = ?", [Clock::db(($this->clock)()), $original->id]);
        // The "last PO price" of the supplier items this order set goes back to their newest order that still stands (review
        // nit, I81): a cancelled order does not pre-fill the editor and the item panel. Module rows after purchase_order (§6.9).
        foreach ($db->column('SELECT id FROM supplier_item WHERE last_po_document_id = ? ORDER BY id FOR UPDATE', [$original->id]) as $siId) {
            $p = $db->one("SELECT p.pack_price, p.effective_on, p.document_id FROM supplier_item_price p JOIN document d ON d.id = p.document_id "
                . "WHERE p.supplier_item_id = ? AND p.source = 'po' AND d.status = 'posted' AND p.document_id <> ? ORDER BY p.effective_on DESC, p.id DESC LIMIT 1",
                [(int) $siId, $original->id]);
            $db->exec('UPDATE supplier_item SET last_po_pack_price = ?, last_po_on = ?, last_po_document_id = ? WHERE id = ?',
                [$p['pack_price'] ?? null, $p['effective_on'] ?? null, $p === null ? null : (int) $p['document_id'], (int) $siId]);
        }
        Audit::write($db, $caller, 'po.cancel', 'document', (string) $original->id, $opKey . ':po',
            ['number' => $original->number, 'cancelled_by' => $reversal->number, 'reason' => $reversal->reasonCode, 'note' => $reversal->note]);
    }

    // ------------------------------------------------------------------------------------------

    /**
     * The canonical JSON a PO's posting anchor keeps (po_posting.content): the immutable purchase_order fields and every
     * po_line field but received_units, decimals as the database returns them (spec §6.2; P2 recomputes it).
     *
     * @param array<string, mixed> $po a purchase_order row
     * @param list<array<string, mixed>> $poLines po_line rows
     */
    public static function content(array $po, array $poLines): string
    {
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $str = static fn (mixed $v): ?string => $v === null ? null : (string) $v;
        $json = static fn (mixed $v): mixed => $v === null ? null : json_decode((string) $v, true, 16, JSON_THROW_ON_ERROR);
        usort($poLines, static fn (array $a, array $b): int => (int) $a['line_no'] <=> (int) $b['line_no']);
        return Idempotency::canonicalJson([
            'purchase_order' => [
                'document_id' => (int) $po['document_id'], 'supplier_id' => (int) $po['supplier_id'], 'source' => (string) $po['source'],
                'expected_date' => $str($po['expected_date']), 'amends_document_id' => $int($po['amends_document_id']), 'currency' => (string) $po['currency'],
                'net_total' => (string) $po['net_total'], 'vat_total' => (string) $po['vat_total'], 'gross_total' => (string) $po['gross_total'],
                'company_snapshot' => $json($po['company_snapshot']), 'supplier_snapshot' => $json($po['supplier_snapshot']),
            ],
            'lines' => array_map(static fn (array $l): array => [
                'line_no' => (int) $l['line_no'], 'kind' => (string) $l['kind'], 'supplier_item_id' => $int($l['supplier_item_id']),
                'supplier_code' => $str($l['supplier_code']), 'purchase_unit' => (string) $l['purchase_unit'], 'units_per_pack' => (int) $l['units_per_pack'],
                'packs' => (int) $l['packs'], 'pack_price' => (string) $l['pack_price'], 'vat_code' => (string) $l['vat_code'], 'vat_rate' => (string) $l['vat_rate'],
                'suggested_units' => $int($l['suggested_units']),
            ], $poLines),
        ]);
    }

    /**
     * What a PO keeps of its supplier at approval (decision 9: the PDF of a posted PO prints this, not today's record).
     *
     * @param array<string, mixed> $s a supplier row
     * @return array<string, ?string>
     */
    public static function supplierSnapshot(array $s): array
    {
        $out = [];
        foreach (['code', 'name', 'legal_name', 'address_line1', 'address_line2', 'city', 'postcode', 'country', 'vat_number', 'contact_name', 'email', 'phone'] as $k) {
            $out[$k] = $s[$k] === null ? null : (string) $s[$k];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> the po_line rows of a document by line_no */
    public static function poLines(Db $db, int $documentId): array
    {
        $out = [];
        foreach ($db->all('SELECT * FROM po_line WHERE document_id = ? ORDER BY line_no', [$documentId]) as $r) {
            $out[(int) $r['line_no']] = $r;
        }
        return $out;
    }

    /**
     * A line against the PO formulas (validate(), and the save as a self-check): item: an item, qty = packs × units per
     * pack > 0, unit cost and amount from PoMath; charge: no item, no qty, amount = pack price (whole pence, > 0).
     *
     * @param array<string, mixed> $l a document_line row
     * @param array<string, mixed> $pl its po_line row
     */
    public static function checkLine(array $l, array $pl, int $no): void
    {
        $bad = static fn (string $why): CwException => new CwException('bad_po_line', "line {$no}: {$why}", 422, ['line' => $no]);
        $priceE4 = PoMath::e4((string) $pl['pack_price']);
        if ($pl['kind'] === 'charge') {
            if ($l['sku_id'] !== null || $l['qty'] !== null) {
                throw $bad('a charge line has no item and no quantity');
            }
            if ($priceE4 % 100 !== 0 || $priceE4 <= 0) {
                throw $bad('a charge is an amount of more than £0 in whole pence');
            }
            if ($l['amount'] === null || PoMath::e6((string) $l['amount']) !== $priceE4 * 100) {
                throw $bad('a charge line\'s amount is its price');
            }
            return;
        }
        if ($l['sku_id'] === null) {
            throw $bad('an item line names an item');
        }
        $qty = $l['qty'] === null ? 0 : (int) $l['qty'];
        if ($qty <= 0 || $qty !== (int) $pl['packs'] * (int) $pl['units_per_pack']) {
            throw $bad('the quantity is packs × units per pack, more than 0');
        }
        if ($l['unit_cost'] === null || PoMath::e6((string) $l['unit_cost']) !== PoMath::unitCostE6($priceE4, (int) $pl['units_per_pack'])) {
            throw $bad('the unit cost is the pack price / units per pack');
        }
        if ($l['amount'] === null || PoMath::e6((string) $l['amount']) !== PoMath::lineAmountE2((int) $pl['packs'], $priceE4) * 10_000) {
            throw $bad('the amount is packs × the pack price');
        }
    }
}
