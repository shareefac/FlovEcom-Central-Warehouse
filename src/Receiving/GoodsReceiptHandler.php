<?php

declare(strict_types=1);

namespace CW\Receiving;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\DocumentHandler;
use CW\Documents\Documents;
use CW\Documents\ReviewInvolvement;
use CW\Idempotency;
use CW\Movements;
use CW\PurchaseOrders\PoMath;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Settings;
use CW\SiteWriter\SiteModes;
use CW\Stock;

/**
 * The GRN document type (IM6 Receive (+ invoice); docs/decisions.md I125-I147): the goods receipt's posting and reversal.
 *
 *  validate()       the goods_receipt row; every line has its grn_line and follows the formulas (qty = packs x units per pack > 0,
 *                   unit cost = half-up(pack price / units per pack, 6), amount = half-up(packs x pack price, 2)); then the
 *                   receipt's plan (ReceiptPlan::build, the item cards FOR SHARE, I122): its first problem refuses (422, every
 *                   problem in detail.problems): the invoice number and its copy, an active supplier, a receivable PO, the
 *                   received time, the bench check, blocked items (ItemCompliance 'receive'), the relay route, the duty stamps
 *                   and the refusal date, one selling mode per item, the over-delivery tolerance.
 *  approvalUnits()  0 (GRN has no approval rule).
 *  post()           goods_receipt FOR UPDATE; the PO's document and rows FOR UPDATE (after the number series: Documents::reverse
 *                   reaches reverse() after it too, so every receipt path takes the series, then the PO: no cycle); the plan built
 *                   again under these locks and the selling-mode rows FOR UPDATE (a PO received meanwhile is seen); then the PO's
 *                   receipts (PurchaseOrders::applyReceipt: received units and state), what the posting decided on each grn_line,
 *                   an incident per exception, the items' selling modes (SellingModes::write, and SiteModes::fromReceipt for the
 *                   receipt sites, IM10), the write-once grn_posting anchor, audit grn.post, then the stock in ONE
 *                   Movements::bookForDocument: goods_in into MAIN (accepted), VERIFY (damaged, wrong item, over) and UNSTAMPED
 *                   (quarantined), each row at the line's unit cost (cost source document; value seq, C0), and LAST a feed row
 *                   per item whose site mode changed (Stock::skuChanged 'mode'). Returns the units booked (the review units).
 *  reverse()        the receipt's PO receipts taken back (PurchaseOrders::reverseReceipt; a closed PO: 409 po_closed), its open
 *                   incidents dismissed ("the receipt was reversed by GRN-x"), its invoice number freed; the stock is negated
 *                   generically afterwards (Movements::reverseDocument). The selling modes it set stay (the quantity goes back;
 *                   a mode is changed by the next receipt or IM10's switch).
 *  involved()       the people who did a bench check of the receipt (audit grn.bench), or set its supplier invoice without being its keyer
 *                   (grn.invoice, I172), never review it (ReviewInvolvement, I133).
 */
