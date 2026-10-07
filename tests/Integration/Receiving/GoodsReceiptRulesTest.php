<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Receiving;

use CW\Caller;
use CW\Receiving\ReceivingInvariants;

/**
 * The receipt's rules (IM6; I127-I141): the supplier invoice number (compulsory, one live receipt per supplier and number, written
 * any way), its copy (a PDF or a photo), an active supplier, blocked items, one route per item (the ERPNext relay), when the goods
 * arrived (a paper sheet keyed later), the over-delivery tolerance, the selling mode on receipt, the bench's figures, who may do
 * what, and the review (never by the person who checked the delivery at the bench).
 */
final class GoodsReceiptRulesTest extends ReceivingTestCase
{
    public function testTheSupplierInvoiceNumberIsCompulsoryAndBookedOnce(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people();
        $other = $this->activeSupplier($buyer, ['name' => 'Other Supplier']);
        $sku = self::makeSku('Invoice rule item');
        $this->dry($sku);
        $d = $this->grns->createDraft($desk, (int) $s['id']);
        $d = $this->grns->saveDraft($desk, $d->id, $d->version, [], [['sku_id' => $sku, 'packs' => 1]]);
        $this->ready($desk, $d->id);
        $e = $this->refusedCode('invoice_number_required', fn () => $this->post($desk, $d->id));
        self::assertSame('invoice_number_required', $e->detail['problems'][0]['code']);
        $d = $this->grns->saveDraft($desk, $d->id, $this->docs->get($d->id)->version, ['invoice_number' => ' inv  001 '], $this->grns->lines($d->id));
        self::assertSame(['inv  001', 'INV001'], [$this->docs->get($d->id)->externalRef, self::$db->value('SELECT invoice_key FROM goods_receipt WHERE document_id = ?', [$d->id])]);

        // The same invoice of the same supplier, however it is written: refused on a second receipt (draft or posted).
        $e = $this->refusedCode('duplicate_invoice', fn () => $this->grns->createDraft($this->staffUser('purchasing_desk'), (int) $s['id'], ['invoice_number' => 'INV 001']));
        self::assertStringContainsString("this supplier's invoice INV001 is already on draft receipt #{$d->id}", $e->getMessage());
        $second = $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'INV-002']);
        $this->refusedCode('duplicate_invoice', fn () => $this->grns->saveDraft($desk, $second->id, $second->version, ['invoice_number' => 'inv001'], []));
        // Another supplier's invoice 001 is another invoice.
        $this->grns->createDraft($desk, (int) $other['id'], ['invoice_number' => 'INV001']);
        $p = $this->post($desk, $d->id);
        self::assertSame('posted', $p->status);
        $this->refusedCode('duplicate_invoice', fn () => $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'INV001']));
        // A cancelled draft frees its number.
        $this->grns->cancel($desk, $second->id, $second->version, 'keyed twice');
        self::assertNull(self::$db->value('SELECT invoice_key FROM goods_receipt WHERE document_id = ?', [$second->id]));
        $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'INV-002']);
        self::assertSame([], ReceivingInvariants::check(self::$db));
    }

    public function testTheInvoiceCopyIsAPdfOrAPhoto(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Copy rule item');
        $this->dry($sku);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 2]]);
        $csv = $this->file("code,qty\r\nA,1\r\n", 'invoice.csv');
        $this->refusedCode('type_not_allowed', fn () => $this->grns->attach($desk, $d->id, $csv, 'invoice.csv', 'supplier_invoice'));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM document_file WHERE document_id = ?', [$d->id]), 'nothing attached');
        $png = $this->file((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true), 'photo.png');
        $this->grns->attach($desk, $d->id, $png, 'photo.png', 'photo');
        $this->bench($this->staffUser('goods_in'), $d->id);
        $this->refusedCode('invoice_file_required', fn () => $this->post($desk, $d->id), 'a photo of the goods is not the invoice');
        $r = $this->grns->attach($desk, $d->id, $png, 'paper-invoice.png', 'supplier_invoice');
        self::assertTrue($r['attached'] && $r['deduped'], 'the same photo, now as the invoice copy');
        self::assertSame('posted', $this->post($desk, $d->id)->status);
        $this->refusedCode('bad_file_role', fn () => $this->grns->attach($desk, $d->id, $png, 'x.png', 'generated_pdf'));
    }

    public function testTheSupplierMustBeActiveAndABlockedItemIsRefused(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people();
        $draftSupplier = $this->draft($buyer, ['name' => 'Not Yet Approved Ltd']);
        $sku = self::makeSku('Blocked liquid 12ml');
        $this->card($sku, ['product_type' => 'e-liquid', 'liquid_ml' => '12', 'nicotine_mg' => '6', 'duty_liable' => 'yes']);
        $d = $this->receipt($desk, (int) $draftSupplier['id'], [['sku_id' => $sku, 'packs' => 1]]);
        $this->ready($desk, $d->id);
        $e = $this->refusedCode('supplier_not_active', fn () => $this->post($desk, $d->id));
        self::assertStringContainsString("Supplier {$draftSupplier['code']} is draft: goods are received only from an active supplier", $e->getMessage());
        // A rule the card breaks only warns until a person confirms it ...
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1]]);
        $this->ready($desk, $d->id);
        $plan = $this->grns->plan($d->id);
        self::assertSame([], $plan['problems']);
        self::assertStringContainsString('nicotine refill over 10 ml', implode(' ', $plan['warnings']));
        // ... and blocks once confirmed (with the acknowledgement): the delivery cannot be received.
        $this->card($sku, [], true, true);
        $e = $this->refusedCode('item_blocked', fn () => $this->post($desk, $d->id));
        self::assertStringContainsString('is blocked by its item card (nicotine refill over 10 ml), so it cannot be received', $e->getMessage());
        self::assertSame('draft', $this->docs->get($d->id)->status);
    }

    /** Plan §9: an item whose goods-in comes from a site's ERPNext relay is not received in CW too (one route per item). */
    public function testOneRoutePerItemTheRelayItemsAreRefused(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Relay item');
        $this->dry($sku);
        $site = $this->site('vpg', 'shadow');
        $this->listing($site, 'V-RELAY', $sku);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1]]);
        $this->ready($desk, $d->id);
        self::assertSame([], $this->grns->plan($d->id)['problems'], 'the relay is not granted: CW is the route');
        $this->grant($site, 'goods_in');
        $e = $this->refusedCode('relay_route', fn () => $this->post($desk, $d->id));
        self::assertStringContainsString('gets its goods-in from the ERPNext relay of vpg', $e->getMessage());
    }

    /** A delivery keyed later from a paper receiving sheet (CW was down): the real received time, a reason, at most N days back. */
    public function testAPaperSheetKeyedLaterWithTheRealReceivedTime(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Paper sheet item');
        $this->dry($sku);
        $uk = new \DateTimeZone('Europe/London');
        $threeDays = (new \DateTimeImmutable('now', $uk))->modify('-3 days')->format('Y-m-d') . ' 09:15';
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 4]], ['received_at' => $threeDays]);
        self::assertSame((new \DateTimeImmutable($threeDays, $uk))->format('Y-m-d'), $this->docs->get($d->id)->docDate, 'the document is dated the day the goods arrived');
        $this->ready($desk, $d->id);
        $this->refusedCode('backdate_reason_required', fn () => $this->post($desk, $d->id));
        $this->refusedCode('backdate_reason_required', fn () => $this->grns->saveDraft($desk, $d->id, $this->docs->get($d->id)->version, ['paper_sheet' => '1'],
            $this->grns->lines($d->id)), 'a paper sheet says why');
        $this->grns->saveDraft($desk, $d->id, $this->docs->get($d->id)->version, ['paper_sheet' => '1', 'backdate_reason' => 'CW down on Monday: paper sheet 14'],
            $this->grns->lines($d->id));
        $this->bench($this->staffUser('goods_in'), $d->id);
        self::assertSame('posted', $this->post($desk, $d->id)->status);
        self::assertSame([1, 'CW down on Monday: paper sheet 14'], array_values((array) self::$db->one('SELECT paper_sheet, backdate_reason FROM goods_receipt WHERE document_id = ?', [$d->id])));
        // Too far back, or in the future.
        $old = (new \DateTimeImmutable('now', $uk))->modify('-40 days')->format('Y-m-d') . ' 10:00';
        $d2 = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1]], ['received_at' => $old, 'backdate_reason' => 'found the sheet']);
        $this->ready($desk, $d2->id);
        $this->refusedCode('received_too_early', fn () => $this->post($desk, $d2->id));
        $this->refusedCode('bad_field', fn () => $this->grns->createDraft($desk, (int) $s['id'], ['received_at' => (new \DateTimeImmutable('now', $uk))->modify('+3 days')->format('Y-m-d H:i')]));
        $this->refusedCode('bad_field', fn () => $this->grns->createDraft($desk, (int) $s['id'], ['received_at' => 'yesterday']));
    }

    /** The over-delivery tolerance of a PO line (po.over_delivery_tolerance_pct, 10%) over every line of the receipt on it. */
    public function testTheOverDeliveryTolerance(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Tolerance item');
        $this->dry($sku);
        $si = $this->supplierItem($buyer, (int) $s['id'], $sku, 1, '1.0000', ['supplier_code' => 'TOL']);
        $po = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 20]]);
        $po = $this->pos->approve($buyer, $po->id, $po->version);
        $d = $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'TOL-1'], $po->id);
        $d = $this->grns->saveDraft($desk, $d->id, $d->version, [], [['supplier_item_id' => (int) $si['id'], 'packs' => 12], ['supplier_item_id' => (int) $si['id'], 'packs' => 11]]);
        self::assertSame([1, 1], array_column($this->grns->lines($d->id), 'po_line_no'), 'both lines received against the order\'s line of the item');
        $this->ready($desk, $d->id);
        $e = $this->refusedCode('over_tolerance', fn () => $this->post($desk, $d->id));
        self::assertStringContainsString('23 units of ' . $po->number . ' line 1 would be received against 20 ordered (0 before this receipt): more than the 10%', $e->getMessage());
        // 22 is within 10% (a warning); a line not against the order is not counted.
        $lines = $this->grns->lines($d->id);
        $lines[1]['po_line_no'] = 0;
        $lines[1]['packs'] = 3;
        $lines[0]['packs'] = 22;
        $this->grns->saveDraft($desk, $d->id, $this->docs->get($d->id)->version, [], $lines);
        $this->bench($this->staffUser('goods_in'), $d->id);
        $plan = $this->grns->plan($d->id);
        self::assertSame([], $plan['problems']);
        self::assertContains("{$po->number} line 1: 22 units received against 20 ordered (within the 10% tolerance).", $plan['warnings']);
        $this->post($desk, $d->id);
        self::assertSame([22, 'received', 25], [(int) self::$db->value('SELECT received_units FROM po_line WHERE document_id = ?', [$po->id]), $this->poRow($po->id)['state'],
            $this->onHand($sku)]);
        // The order received: a further receipt against it is refused.
        $this->refusedCode('po_not_receivable', fn () => $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'TOL-2'], $po->id));
    }

    /** The selling mode on receipt: the last mode; an Out-Of-Stock item back to its previous one; a fallback; a choice; one per item. */
    public function testTheSellingModeOnReceipt(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        [$a, $b, $c, $x] = [self::makeSku('Mode A'), self::makeSku('Mode B'), self::makeSku('Mode C'), self::makeSku('Mode X')];
        foreach ([$a, $b, $c, $x] as $sku) {
            $this->dry($sku);
        }
        // a: the sites' last snapshot says In-Stock (no CW mode yet); b: CW says Out-Of-Stock, before that From-Warehouse; c: nothing known.
        $site = $this->site('vpg', 'shadow');
        $listing = $this->listing($site, 'V-A', $a);
        $batch = self::$db->insert("INSERT INTO sales_import_batch (channel_id, source, date_from, date_to, sales_file, sales_sha256, manifest, exported_at, status, actor) "
            . "VALUES (?, 'cps', '2026-09-01', '2026-09-30', 'f', REPEAT('a', 64), '{}', NOW(), 'loaded', 'system:test')", [$site->channelId]);
        self::$db->exec("INSERT INTO listing_stock_latest (channel_id, external_variant_id, snapshot_date, stock, stock_mode, sellable, batch_id) VALUES (?, 'V-A', '2026-09-30', 5, 'In-Stock', 1, ?)",
            [$site->channelId, $batch]);
        self::assertGreaterThan(0, $listing);
        foreach (['From-Warehouse', 'Out-Of-Stock'] as $choice) {
            $d1 = $this->receipt($desk, (int) $s['id'], [['sku_id' => $b, 'packs' => 1, 'mode_choice' => $choice]]);
            $this->ready($desk, $d1->id);
            $this->post($desk, $d1->id);
        }
        self::assertSame(['Out-Of-Stock', 'From-Warehouse', 2], array_values((array) self::$db->one('SELECT mode, previous_mode, version FROM item_selling_mode WHERE sku_id = ?', [$b])),
            'an item going Out-Of-Stock remembers its mode before');

        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $a, 'packs' => 1], ['sku_id' => $b, 'packs' => 1], ['sku_id' => $c, 'packs' => 1],
            ['sku_id' => $x, 'packs' => 1, 'mode_choice' => 'Out-Of-Stock']]);
        $plan = $this->grns->plan($d->id);
        self::assertSame([['In-Stock', 'last'], ['From-Warehouse', 'previous'], ['From-Warehouse', 'fallback'], ['Out-Of-Stock', 'chosen']],
            array_map(static fn (array $l): array => [$l['mode']['mode'], $l['mode']['source']], array_values($plan['lines'])));
        self::assertContains(self::$db->value('SELECT code FROM sku WHERE id = ?', [$b]) . ': selling mode Out-Of-Stock → From-Warehouse (its mode before it went Out-Of-Stock).',
            $plan['warnings']);
        // Two lines of one item asking for two modes: refused.
        $lines = $this->grns->lines($d->id);
        $lines[] = ['sku_id' => $a, 'packs' => 2, 'mode_choice' => 'From-Warehouse', 'units_per_pack' => 2];
        $this->grns->saveDraft($desk, $d->id, $this->docs->get($d->id)->version, [], $lines);
        $this->ready($desk, $d->id);
        $this->refusedCode('mode_conflict', fn () => $this->post($desk, $d->id));
        $lines = $this->grns->lines($d->id);
        array_pop($lines);
        $this->grns->saveDraft($desk, $d->id, $this->docs->get($d->id)->version, [], $lines);
        $this->bench($this->staffUser('goods_in'), $d->id);
        $this->post($desk, $d->id);
        $modes = [];
        foreach (self::$db->all('SELECT sku_id, mode, previous_mode, version FROM item_selling_mode ORDER BY sku_id') as $r) {
            $modes[(int) $r['sku_id']] = [$r['mode'], $r['previous_mode'], (int) $r['version']];
        }
        self::assertSame([$a => ['In-Stock', null, 1], $b => ['From-Warehouse', null, 3], $c => ['From-Warehouse', null, 1], $x => ['Out-Of-Stock', null, 1]], $modes,
            'x had no mode before it went Out-Of-Stock: none to go back to');
        self::assertSame(['In-Stock', 'last', 'receipt'], array_values((array) self::$db->one('SELECT mode_before, mode_source, reason FROM item_selling_mode_log WHERE sku_id = ?', [$a])));
    }

    public function testTheBenchFiguresAreChecked(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Bench figures liquid');
        $this->liquid($sku);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1, 'units_per_pack' => 10]]);
        $bench = $this->staffUser('goods_in');
        $e = $this->refusedCode('bad_bench', fn () => $this->bench($bench, $d->id, [1 => ['short_units' => '4', 'damaged_units' => '4', 'unstamped_units' => '3',
            'unstamped_action' => 'refuse']]));
        self::assertStringContainsString('short, damaged, wrong item and unstamped are 11 units together, but the line has only 10', $e->getMessage());
        $this->refusedCode('bad_bench', fn () => $this->bench($bench, $d->id, [1 => ['unstamped_units' => '2']]), 'what happens to them');
        $this->refusedCode('bad_bench', fn () => $this->bench($bench, $d->id, [1 => ['unstamped_units' => '2', 'unstamped_action' => 'accept_pre_october',
            'pre_october_evidence' => 'yes']]), 'the evidence, in 10 characters at least');
        $this->refusedCode('bad_bench', fn () => $this->bench($bench, $d->id, [1 => ['stamp_on_pack' => 'maybe']]));
        $this->refusedCode('bad_line', fn () => $this->bench($bench, $d->id, [7 => ['short_units' => '1']]));
        $e = $this->refusedCode('bad_bench', fn () => $this->bench($bench, $d->id, [1 => ['stamp_on_pack' => '0', 'unstamped_units' => '4', 'unstamped_action' => 'refuse']]));
        self::assertStringContainsString('"no stamp on the pack" means all 10 units that arrived are unstamped', $e->getMessage());
        $e = $this->refusedCode('bad_bench', fn () => $this->bench($bench, $d->id, [1 => ['short_units' => 'x']]));
        self::assertSame('Line 1, short: a whole number from 0 to 10,000,000', $e->getMessage(), 'the screen\'s words, not the column names');
        // The bench's findings survive the desk's next save while the quantity stays; a changed quantity starts the line again (I168).
        $this->bench($bench, $d->id, [1 => ['damaged_units' => '3']]);
        $lines = $this->grns->lines($d->id);
        self::assertSame(3, $lines[0]['damaged_units']);
        $lines[0]['description'] = 'one box crushed';
        $this->grns->saveDraft($desk, $d->id, $this->docs->get($d->id)->version, [], $lines);
        self::assertSame([3, 'one box crushed'], [$this->grns->lines($d->id)[0]['damaged_units'], $this->grns->lines($d->id)[0]['description']]);
        $lines = $this->grns->lines($d->id);
        $lines[0]['units_per_pack'] = 2;
        $this->grns->saveDraft($desk, $d->id, $this->docs->get($d->id)->version, [], $lines);
        self::assertSame([0, null, 2], [$this->grns->lines($d->id)[0]['damaged_units'], $this->grns->lines($d->id)[0]['checked_at'], $this->grns->lines($d->id)[0]['units_per_pack']],
            'the bench counts the changed line again');
    }

    /**
     * The bench check covers what is posted (review 7 Oct, probes B and G; I168): a line added, or whose quantity changed, after the
     * check starts unchecked and posting waits for the bench; the desk's save never carries bench findings of its own.
     */
    public function testTheBenchCheckCoversWhatIsPosted(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Recount liquid');
        $this->liquid($sku);
        $coil = self::makeSku('Recount coil');
        $this->dry($coil);
        $bench = $this->staffUser('goods_in');
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1, 'units_per_pack' => 10, 'pack_price' => '20.00']]);
        $this->ready($desk, $d->id);
        $lines = $this->grns->lines($d->id);
        self::assertNotNull($lines[0]['checked_at']);
        // B: the desk raises the packs to 10 after the check: the line starts again, posting waits for the bench.
        $lines[0]['packs'] = 10;
        $this->grns->saveDraft($desk, $d->id, $this->docs->get($d->id)->version, [], $lines);
        $l = $this->grns->lines($d->id)[0];
        self::assertSame([null, null, null], [$l['checked_at'], $l['stamp_on_pack'], $l['stamp_type']]);
        $e = $this->refusedCode('line_not_checked', fn () => $this->post($desk, $d->id));
        self::assertStringContainsString('Waiting for the goods-in bench: line 1 not counted yet', $e->getMessage());
        // A change the bench does not count (a note, the price) keeps the check; findings the desk sends itself are ignored.
        $this->bench($bench, $d->id);
        $lines = $this->grns->lines($d->id);
        $checked = $lines[0]['checked_at'];
        $lines[0]['description'] = 'price per the invoice';
        $lines[0]['pack_price'] = '19.50';
        $lines[0]['damaged_units'] = 7;
        $lines[0]['checked_at'] = '2020-01-01 00:00:00';
        $this->grns->saveDraft($desk, $d->id, $this->docs->get($d->id)->version, [], $lines);
        $l = $this->grns->lines($d->id)[0];
        self::assertSame([$checked, 0, 1, 'digital', '19.5000'], [$l['checked_at'], $l['damaged_units'], $l['stamp_on_pack'], $l['stamp_type'], $l['pack_price']]);
        // G: a line keyed after the bench answered for the paperwork (here on an empty receipt) waits for the bench too.
        $d2 = $this->receipt($desk, (int) $s['id'], []);
        $this->invoice($desk, $d2->id);
        $this->grns->bench($bench, $d2->id, $this->docs->get($d2->id)->version, ['paperwork_ok' => '1'], []);
        $this->grns->saveDraft($desk, $d2->id, $this->docs->get($d2->id)->version, [], [['sku_id' => $coil, 'packs' => 50, 'units_per_pack' => 10]]);
        $this->refusedCode('line_not_checked', fn () => $this->post($desk, $d2->id));
        // Counted, then the count taken back (unticked), then counted again.
        $this->grns->bench($bench, $d2->id, $this->docs->get($d2->id)->version, [], [1 => []]);
        $this->grns->bench($bench, $d2->id, $this->docs->get($d2->id)->version, [], [1 => ['checked' => false]]);
        $this->refusedCode('line_not_checked', fn () => $this->post($desk, $d2->id));
        $this->grns->bench($bench, $d2->id, $this->docs->get($d2->id)->version, [], [1 => []]);
        self::assertSame('posted', $this->post($desk, $d2->id)->status);
        self::assertSame('posted', $this->post($desk, $d->id)->status);
        self::assertSame([500, 100], [$this->onHand($coil), $this->onHand($sku)]);
        self::assertSame([], ReceivingInvariants::check(self::$db));
    }

    /** More units over than the line has: a typo is likelier than a delivery twice the invoice, so the bench confirms it (I174). */
    public function testOverUnitsBeyondTheLineAreConfirmedAtTheBench(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $coil = self::makeSku('Over coil');
        $this->dry($coil);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $coil, 'packs' => 1, 'units_per_pack' => 10]]);
        $bench = $this->staffUser('goods_in');
        $e = $this->refusedCode('over_unconfirmed', fn () => $this->bench($bench, $d->id, [1 => ['over_units' => '1000']]));
        self::assertStringContainsString('1000 units over on a line of 10', $e->getMessage());
        $this->bench($bench, $d->id, [1 => ['over_units' => '10']]);
        $this->bench($bench, $d->id, [1 => ['over_units' => '1000', 'over_confirmed' => '1']]);
        $this->bench($bench, $d->id, [1 => ['over_units' => '1000']]);
        self::assertStringContainsString('1000 units over on a line of 10 (the bench confirmed the count)', implode(' ', $this->grns->plan($d->id)['warnings']));
        $this->invoice($desk, $d->id);
        $this->post($desk, $d->id);
        self::assertSame([10, 1000], [$this->onHand($coil), $this->onHand($coil, 'VERIFY')]);
    }

    /** One supplier invoice copy on two live receipts of the supplier (its number typed differently): refused at attach, a problem at posting (I171). */
    public function testTheSameInvoiceCopyIsReceivedOnce(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people();
        $other = $this->activeSupplier($buyer, ['name' => 'Copy Other Ltd']);
        $sku = self::makeSku('Copy once item');
        $this->dry($sku);
        $a = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1]], ['invoice_number' => 'INV-001']);
        $b = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1]], ['invoice_number' => 'INV/001']);
        $c = $this->receipt($desk, (int) $other['id'], [['sku_id' => $sku, 'packs' => 1]], ['invoice_number' => 'INV-001']);
        $pdf = $this->pdf();
        $this->grns->attach($desk, $a->id, $pdf, 'inv.pdf', 'supplier_invoice');
        $e = $this->refusedCode('invoice_copy_elsewhere', fn () => $this->grns->attach($desk, $b->id, $pdf, 'inv.pdf', 'supplier_invoice'));
        self::assertStringContainsString("is already the supplier invoice of draft receipt #{$a->id}", $e->getMessage());
        $this->grns->attach($desk, $b->id, $pdf, 'inv.pdf', 'delivery_note');
        $this->grns->attach($desk, $c->id, $pdf, 'inv.pdf', 'supplier_invoice');
        // Attached past the check (two people at once): the receipt cannot be posted while the other one is live.
        $file = (int) self::$db->value("SELECT file_id FROM document_file WHERE document_id = ? AND role = 'supplier_invoice'", [$a->id]);
        $this->store->attach($desk, $b->id, $file, 'supplier_invoice');
        $this->bench($this->staffUser('goods_in'), $b->id);
        $e = $this->refusedCode('invoice_copy_elsewhere', fn () => $this->post($desk, $b->id));
        self::assertStringContainsString("is also the invoice of draft receipt #{$a->id}", $e->getMessage());
        $this->grns->cancel($desk, $a->id, $this->docs->get($a->id)->version, 'keyed twice');
        self::assertSame('posted', $this->post($desk, $b->id)->status);
    }

    /** A receipt started at the bench from the delivery note is completed by the desk: the invoice set by someone who did not key it (I172). */
    public function testAnotherPersonSetsTheSupplierInvoice(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Bench started item');
        $this->dry($sku);
        $goodsIn = $this->staffUser('goods_in');
        $deskReviewer = $this->staffUser(['purchasing_desk', 'reviewer']);
        $d = $this->grns->createDraft($goodsIn, (int) $s['id'], ['delivery_note' => 'DN-55']);
        $d = $this->grns->saveDraft($goodsIn, $d->id, $d->version, [], [['sku_id' => $sku, 'packs' => 2]]);
        $e = $this->refusedCode('not_creator', fn () => $this->grns->saveDraft($desk, $d->id, $d->version, ['invoice_number' => 'X-1'], $this->grns->lines($d->id)));
        self::assertStringContainsString('anyone who receives goods can set the supplier invoice', $e->getMessage());
        $this->grns->setInvoice($deskReviewer, $d->id, $this->docs->get($d->id)->version, ['invoice_number' => 'bench 77', 'invoice_date' => self::day('-1 day')]);
        self::assertSame(['bench 77', 'BENCH77', 'DN-55'], [$this->docs->get($d->id)->externalRef, self::$db->value('SELECT invoice_key FROM goods_receipt WHERE document_id = ?', [$d->id]),
            self::$db->value('SELECT delivery_note FROM goods_receipt WHERE document_id = ?', [$d->id])]);
        $this->refusedCode('duplicate_invoice', fn () => $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'BENCH 77']));
        $this->refusedCode('bad_field', fn () => $this->grns->setInvoice($desk, $d->id, $this->docs->get($d->id)->version, []));
        $this->refusedCode('role_not_allowed', fn () => $this->grns->setInvoice($this->staffUser('reviewer'), $d->id, $this->docs->get($d->id)->version, ['invoice_number' => 'Y']));
        $this->invoice($desk, $d->id);
        $this->bench($goodsIn, $d->id);
        $p = $this->post($desk, $d->id);
        self::assertSame(['code' => 'own_document', 'message' => 'You set this receipt\'s supplier invoice: another reviewer must review it.'],
            $this->docs->refusalFor((int) $deskReviewer->staffUserId, ['purchasing_desk', 'reviewer'], $p, 'review'));
        self::assertSame(0, $this->docs->decidableCount((int) $deskReviewer->staffUserId, ['purchasing_desk', 'reviewer']));
        self::assertSame([], ReceivingInvariants::check(self::$db));
    }

    /** When the goods arrived: the bench says "they arrived now" for a receipt keyed before they came; a check before the arrival is refused (I170). */
    public function testTheBenchSaysWhenTheGoodsArrived(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Arrival item');
        $this->dry($sku);
        $uk = new \DateTimeZone('Europe/London');
        $twoDays = (new \DateTimeImmutable('now', $uk))->modify('-2 days')->format('Y-m-d') . ' 09:00';
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1]], ['received_at' => $twoDays, 'backdate_reason' => 'keyed from the invoice']);
        $this->invoice($desk, $d->id);
        $this->grns->bench($this->staffUser('goods_in'), $d->id, $this->docs->get($d->id)->version, ['paperwork_ok' => '1', 'arrived_now' => true], [1 => []]);
        $today = (new \DateTimeImmutable('now', $uk))->format('Y-m-d');
        self::assertSame($today, $this->docs->get($d->id)->docDate, 'the document is dated the day the goods arrived');
        self::assertSame($today, $this->grns->plan($d->id)['header']['received_uk']);
        self::assertSame([], $this->grns->plan($d->id)['problems']);
        // A bench check older than the arrival (the received time moved later since): the time or the check is wrong.
        self::$db->exec('UPDATE grn_line SET checked_at = checked_at - INTERVAL 1 HOUR WHERE document_id = ?', [$d->id]);
        self::$db->exec('UPDATE goods_receipt SET checked_at = checked_at - INTERVAL 1 HOUR WHERE document_id = ?', [$d->id]);
        $this->refusedCode('checked_before_arrival', fn () => $this->post($desk, $d->id));
    }

    public function testWhoMayKeyCheckAndPost(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Who item');
        $this->dry($sku);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1]]);
        $this->refusedCode('not_creator', fn () => $this->grns->saveDraft($this->staffUser('purchasing_desk'), $d->id, $this->docs->get($d->id)->version, [], []));
        $this->refusedCode('role_not_allowed', fn () => $this->grns->createDraft($buyer, (int) $s['id']));
        $this->refusedCode('admin_cannot_post', fn () => $this->grns->createDraft($this->staffUser('admin'), (int) $s['id']));
        $this->refusedCode('role_not_allowed', fn () => $this->grns->bench($this->staffUser('reviewer'), $d->id, $this->docs->get($d->id)->version, ['paperwork_ok' => '1'], []));
        $this->refusedCode('staff_required', fn () => $this->grns->createDraft(Caller::system('test'), (int) $s['id']));
        // Anyone who receives goods may check it at the bench and post it; a stale form is refused.
        $other = $this->staffUser('goods_in');
        $v = $this->docs->get($d->id)->version;
        $this->invoice($other, $d->id);
        $this->grns->bench($other, $d->id, $v, ['paperwork_ok' => '1'], [1 => []]);
        $this->refusedCode('version_conflict', fn () => $this->grns->bench($other, $d->id, $v, ['paperwork_ok' => '1'], []));
        self::assertSame('posted', $this->post($other, $d->id)->status);
        $this->refusedCode('not_draft', fn () => $this->post($other, $d->id));
        $this->refusedCode('not_draft', fn () => $this->grns->bench($other, $d->id, $this->docs->get($d->id)->version, [], []));
    }

    /** The person who did the bench check never reviews the receipt (I133); the review queue's count leaves it out; G8. */
    public function testTheBenchCheckerNeverReviewsTheReceipt(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Review item');
        $this->dry($sku);
        $checker = $this->staffUser(['goods_in', 'reviewer']);
        $reviewer = $this->staffUser('reviewer');
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 3]]);
        $this->invoice($desk, $d->id);
        $this->bench($checker, $d->id);
        $p = $this->post($desk, $d->id);
        $task = $this->docTask($p->id);
        self::assertSame(0, $this->docs->decidableCount((int) $checker->staffUserId, ['goods_in', 'reviewer']));
        self::assertSame(1, $this->docs->decidableCount((int) $reviewer->staffUserId, ['reviewer']));
        self::assertSame(['code' => 'own_document', 'message' => 'You checked this delivery at the goods-in bench: another reviewer must review it.'],
            $this->docs->refusalFor((int) $checker->staffUserId, ['goods_in', 'reviewer'], $p, 'review'));
        $this->refusedCode('own_document', fn () => $this->docs->approve($checker, $task, null));
        $this->refusedCode('own_document', fn () => $this->docs->reject($checker, $task, 'not mine to judge'));
        // Another reviewer rejects it: the receipt is reversed (its stock taken back).
        self::assertSame(3, $this->onHand($sku));
        $this->docs->reject($reviewer, $task, 'wrong supplier');
        self::assertSame(['reversed', 0], [$this->docs->get($d->id)->status, $this->onHand($sku)]);
        self::assertSame([], ReceivingInvariants::check(self::$db));
        // G8: a decision by the checker, forged, is found.
        self::$db->exec('UPDATE review_task SET decided_by = ? WHERE id = ?', [$checker->staffUserId, $task]);
        self::assertCount(1, array_filter(ReceivingInvariants::check(self::$db), static fn (string $v): bool => str_contains($v, 'who did its bench check')));
        self::$db->exec('UPDATE review_task SET decided_by = ? WHERE id = ?', [$reviewer->staffUserId, $task]);
    }
}
