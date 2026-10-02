<?php

declare(strict_types=1);

namespace CW\Tests\Integration\PurchaseOrders;

use CW\Clock;
use CW\PurchaseOrders\PurchaseOrderHandler;

/**
 * The PO lifecycle through CW\PurchaseOrders\PurchaseOrders and the document base (spec §6.1-§6.3, §9.2; I48-I59): drafts,
 * the line resolution of a scan, approval below and above the value limit, the refusals of approval, sending, cancelling,
 * amending, copying, a rejected review that only records, pack_in_use, and the creator-only rule.
 */
final class PurchaseOrderLifecycleTest extends PurchaseOrderTestCase
{
    public function testCreateSaveAndTheScanResolutionOrder(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Scan Supplies']);
        $sid = (int) $s['id'];
        $pod = $this->itemWithBarcode('Pod 2ml', '05012345678900', '15012345678907', 6);
        $liquid = self::makeSku('Liquid 10ml cherry');
        $other = self::makeSku('Liquid 10ml cherry ice');
        $pack6 = $this->supplierItem($buyer, $sid, $pod, 6, '9.0000', ['supplier_code' => 'POD-6']);
        $single = $this->supplierItem($buyer, $sid, $pod, 1, '1.6500', ['supplier_code' => 'POD-1', 'is_preferred' => '1']);
        $liq = $this->supplierItem($buyer, $sid, $liquid, 10, '15.0000', ['supplier_code' => 'LIQ-10']);

        $d = $this->pos->createDraft($buyer, $sid, ['expected_date' => '2026-10-09', 'external_ref' => 'Q-77', 'note' => 'Deliver to bay 2']);
        self::assertSame(['draft', 'MAIN', 'Q-77', 'Deliver to bay 2'], [$d->status, self::$db->value('SELECT code FROM warehouse WHERE id = ?', [$d->warehouseId]),
            $d->externalRef, $d->note]);
        self::assertSame((new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d'), $d->docDate, 'order date: today (UK)');
        $po = $this->poRow($d->id);
        self::assertSame([$sid, null, 'manual', '2026-10-09'], [(int) $po['supplier_id'], $po['state'], $po['source'], $po['expected_date']]);

        // (1) a barcode: the single-unit barcode -> the preferred supplier item; the case barcode (6 per scan) -> the pack of 6.
        $r = $this->pos->addLine($buyer, $d->id, $d->version, '5012345678900');
        self::assertSame(['added', 1], [$r['status'], $r['line_no']]);
        $r = $this->pos->addLine($buyer, $d->id, $r['document']->version, '015012345678907', 2);
        self::assertSame(['added', 2], [$r['status'], $r['line_no']]);
        // (2) this supplier's code, any case; (3) a CW code.
        $r = $this->pos->addLine($buyer, $d->id, $r['document']->version, ' liq-10 ');
        self::assertSame(['added', 3], [$r['status'], $r['line_no']]);
        $r = $this->pos->addLine($buyer, $d->id, $r['document']->version, sprintf('CW-%06d', $other));
        self::assertSame(['added', 4], [$r['status'], $r['line_no']]);
        // A repeated scan adds a pack to the same supplier item's line.
        $r = $this->pos->addLine($buyer, $d->id, $r['document']->version, '5012345678900');
        self::assertSame(['incremented', 1], [$r['status'], $r['line_no']]);
        // (4) a search: choices (items this supplier sells first); nothing found.
        $c = $this->pos->addLine($buyer, $d->id, $r['document']->version, 'cherry');
        self::assertSame('choices', $c['status']);
        self::assertSame([$liquid, $other], array_column($c['choices'], 'sku_id'));
        self::assertSame((int) $liq['id'], $c['choices'][0]['supplier_item_id']);
        self::assertSame($r['document']->version, $c['document']->version, 'choices change nothing');
        self::assertSame('not_found', $this->pos->addLine($buyer, $d->id, $r['document']->version, 'zzz nothing')['status']);

        $lines = $this->pos->lines($d->id);
        self::assertSame([
            [(int) $single['id'], $pod, 1, 2, '1.6500'],
            [(int) $pack6['id'], $pod, 6, 2, '9.0000'],
            [(int) $liq['id'], $liquid, 10, 1, '15.0000'],
            [null, $other, 1, 1, '0.0000'],
        ], array_map(static fn (array $l): array => [$l['supplier_item_id'], $l['sku_id'], $l['units_per_pack'], $l['packs'], $l['pack_price']], $lines));
        self::assertSame([2, 12, 10, 1], array_map('intval', self::$db->column('SELECT qty FROM document_line WHERE document_id = ? ORDER BY line_no', [$d->id])));
        self::assertSame(['3.30', '18.00', '15.00', '0.00'], array_map(static fn (mixed $a): string => number_format((float) $a, 2, '.', ''),
            self::$db->column('SELECT amount FROM document_line WHERE document_id = ? ORDER BY line_no', [$d->id])));
        $po = $this->poRow($d->id);
        self::assertSame(['36.30', '7.26', '43.56'], [$po['net_total'], $po['vat_total'], $po['gross_total']]);
        self::assertContains('Line 4 (' . sprintf('CW-%06d', $other) . ') has a price of £0.', $this->pos->warnings($d->id));

        // saveDraft: header edits, a charge line, save_item creates the supplier item; packs 0 lines are the editor's removal.
        $doc = $this->docs->get($d->id);
        $edit = $lines;
        $edit[3] = ['kind' => 'item', 'sku_id' => $other, 'units_per_pack' => 12, 'packs' => 3, 'pack_price' => '£1,020.5', 'supplier_code' => 'CHI-12',
            'save_item' => true];
        $edit[] = ['kind' => 'charge', 'description' => 'Delivery', 'pack_price' => '7.50', 'vat_code' => 'S'];
        $doc = $this->pos->saveDraft($buyer, $d->id, $doc->version, ['note' => 'Bay 3', 'expected_date' => ''], $edit);
        self::assertSame('Bay 3', $doc->note);
        self::assertNull($this->poRow($d->id)['expected_date']);
        $new = self::$db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ?', [$sid, $other]);
        self::assertSame(['CHI-12', 12, 'case'], [$new['supplier_code'], (int) $new['units_per_pack'], $new['purchase_unit']]);
        $l4 = $this->pos->lines($d->id)[3];
        self::assertSame([(int) $new['id'], '1020.5000', 3], [$l4['supplier_item_id'], $l4['pack_price'], $l4['packs']]);
        self::assertEquals(['kind' => 'charge', 'description' => 'Delivery', 'pack_price' => '7.5000'],
            array_intersect_key($this->pos->lines($d->id)[4], ['kind' => 1, 'description' => 1, 'pack_price' => 1]));
        self::assertSame([null, null, '7.500000'], array_values((array) self::$db->one('SELECT sku_id, qty, amount FROM document_line WHERE document_id = ? AND line_no = 5', [$d->id])));
        self::refused(409, 'version_conflict', fn () => $this->pos->saveDraft($buyer, $d->id, $doc->version - 1, [], []));
        self::refused(409, 'supplier_has_lines', fn () => $this->pos->saveDraft($buyer, $d->id, $doc->version, ['supplier_id' => (string) $this->activeSupplier($buyer,
            ['name' => 'Other Supplier'])['id']], $this->pos->lines($d->id)));
        self::refused(422, 'bad_line', fn () => $this->pos->saveDraft($buyer, $d->id, $doc->version, [], [['supplier_item_id' => (int) $pack6['id'], 'packs' => 1,
            'units_per_pack' => 12]]));
        self::refused(422, 'bad_line', fn () => $this->pos->saveDraft($buyer, $d->id, $doc->version, [], [['kind' => 'charge', 'description' => 'x', 'pack_price' => '1.005']]));
        self::refused(422, 'bad_line', fn () => $this->pos->saveDraft($buyer, $d->id, $doc->version, [], [['sku_id' => $liquid, 'packs' => 1, 'vat_code' => 'XX']]));
        self::assertSame(['document.create', 'po.create'], array_slice($this->audits($d->id), 0, 2));
    }

    public function testApproveBelowTheLimit(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Approve Ltd', 'vat_number' => 'GB999']);
        $sku = self::makeSku('Approve item');
        $si = $this->supplierItem($buyer, (int) $s['id'], $sku, 24, '20.0000', ['supplier_code' => 'AP-24']);
        $d = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 5], ['kind' => 'charge', 'description' => 'Carriage',
            'pack_price' => '10.00']]);
        $p = $this->pos->approve($buyer, $d->id, $d->version);
        self::assertSame(['posted', 'PO-000001', 'pending', $buyer->staffUserId], [$p->status, $p->number, $p->reviewState, $p->postedBy]);
        $po = $this->poRow($d->id);
        self::assertSame(['approved', '110.00', '22.00', '132.00'], [$po['state'], $po['net_total'], $po['vat_total'], $po['gross_total']]);
        $company = json_decode((string) $po['company_snapshot'], true);
        self::assertEquals(['legal_name' => '', 'confirmed' => false], array_intersect_key($company, ['legal_name' => 1, 'confirmed' => 1]));
        self::assertEquals(['code' => (string) $s['code'], 'vat_number' => 'GB999'], array_intersect_key(json_decode((string) $po['supplier_snapshot'], true),
            ['code' => 1, 'vat_number' => 1]));
        $anchor = self::$db->one('SELECT content_hash, content FROM po_posting WHERE document_id = ?', [$d->id]);
        self::assertSame(hash('sha256', (string) $anchor['content']), $anchor['content_hash']);
        self::assertSame((string) $anchor['content'], PurchaseOrderHandler::content(self::$db->one('SELECT * FROM purchase_order WHERE document_id = ?', [$d->id]) ?? [],
            self::$db->all('SELECT * FROM po_line WHERE document_id = ?', [$d->id])));
        $price = self::$db->one("SELECT * FROM supplier_item_price WHERE supplier_item_id = ? AND source = 'po'", [(int) $si['id']]);
        self::assertSame(['20.0000', 24, '0.833333', 'PO-000001', $d->id, $d->docDate], [$price['pack_price'], (int) $price['units_per_pack'], $price['unit_price'],
            $price['source_ref'], (int) $price['document_id'], $price['effective_on']]);
        $after = $this->items->get((int) $si['id']);
        self::assertSame(['20.0000', $d->docDate, $d->id, '20.0000', 'manual'], [$after['last_po_pack_price'], $after['last_po_on'], (int) $after['last_po_document_id'],
            $after['last_pack_price'], $after['last_price_source']], 'a PO price sets only last_po_* (S4)');
        $task = self::$db->one("SELECT * FROM review_task WHERE subject_type = 'document' AND subject_id = ?", [$d->id]);
        self::assertSame(['review', 'all_documents', 110, 'open'], [$task['kind'], $task['reason'], (int) $task['units'], $task['state']]);
        self::assertSame('+7', (string) (Clock::fromDb((string) $task['due_at'])->diff(Clock::fromDb((string) $task['opened_at']))->days === 7 ? '+7' : 'x'));
        self::assertContains('po.approve', $this->audits($d->id));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger'), 'a PO books no stock');
        self::refused(409, 'not_draft', fn () => $this->pos->saveDraft($buyer, $d->id, $p->version, [], []));
    }

    public function testAboveTheLimitWaitsForAReviewer(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Big Order Ltd']);
        $sku = self::makeSku('Expensive item');
        $si = $this->supplierItem($buyer, (int) $s['id'], $sku, 1, '10000.0100');
        $d = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 1]]);
        $w = $this->pos->approve($buyer, $d->id, $d->version);
        self::assertSame(['awaiting_approval', null], [$w->status, $w->number]);
        $task = self::$db->one("SELECT * FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND kind = 'approval'", [$d->id]);
        self::assertSame(['over_value', 10001, 'open'], [$task['reason'], (int) $task['units'], $task['state']]);
        self::assertNull($this->poRow($d->id)['state']);
        self::refused(403, 'role_not_allowed', fn () => $this->docs->approve($buyer, (int) $task['id'], null));
        self::refused(403, 'admin_cannot_review', fn () => $this->docs->approve($this->staffUser('admin'), (int) $task['id'], null));
        $reviewer = $this->staffUser('reviewer');
        $p = $this->docs->approve($reviewer, (int) $task['id'], 'ok');
        self::assertSame(['posted', 'PO-000001', $buyer->staffUserId, 'approved'], [$p->status, $p->number, $p->postedBy, $p->reviewState],
            'posted as the requester\'s posting (I19, I34)');
        self::assertSame('approved', $this->poRow($d->id)['state']);
        self::assertSame(0, $this->docTask($d->id), 'the approval was the review');

        // Exactly £10,000.00 is not above the limit; a rejected request is cancelled.
        $si2 = $this->supplierItem($buyer, (int) $s['id'], self::makeSku('Exact item'), 1, '10000.0000');
        $e = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si2['id'], 'packs' => 1]]);
        self::assertSame('PO-000002', $this->pos->approve($buyer, $e->id, $e->version)->number);
        $x = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 2]]);
        $x = $this->pos->approve($buyer, $x->id, $x->version);
        $r = $this->docs->reject($reviewer, $this->docTask($x->id, 'approval'), 'too much stock');
        self::assertSame(['cancelled', null], [$r->status, $this->poRow($x->id)['state']]);
        // The requester may withdraw a request (the draft comes back) and cancel it.
        $y = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 3]]);
        $y = $this->pos->approve($buyer, $y->id, $y->version);
        $other = $this->staffUser('buyer');
        self::refused(409, 'awaiting_approval', fn () => $this->pos->cancel($other, $y->id, $y->version, 'not_needed', null));
        $y = $this->pos->withdraw($buyer, $y->id, $y->version);
        self::assertSame('draft', $y->status);
        $y = $this->pos->approve($buyer, $y->id, $y->version);
        self::assertSame('cancelled', $this->pos->cancel($buyer, $y->id, $y->version, 'not_needed', 'changed my mind')->status);
    }

    public function testApprovalIsRefusedForAnInactiveSupplierAnUnapprovedRouteOrAnotherWarehouse(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Refusals Ltd']);
        $sku = self::makeSku('Refusal item');
        $si = $this->supplierItem($buyer, (int) $s['id'], $sku, 1, '1.0000');
        $d = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 1]]);
        $s = $this->sup->deactivate($buyer, (int) $s['id'], (int) $s['version'], 'stopped trading');
        self::refused(422, 'supplier_not_active', fn () => $this->pos->approve($buyer, $d->id, $d->version));
        self::refused(422, 'supplier_inactive', fn () => $this->pos->createDraft($buyer, (int) $s['id'], []));

        // A draft supplier: the draft is allowed (warned), the approval refused.
        $draftSupplier = $this->draft($buyer, ['name' => 'Not Yet Ltd']);
        $si2 = $this->supplierItem($buyer, (int) $draftSupplier['id'], $sku, 1, '1.0000');
        $d2 = $this->draftPo($buyer, (int) $draftSupplier['id'], [['supplier_item_id' => (int) $si2['id'], 'packs' => 1]]);
        self::assertStringContainsString('is draft: the order can be drafted', implode(' ', $this->pos->warnings($d2->id)));
        self::refused(422, 'supplier_not_active', fn () => $this->pos->approve($buyer, $d2->id, $d2->version));

        // Overseas: the route approval cleared by a route change refuses the PO until it is approved again.
        $o = $this->activeSupplier($buyer, ['name' => 'Overseas Co', 'country' => 'NL', 'is_overseas' => '1', 'import_route' => 'Stamped by the importer in Kent']);
        $o = $this->sup->update($buyer, (int) $o['id'], (int) $o['version'], ['import_route' => 'Stamped in Essex']);
        self::assertNull($o['import_route_approved_at']);
        $si3 = $this->supplierItem($buyer, (int) $o['id'], $sku, 1, '1.0000');
        $d3 = $this->draftPo($buyer, (int) $o['id'], [['supplier_item_id' => (int) $si3['id'], 'packs' => 1]]);
        self::refused(422, 'import_route_not_approved', fn () => $this->pos->approve($buyer, $d3->id, $d3->version));
        // Made UK by the same buyer (review blocker, I72): still refused until a second person approves the change.
        $o = $this->sup->update($buyer, (int) $o['id'], (int) $o['version'], ['is_overseas' => '0', 'country' => 'GB']);
        self::assertSame(0, (int) $o['is_overseas']);
        self::refused(422, 'import_route_not_approved', fn () => $this->pos->approve($buyer, $d3->id, $d3->version));
        self::assertStringContainsString('waits for a second person', implode(' ', $this->pos->warnings($d3->id)));
        $this->sup->approve($this->staffUser('reviewer'), $this->openTask((int) $o['id']), 'confirmed UK');
        $d3 = $this->pos->approve($buyer, $d3->id, $d3->version);
        self::assertSame('posted', $d3->status);
        $posted = [$d3->id];

        // Another warehouse than a sellable one (VERIFY): refused.
        $ok = $this->activeSupplier($buyer, ['name' => 'Warehouse Test Ltd']);
        $si4 = $this->supplierItem($buyer, (int) $ok['id'], $sku, 1, '1.0000');
        $d4 = $this->draftPo($buyer, (int) $ok['id'], [['supplier_item_id' => (int) $si4['id'], 'packs' => 1]]);
        $d4 = $this->docs->updateDraft($buyer, $d4->id, $d4->version, ['warehouse' => 'VERIFY']);
        self::refused(422, 'bad_warehouse', fn () => $this->pos->approve($buyer, $d4->id, $d4->version));
        self::assertSame($posted, array_map('intval', self::$db->column("SELECT id FROM document WHERE doc_type = 'PO' AND status = 'posted'")),
            'only the order approved once the second person confirmed the supplier is UK');
    }

    /** Review nits (I81): the order date is bounded, and a cancelled order no longer sets its items' "last PO price". */
    public function testTheOrderDateIsBoundedAndACancelledOrderLeavesTheLastPoPrice(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Last Price Ltd']);
        $si = $this->supplierItem($buyer, (int) $s['id'], self::makeSku('Last PO item'), 1, '1.0000');
        $line = static fn (string $price): array => [['supplier_item_id' => (int) $si['id'], 'packs' => 1, 'pack_price' => $price]];
        self::assertSame('doc_date', self::refused(422, 'bad_field', fn () => $this->draftPo($buyer, (int) $s['id'], $line('1.00'), ['doc_date' => self::day('+1 year')]))
            ->detail['field'], 'a 2027 typo would freeze last_po_on');
        self::refused(422, 'bad_field', fn () => $this->draftPo($buyer, (int) $s['id'], $line('1.00'), ['doc_date' => self::day('-3 years')]));
        $first = $this->draftPo($buyer, (int) $s['id'], $line('1.10'), ['doc_date' => self::day('-3 days')]);
        $first = $this->pos->approve($buyer, $first->id, $first->version);
        $second = $this->draftPo($buyer, (int) $s['id'], $line('1.30'), ['doc_date' => self::day('-1 day')]);
        $second = $this->pos->approve($buyer, $second->id, $second->version);
        $row = fn (): array => array_values((array) self::$db->one('SELECT last_po_pack_price, last_po_on, last_po_document_id FROM supplier_item WHERE id = ?', [(int) $si['id']]));
        self::assertSame(['1.3000', self::day('-1 day'), $second->id], array_map(static fn (mixed $v): mixed => is_int($v) || $v === null ? $v : (string) $v,
            [$row()[0], $row()[1], (int) $row()[2]]));
        $this->pos->cancel($buyer, $second->id, $second->version, 'not_needed', null);
        self::assertSame(['1.1000', self::day('-3 days'), $first->id], [(string) $row()[0], (string) $row()[1], (int) $row()[2]], 'back to the order that stands');
        $this->pos->cancel($buyer, $first->id, $first->version, 'not_needed', null);
        self::assertSame([null, null, null], $row(), 'no order stands: no last PO price');
        self::assertSame('1.0000', (string) self::$db->value('SELECT last_pack_price FROM supplier_item WHERE id = ?', [(int) $si['id']]), 'the last price is untouched');
    }

    public function testSendCancelAmendCopy(): void
    {
        $buyer = $this->staffUser('buyer');
        ['doc' => $p, 'supplier' => $s, 'si' => $si] = $this->approvedPo($buyer, 2, '12.0000', 6);
        // Review finding (I86): the company details are not confirmed (the seed), so sending needs "send anyway".
        $e = self::refused(409, 'send_warnings', fn () => $this->pos->markSent($buyer, $p->id, $p->version, 'email', 'orders@acme.example'));
        self::assertStringContainsString('company details it was approved with are not confirmed', $e->getMessage());
        self::assertSame('approved', $this->poRow($p->id)['state']);
        $sent = $this->pos->markSent($buyer, $p->id, $p->version, 'email', 'orders@acme.example', true);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'po.send' AND entity_id = ? AND JSON_LENGTH(detail, '$.acknowledged') = 1",
            [(string) $p->id]), 'the acknowledged warning is audited');
        $po = $this->poRow($p->id);
        self::assertSame(['sent', 'email', 'orders@acme.example', $buyer->staffUserId, null], [$po['state'], $po['sent_via'], $po['sent_to'], (int) $po['sent_by'],
            $po['sent_file_id']], 'no file store here: the PDF is not archived');
        self::refused(409, 'version_conflict', fn () => $this->pos->markSent($buyer, $p->id, $p->version, 'email', null, true));
        $again = $this->pos->markSent($buyer, $p->id, $sent->version, 'phone', null, true);
        self::assertSame(['sent', 'phone'], [$this->poRow($p->id)['state'], $this->poRow($p->id)['sent_via']]);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'po.send' AND entity_id = ?", [(string) $p->id]));

        // Cancel: the reversal is a PO document numbered in the same series, with no purchase_order of its own.
        $c = $this->pos->cancel($buyer, $p->id, $again->version, 'supplier_cannot_supply', 'out of stock');
        self::assertSame('reversed', $c->status);
        $rev = $this->docs->get((int) self::$db->value('SELECT id FROM document WHERE reverses_id = ?', [$p->id]));
        self::assertSame(['PO-000002', 'posted', 'supplier_cannot_supply', 'pending'], [$rev->number, $rev->status, $rev->reasonCode, $rev->reviewState],
            'a cancellation is reviewed like a PO');
        self::assertSame('cancelled', $this->poRow($p->id)['state']);
        self::assertSame([], $this->poRow($rev->id), 'a reversal has no purchase_order');
        self::refused(409, 'not_sendable', fn () => $this->pos->markSent($buyer, $p->id, $c->version, 'email', null, true));

        // Amend: a reversal (po_amended) and a new draft copied from the original, in one transaction.
        ['doc' => $q] = $this->approvedPo($buyer, 3, '5.5000', 1);
        $new = $this->pos->amend($buyer, $q->id, 'po_amended', null);
        self::assertSame('draft', $new->status);
        $npo = $this->poRow($new->id);
        self::assertSame(['amend', $q->id], [$npo['source'], (int) $npo['amends_document_id']]);
        self::assertSame([3, '5.5000'], [$this->pos->lines($new->id)[0]['packs'], $this->pos->lines($new->id)[0]['pack_price']]);
        self::assertSame('reversed', $this->docs->get($q->id)->status);
        self::assertSame('po_amended', self::$db->value('SELECT reason_code FROM document WHERE reverses_id = ?', [$q->id]));

        // Copy: from any PO (a cancellation copies its original), the original prices.
        $copy = $this->pos->copy($buyer, $rev->id);
        self::assertSame(['copy', (int) $s['id']], [$this->poRow($copy->id)['source'], (int) $this->poRow($copy->id)['supplier_id']]);
        self::assertSame([(int) $si['id'], 2, '12.0000'], [$this->pos->lines($copy->id)[0]['supplier_item_id'], $this->pos->lines($copy->id)[0]['packs'],
            $this->pos->lines($copy->id)[0]['pack_price']]);
        $other = $this->staffUser('purchasing_manager');
        $copy2 = $this->pos->copy($other, $p->id);
        self::assertSame($other->staffUserId, $copy2->createdBy, 'a copy is the copier\'s draft');
        // A draft is cancelled, never reversed.
        $cd = $this->pos->cancel($buyer, $copy->id, $copy->version, 'not_needed', null);
        self::assertSame(['cancelled', 'No longer needed'], [$cd->status, $cd->cancelReason]);
    }

    public function testRejectingTheReviewRecordsAndCancelsNothing(): void
    {
        $buyer = $this->staffUser('buyer');
        ['doc' => $p] = $this->approvedPo($buyer);
        $reviewer = $this->staffUser('reviewer');
        $r = $this->docs->reject($reviewer, $this->docTask($p->id), 'price too high');
        self::assertSame(['posted', 'rejected'], [$r->status, $r->reviewState]);
        self::assertSame('approved', $this->poRow($p->id)['state'], 'the order stands: the buyer cancels or amends it');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM document WHERE reverses_id = ?', [$p->id]), 'nothing reversed');
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'document.reject' AND entity_id = ?", [(string) $p->id]), true);
        self::assertSame([false, true], [$audit['booked'], $audit['recorded']]);
        $c = $this->pos->cancel($buyer, $p->id, $r->version, 'not_needed', null);
        self::assertSame('reversed', $c->status);
    }

    public function testPackInUseAndCreatorOnly(): void
    {
        $buyer = $this->staffUser('buyer');
        ['doc' => $p, 'si' => $si, 'supplier' => $s] = $this->approvedPo($buyer, 1, '6.0000', 6);
        $si = $this->items->get((int) $si['id']);
        $e = self::refused(409, 'pack_in_use', fn () => $this->items->update($buyer, (int) $si['id'], (int) $si['version'], ['units_per_pack' => '12']));
        self::assertStringContainsString($p->number ?? 'x', $e->getMessage());
        self::assertSame('Better code', $this->items->update($buyer, (int) $si['id'], (int) $si['version'], ['supplier_description' => 'Better code'])['supplier_description'],
            'other fields still change');
        // A draft that uses a supplier item counts too (I55); a cancelled draft does not.
        $si2 = $this->supplierItem($buyer, (int) $s['id'], self::makeSku('Draft-only item'), 4, '2.0000');
        $d = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si2['id'], 'packs' => 1]]);
        $si2 = $this->items->get((int) $si2['id']);
        self::refused(409, 'pack_in_use', fn () => $this->items->update($buyer, (int) $si2['id'], (int) $si2['version'], ['units_per_pack' => '8']));
        $this->pos->cancel($buyer, $d->id, $d->version, 'entered_in_error', null);
        self::assertSame(8, (int) $this->items->update($buyer, (int) $si2['id'], (int) $si2['version'], ['units_per_pack' => '8'])['units_per_pack']);

        $d2 = $this->draftPo($buyer, (int) $s['id'], [['sku_id' => (int) $si['sku_id'], 'packs' => 1]]);
        $other = $this->staffUser('buyer');
        self::refused(403, 'not_creator', fn () => $this->pos->saveDraft($other, $d2->id, $d2->version, [], []));
        self::refused(403, 'not_creator', fn () => $this->pos->addLine($other, $d2->id, $d2->version, 'x1'));
        self::refused(403, 'role_not_allowed', fn () => $this->pos->createDraft($this->staffUser('reviewer'), (int) $s['id'], []));
        self::refused(403, 'admin_cannot_post', fn () => $this->pos->createDraft($this->staffUser('admin'), (int) $s['id'], []));
        self::assertSame('posted', $this->pos->approve($other, $d2->id, $d2->version)->status, 'anyone allowed to post may approve another\'s draft');
    }
}