final class GoodsReceiptHandler implements DocumentHandler, ReviewInvolvement
{
    public const MAX_LINES = GoodsReceipts::MAX_LINES;

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
        return 'GRN';
    }

    public function validate(Db $db, Document $doc, array $lines): void
    {
        if ($db->value('SELECT 1 FROM goods_receipt WHERE document_id = ?', [$doc->id]) === null) {
            throw new CwException('grn_header_missing', "{$doc->label()} has no goods receipt header", 422);
        }
        if (count($lines) > self::MAX_LINES) {
            throw new CwException('too_many_lines', 'a receipt has at most ' . self::MAX_LINES . ' lines', 422);
        }
        $grn = self::grnLines($db, $doc->id);
        foreach ($lines as $l) {
            $no = (int) $l['line_no'];
            self::checkLine($l, $grn[$no] ?? throw new CwException('grn_line_missing', "line {$no} has no receipt details: save the receipt again", 422, ['line' => $no]), $no);
        }
        if (count($grn) !== count($lines)) {
            throw new CwException('grn_line_missing', 'the receipt details do not match its lines: save the receipt again', 422);
        }
        self::refuse(ReceiptPlan::build($db, $doc->id, $this->now(), true, false, $this->settings));
    }

    public function approvalUnits(Db $db, Document $doc, array $lines): int
    {
        return 0;
    }

    public function post(Db $db, Document $doc, array $lines, Caller $caller, string $opKey): int
    {
        $gr = $db->one('SELECT * FROM goods_receipt WHERE document_id = ? FOR UPDATE', [$doc->id])
            ?? throw new CwException('grn_header_missing', "{$doc->label()} has no goods receipt header", 422);
        $poId = $gr['po_document_id'] === null ? null : (int) $gr['po_document_id'];
        if ($poId !== null) {
            // The PO's rows under lock before the plan reads its receipts (the tolerance is checked against what it holds now).
            $db->one('SELECT id FROM document WHERE id = ? FOR UPDATE', [$poId]);
            $db->one('SELECT document_id FROM purchase_order WHERE document_id = ? FOR UPDATE', [$poId]);
            $db->all('SELECT line_no FROM po_line WHERE document_id = ? ORDER BY line_no FOR UPDATE', [$poId]);
        }
        $plan = ReceiptPlan::build($db, $doc->id, $this->now(), true, true, $this->settings);
        self::refuse($plan);
        $now = Clock::db($this->now());

        // 1. The purchase order's receipts (its rows first: PO module rows, then this receipt's).
        $byPoLine = [];
        foreach ($plan['lines'] as $l) {
            if ($poId !== null && $l['po_line_no'] !== null && $l['split']['po_units'] > 0) {
                $byPoLine[(int) $l['po_line_no']] = ($byPoLine[(int) $l['po_line_no']] ?? 0) + $l['split']['po_units'];
            }
        }
        if ($byPoLine !== []) {
            ksort($byPoLine);
            (new PurchaseOrders($db, new Documents($db, [])))->applyReceipt($poId, $byPoLine, (string) $doc->number, $caller);
        }

        // 2. What the posting decided, on each line.
        foreach ($plan['lines'] as $no => $l) {
            $s = $l['split'];
            $db->exec('UPDATE grn_line SET stamp_required = ?, duty_ml = ?, expected_duty = ?, selling_mode = ?, mode_source = ?, accepted_units = ?, verify_units = ?, '
                . 'quarantine_units = ?, refused_units = ?, po_units = ? WHERE document_id = ? AND line_no = ?',
                [$l['stamp_required'] ? 1 : 0, $l['duty_pence_unit'] === null ? null : $l['card']['liquid_ml'],
                    $l['expected_duty_pence'] === null ? null : ReceiptMath::decimal($l['expected_duty_pence']), $l['mode']['mode'], $l['mode']['source'],
                    $s['accepted'], $s['verify'], $s['quarantine'], $s['refused'], $s['po_units'], $doc->id, $no]);
        }

        // 3. An incident per exception (FKs: written before the stock locks, I21).
        $wh = [];
        foreach ($db->all("SELECT id, code FROM warehouse WHERE code IN ('MAIN', 'VERIFY', 'UNSTAMPED')") as $w) {
            $wh[(string) $w['code']] = (int) $w['id'];
        }
        $incidents = 0;
        foreach ($plan['lines'] as $no => $l) {
            // An unstamped delivery's damaged and over units follow its unstamped units (ReceiptMath::split, I167).
            $extra = match ($l['split']['extras']) {
                'quarantine' => ['quarantine', $wh['UNSTAMPED']],
                'refuse' => ['refused', null],
                default => ['verify', $wh['VERIFY']],
            };
            $kinds = [
                'short' => [(int) $l['short_units'], 'not_received', null],
                'over' => [(int) $l['over_units'], ...$extra],
                'damaged' => [(int) $l['damaged_units'], ...$extra],
                'wrong_item' => [(int) $l['wrong_item_units'], 'verify', $wh['VERIFY']],
            ];
            if ((int) $l['unstamped_units'] > 0 && $l['unstamped_action'] !== 'accept_pre_october') {
                $kinds['unstamped'] = $l['unstamped_action'] === 'quarantine' ? [(int) $l['unstamped_units'], 'quarantine', $wh['UNSTAMPED']]
                    : [(int) $l['unstamped_units'], 'refused', null];
            }
            foreach ($kinds as $kind => [$units, $disposition, $warehouseId]) {
                if ($units <= 0) {
                    continue;
                }
                $db->exec('INSERT INTO incident (source, kind, disposition, document_id, line_no, sku_id, supplier_id, warehouse_id, units, detail, opened_by, opened_actor, '
                    . "opened_at, dedupe_key) VALUES ('goods_receipt', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$kind, $disposition, $doc->id, $no, (int) $l['sku_id'], (int) $gr['supplier_id'], $warehouseId, $units,
                        Idempotency::json(['grn' => $doc->number, 'sku_code' => $l['sku_code'], 'units_on_paperwork' => $l['units'], 'supplier_invoice' => $doc->externalRef]
                            + ($kind === 'unstamped' ? ['action' => $l['unstamped_action'], 'stamp_on_pack' => $l['stamp_on_pack'] === null ? null : (int) $l['stamp_on_pack']] : [])
                            + (in_array($kind, ['over', 'damaged'], true) && $l['split']['extras'] !== null ? ['unstamped' => true, 'stamp_on_pack' => $l['stamp_on_pack'] === null
                                ? null : (int) $l['stamp_on_pack']] : [])),
                        $caller->staffUserId, $caller->actor, $now, "grn:{$doc->id}:{$no}:{$kind}"]);
                $incidents++;
            }
        }

        // 4. The items' selling modes (rows read FOR UPDATE by the plan), one write per item, for the site stock writer (IM10): only
        //    the items the receipt accepts something of into MAIN; an item it accepts nothing of keeps its mode (I169), so a refused,
        //    short or quarantined delivery never puts an item back on sale (nor fires a back-in-stock e-mail).
        $writes = [];
        foreach ($plan['modes'] as $sku => $m) {
            if ($m['source'] !== 'kept') {
                $writes[(int) $sku] = ['mode' => $m['mode'], 'source' => $m['source'], 'line_no' => (int) $m['line_no'], 'current' => $m['current']];
            }
        }
        (new SellingModes($db))->write($caller, $doc->id, $writes, $now);
        // ... and the item's mode on the receipt sites (IM10, I136: Vape and Go until the owner says otherwise), for the site writer.
        $siteModesChanged = (new SiteModes($db))->fromReceipt($caller, $doc->id, array_map(static fn (array $w): array => ['mode' => $w['mode'], 'line_no' => $w['line_no']],
            $writes), $this->settings, $now);

        // 5. The write-once anchor of the module content as posted (G3).
        $content = self::content($db, $doc->id);
        $db->exec('INSERT INTO grn_posting (document_id, content_hash, content, created_at) VALUES (?, ?, ?, ?)', [$doc->id, hash('sha256', $content), $content, $now]);
        $t = $plan['totals'];
        Audit::write($db, $caller, 'grn.post', 'document', (string) $doc->id, $opKey . ':grn', ['number' => $doc->number, 'supplier' => $plan['header']['supplier']['code'] ?? null,
            'invoice' => $doc->externalRef, 'po' => $plan['po']['number'] ?? null, 'lines' => count($plan['lines']), 'units' => $t['units'], 'accepted' => $t['accepted'],
            'verify' => $t['verify'], 'quarantine' => $t['quarantine'], 'refused' => $t['refused'], 'short' => $t['short'], 'incidents' => $incidents,
            'expected_duty' => ReceiptMath::decimal($t['expected_duty_pence']), 'received_at' => $gr['received_at']]);

        // 6. LAST: the stock, in one bookForDocument (one lock(), one flush(): I7).
        $moves = [];
        foreach (['MAIN' => 'accepted', 'VERIFY' => 'verify', 'UNSTAMPED' => 'quarantine'] as $code => $part) {
            $mvLines = [];
            foreach ($plan['lines'] as $no => $l) {
                $q = $l['split'][$part];
                if ($q > 0) {
                    $mvLines[] = ['document_line' => $no, 'sku_id' => (int) $l['sku_id'], 'qty' => $q, 'unit_cost' => (string) $l['unit_cost']];
                }
            }
            if ($mvLines !== []) {
                $moves[] = ['type' => 'goods_in', 'warehouse' => $code, 'note' => $code === 'MAIN' ? null : ($code === 'VERIFY' ? 'receipt exceptions' : 'unstamped, quarantined'),
                    'lines' => $mvLines];
            }
        }
        if ($moves !== []) {
            (new Movements($db, null, $this->clock))->bookForDocument($caller, ['document_id' => $doc->id, 'doc_ref' => (string) $doc->number], $opKey, $moves);
        }
        // 7. After the stock (its flush took the feed clock, or this takes it: nothing is locked after it, D39): a feed row for each item
        //    whose selling mode changed on a receipt site, so the site writer sends the mode even when no stock moved (IM10).
        if ($siteModesChanged !== []) {
            $stock = new Stock($db);
            foreach ($siteModesChanged as $sku) {
                $stock->skuChanged($sku, 'mode');
            }
        }
        return $t['accepted'] + $t['verify'] + $t['quarantine'];
    }

    public function reverse(Db $db, Document $original, Document $reversal, array $lines, Caller $caller, string $opKey): void
    {
        $gr = $db->one('SELECT * FROM goods_receipt WHERE document_id = ? FOR UPDATE', [$original->id])
            ?? throw new CwException('grn_header_missing', "{$original->label()} has no goods receipt header", 422);
        $back = [];
        foreach ($db->all('SELECT po_line_no, SUM(po_units) AS units FROM grn_line WHERE document_id = ? AND po_line_no IS NOT NULL AND po_units > 0 GROUP BY po_line_no '
            . 'ORDER BY po_line_no', [$original->id]) as $r) {
            $back[(int) $r['po_line_no']] = (int) $r['units'];
        }
        if ($back !== [] && $gr['po_document_id'] !== null) {
            $poId = (int) $gr['po_document_id'];
            $state = $db->value('SELECT p.state FROM document d JOIN purchase_order p ON p.document_id = d.id WHERE d.id = ? FOR UPDATE', [$poId]);
            if ($state === 'closed') {
                $number = (string) $db->value('SELECT number FROM document WHERE id = ?', [$poId]);
                throw new CwException('po_closed', "{$number} was closed after this delivery: a receipt against a closed order is not reversed here (its receipts would "
                    . 'reopen it). Correct the stock with an adjustment (Phase I-4), or ask an engineer.', 409);
            }
            (new PurchaseOrders($db, new Documents($db, [])))->reverseReceipt($poId, $back, (string) $reversal->number, $caller);
        }
        $now = Clock::db($this->now());
        $dismissed = $db->exec("UPDATE incident SET status = 'dismissed', resolution = ?, resolved_by = ?, resolved_actor = ?, resolved_at = ? WHERE document_id = ? AND status = 'open'",
            ["the receipt was reversed by {$reversal->number}", $caller->staffUserId, $caller->actor, $now, $original->id]);
        $db->exec('UPDATE goods_receipt SET invoice_key = NULL, updated_at = ? WHERE document_id = ?', [$now, $original->id]);
        Audit::write($db, $caller, 'grn.reverse', 'document', (string) $original->id, $opKey . ':grn', ['number' => $original->number, 'reversed_by' => $reversal->number,
            'reason' => $reversal->reasonCode, 'po_units_back' => $back, 'incidents_dismissed' => $dismissed]);
    }

    public function involved(Db $db, Document $doc): array
    {
        $out = [];
        foreach ($db->all("SELECT staff_user_id, MIN(action) AS action FROM audit_log WHERE entity_type = 'document' AND entity_id = ? AND action IN "
            . self::INVOLVED_SQL_LIST . ' AND staff_user_id IS NOT NULL GROUP BY staff_user_id', [(string) ($doc->reversesId ?? $doc->id)]) as $r) {
            $out[(int) $r['staff_user_id']] = $r['action'] === 'grn.bench' ? 'You checked this delivery at the goods-in bench: another reviewer must review it.'
                : 'You set this receipt\'s supplier invoice: another reviewer must review it.';
        }
        return $out;
    }

    /** The audit actions that make a person part of a receipt (I133; the invoice set by another person than its keyer, I172). */
    public const INVOLVED_ACTIONS = ['grn.bench', 'grn.invoice'];
    private const INVOLVED_SQL_LIST = "('grn.bench', 'grn.invoice')";

    public function involvedSql(): string
    {
        return "EXISTS (SELECT 1 FROM audit_log a WHERE a.entity_type = 'document' AND a.entity_id = CAST(COALESCE(d.reverses_id, d.id) AS CHAR) "
            . 'AND a.action IN ' . self::INVOLVED_SQL_LIST . ' AND a.staff_user_id = ?)';
    }

    // ------------------------------------------------------------------------------------------

    /**
     * The canonical JSON a posted receipt's anchor keeps (grn_posting.content): goods_receipt (all but invoice_key, freed on a
     * reversal, and the row's own timestamps) and every grn_line field, decimals as the database returns them (G3 recomputes it).
     */
    public static function content(Db $db, int $documentId): string
    {
        $gr = $db->one('SELECT * FROM goods_receipt WHERE document_id = ?', [$documentId]) ?? throw new \LogicException("no goods_receipt {$documentId}");
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $str = static fn (mixed $v): ?string => $v === null ? null : (string) $v;
        $lines = [];
        foreach (self::grnLines($db, $documentId) as $l) {
            $row = [];
            foreach (self::LINE_INTS as $k) {
                $row[$k] = $int($l[$k]);
            }
            foreach (self::LINE_STRINGS as $k) {
                $row[$k] = $str($l[$k]);
            }
            ksort($row);
            $lines[] = $row;
        }
        return Idempotency::canonicalJson([
            'goods_receipt' => [
                'document_id' => (int) $gr['document_id'], 'supplier_id' => (int) $gr['supplier_id'], 'po_document_id' => $int($gr['po_document_id']),
                'invoice_date' => $str($gr['invoice_date']), 'delivery_note' => $str($gr['delivery_note']), 'received_at' => (string) $gr['received_at'],
                'paper_sheet' => (int) $gr['paper_sheet'], 'backdate_reason' => $str($gr['backdate_reason']), 'paperwork_ok' => $int($gr['paperwork_ok']),
                'bench_note' => $str($gr['bench_note']), 'checked_by' => $int($gr['checked_by']), 'checked_actor' => $str($gr['checked_actor']),
                'checked_at' => $str($gr['checked_at']),
            ],
            'lines' => $lines,
        ]);
    }

    private const LINE_INTS = ['line_no', 'supplier_item_id', 'units_per_pack', 'packs', 'po_line_no', 'stamp_on_pack', 'short_units', 'over_units', 'damaged_units',
        'wrong_item_units', 'unstamped_units', 'stamp_required', 'accepted_units', 'verify_units', 'quarantine_units', 'refused_units', 'po_units'];
    private const LINE_STRINGS = ['supplier_code', 'purchase_unit', 'pack_price', 'entry', 'mode_choice', 'checked_at', 'stamp_type', 'stamp_code', 'unstamped_action',
        'pre_october_evidence', 'duty_ml', 'expected_duty', 'selling_mode', 'mode_source'];

    /** @return array<int, array<string, mixed>> the grn_line rows of a document by line_no */
    public static function grnLines(Db $db, int $documentId): array
    {
        $out = [];
        foreach ($db->all('SELECT * FROM grn_line WHERE document_id = ? ORDER BY line_no', [$documentId]) as $r) {
            $out[(int) $r['line_no']] = $r;
        }
        return $out;
    }

    /**
     * A line against the receipt formulas (validate()): an item; qty = packs x units per pack > 0; unit cost and amount from PoMath.
     *
     * @param array<string, mixed> $l a document_line row
     * @param array<string, mixed> $g its grn_line row
     */
    public static function checkLine(array $l, array $g, int $no): void
    {
        $bad = static fn (string $why): CwException => new CwException('bad_grn_line', "line {$no}: {$why}", 422, ['line' => $no]);
        if ($l['sku_id'] === null) {
            throw $bad('a receipt line names an item');
        }
        $qty = $l['qty'] === null ? 0 : (int) $l['qty'];
        if ($qty <= 0 || $qty !== (int) $g['packs'] * (int) $g['units_per_pack']) {
            throw $bad('the quantity is packs x units per pack, more than 0');
        }
        $priceE4 = PoMath::e4((string) $g['pack_price']);
        if ($l['unit_cost'] === null || PoMath::e6((string) $l['unit_cost']) !== PoMath::unitCostE6($priceE4, (int) $g['units_per_pack'])) {
            throw $bad('the unit cost is the pack price / units per pack');
        }
        if ($l['amount'] === null || PoMath::e6((string) $l['amount']) !== PoMath::lineAmountE2((int) $g['packs'], $priceE4) * 10_000) {
            throw $bad('the amount is packs x the pack price');
        }
    }

    /** Refuses a plan with problems: the first one's code and message, every problem in detail.problems. @param array<string, mixed> $plan */
    public static function refuse(array $plan): void
    {
        if ($plan['problems'] === []) {
            return;
        }
        $first = $plan['problems'][0];
        $more = count($plan['problems']) - 1;
        throw new CwException($first['code'], $first['message'] . ($more > 0 ? " (and {$more} more problem" . ($more === 1 ? '' : 's') . ' to fix before posting)' : ''),
            422, ['problems' => $plan['problems']]);
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock)()->setTimezone(Clock::utc());
    }
}
