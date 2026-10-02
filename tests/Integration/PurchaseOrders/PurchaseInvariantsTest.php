<?php

declare(strict_types=1);

namespace CW\Tests\Integration\PurchaseOrders;

use CW\Invariants;
use CW\PurchaseOrders\PurchaseInvariants;

/**
 * P1-P6 (spec §6.4; I48-I59): each check catches a violation planted with admin SQL, and is quiet on what the services
 * write. Every test puts back what it planted, so the post-condition (Invariants::check, which includes P1-P6) holds again.
 */
final class PurchaseInvariantsTest extends PurchaseOrderTestCase
{
    /** @return list<string> */
    private static function found(string $needle): array
    {
        return array_values(array_filter(PurchaseInvariants::check(self::$db), static fn (string $v): bool => str_contains($v, $needle)));
    }

    public function testQuietOnWhatTheServicesWrite(): void
    {
        $buyer = $this->staffUser('buyer');
        ['doc' => $p, 'supplier' => $s, 'si' => $si] = $this->approvedPo($buyer, 3, '1.6500', 24);
        $p = $this->pos->markSent($buyer, $p->id, $p->version, 'email', null, true);
        self::$db->transaction(fn () => $this->pos->applyReceipt($p->id, [1 => 24], 'GRN-1'));
        $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 1], ['kind' => 'charge', 'description' => 'Delivery', 'pack_price' => '3.33',
            'vat_code' => 'R']]);
        ['doc' => $q] = $this->approvedPo($buyer, 1, '0.0499', 99_999);
        $this->pos->amend($buyer, $q->id, 'po_amended', null);
        self::assertSame([], PurchaseInvariants::check(self::$db));
        self::assertSame([], Invariants::check(self::$db));
    }

    public function testP1HeaderAndState(): void
    {
        $buyer = $this->staffUser('buyer');
        ['doc' => $p] = $this->approvedPo($buyer);
        self::$db->exec("UPDATE purchase_order SET state = 'cancelled' WHERE document_id = ?", [$p->id]);
        self::assertCount(1, self::found("PO document {$p->id} ({$p->number}) is posted but its purchase_order state is cancelled"));
        self::$db->exec('UPDATE purchase_order SET state = NULL WHERE document_id = ?', [$p->id]);
        self::assertCount(1, self::found("PO document {$p->id} ({$p->number}) is posted but its purchase_order state is NULL"));
        self::$db->exec("UPDATE purchase_order SET state = 'approved' WHERE document_id = ?", [$p->id]);
        $orphan = self::$db->insert("INSERT INTO document (doc_type, created_actor) VALUES ('PO', 'system:test')");
        self::assertCount(1, self::found("PO document {$orphan} (draft) has no purchase_order row"));
        self::$db->exec("UPDATE document SET doc_type = 'GRN' WHERE id = ?", [$orphan]);
        self::$db->exec('INSERT INTO purchase_order (document_id, supplier_id) SELECT ?, supplier_id FROM purchase_order WHERE document_id = ?', [$orphan, $p->id]);
        self::assertCount(1, self::found("document {$orphan} (draft) is a GRN but has a purchase_order row"));
        self::$db->exec('DELETE FROM purchase_order WHERE document_id = ?', [$orphan]);
        self::$db->exec('DELETE FROM document WHERE id = ?', [$orphan]);
        self::assertSame([], PurchaseInvariants::check(self::$db));
    }

    public function testP2AChangedLineOrHeaderAfterApproval(): void
    {
        $buyer = $this->staffUser('buyer');
        ['doc' => $p] = $this->approvedPo($buyer, 2, '12.0000', 6);
        self::$db->exec("UPDATE po_line SET pack_price = '11.0000' WHERE document_id = ?", [$p->id]);
        self::assertCount(1, self::found("PO {$p->number} (document {$p->id}): its header or lines changed after approval"));
        self::$db->exec("UPDATE po_line SET pack_price = '12.0000' WHERE document_id = ?", [$p->id]);
        self::$db->exec("UPDATE purchase_order SET expected_date = '2030-01-01' WHERE document_id = ?", [$p->id]);
        self::assertCount(1, self::found('its header or lines changed after approval'));
        self::$db->exec('UPDATE purchase_order SET expected_date = NULL WHERE document_id = ?', [$p->id]);
        // Receipts and the state are not part of the anchor.
        self::$db->transaction(fn () => $this->pos->applyReceipt($p->id, [1 => 1], 'GRN-1'));
        self::assertSame([], self::found('changed after approval'));
        $content = (string) self::$db->value('SELECT content FROM po_posting WHERE document_id = ?', [$p->id]);
        self::$db->exec("UPDATE po_posting SET content = REPLACE(content, '12.0000', '13.0000') WHERE document_id = ?", [$p->id]);
        self::assertCount(1, self::found("po_posting of document {$p->id}: its content does not hash to its content_hash"));
        self::$db->exec('UPDATE po_posting SET content = ? WHERE document_id = ?', [$content, $p->id]);
        $hash = (string) self::$db->value('SELECT content_hash FROM po_posting WHERE document_id = ?', [$p->id]);
        self::$db->exec('DELETE FROM po_posting WHERE document_id = ?', [$p->id]);
        self::assertCount(1, self::found("PO document {$p->id} ({$p->number}) is posted but has no po_posting anchor"));
        self::$db->exec('INSERT INTO po_posting (document_id, content_hash, content) VALUES (?, ?, ?)', [$p->id, $hash, $content]);
        self::$db->transaction(fn () => $this->pos->reverseReceipt($p->id, [1 => 1], 'GRN-1'));
        self::assertSame([], PurchaseInvariants::check(self::$db));
    }

    public function testP3TheLineFormulas(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Formula Ltd']);
        $si = $this->supplierItem($buyer, (int) $s['id'], self::makeSku('Formula item'), 3, '1.0000');
        $d = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 2], ['kind' => 'charge', 'description' => 'Fee', 'pack_price' => '2.00']]);
        foreach (["qty = 7", "unit_cost = '0.333334'", "amount = '2.01'"] as $set) {
            $was = self::$db->one('SELECT qty, unit_cost, amount FROM document_line WHERE document_id = ? AND line_no = 1', [$d->id]);
            self::$db->exec("UPDATE document_line SET {$set} WHERE document_id = ? AND line_no = 1", [$d->id]);
            self::assertCount(1, self::found("PO document {$d->id} line 1 (item)"), $set);
            self::$db->exec('UPDATE document_line SET qty = ?, unit_cost = ?, amount = ? WHERE document_id = ? AND line_no = 1',
                [$was['qty'], $was['unit_cost'], $was['amount'], $d->id]);
        }
        self::$db->exec("UPDATE document_line SET amount = '2.50' WHERE document_id = ? AND line_no = 2", [$d->id]);
        self::assertCount(1, self::found("PO document {$d->id} line 2 (charge)"));
        self::$db->exec("UPDATE document_line SET amount = '2.00' WHERE document_id = ? AND line_no = 2", [$d->id]);
        self::assertSame([], PurchaseInvariants::check(self::$db));
    }

    public function testP4LinePairs(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Pairs Ltd']);
        $d = $this->draftPo($buyer, (int) $s['id'], [['sku_id' => self::makeSku('Pairs item'), 'packs' => 1]]);
        $row = self::$db->one('SELECT * FROM po_line WHERE document_id = ?', [$d->id]);
        self::$db->exec('DELETE FROM po_line WHERE document_id = ?', [$d->id]);
        self::assertCount(1, self::found("PO document {$d->id} line 1 has no po_line"));
        self::$db->exec('INSERT INTO po_line (document_id, line_no, kind, units_per_pack, packs, pack_price, vat_code, vat_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$d->id, 1, $row['kind'], $row['units_per_pack'], $row['packs'], $row['pack_price'], $row['vat_code'], $row['vat_rate']]);
        self::assertSame([], PurchaseInvariants::check(self::$db));
    }

    public function testP5StateAgainstReceipts(): void
    {
        $buyer = $this->staffUser('buyer');
        ['doc' => $p] = $this->approvedPo($buyer, 2, '1.0000', 1);
        self::$db->exec('UPDATE po_line SET received_units = 1 WHERE document_id = ?', [$p->id]);
        self::assertCount(1, self::found("PO {$p->number} is approved with 1 units received"));
        self::$db->exec("UPDATE purchase_order SET state = 'received' WHERE document_id = ?", [$p->id]);
        self::assertCount(1, self::found("PO {$p->number} is received with 1 units received"));
        self::$db->exec("UPDATE purchase_order SET state = 'approved' WHERE document_id = ?", [$p->id]);
        self::$db->exec('UPDATE po_line SET received_units = 0 WHERE document_id = ?', [$p->id]);
        self::assertSame([], PurchaseInvariants::check(self::$db));
    }

    public function testP6Totals(): void
    {
        $buyer = $this->staffUser('buyer');
        ['doc' => $p] = $this->approvedPo($buyer, 2, '12.0000', 6);
        // A total changed after approval: P6 (and P2, the anchor keeps the totals).
        self::$db->exec("UPDATE purchase_order SET net_total = '25.00', gross_total = vat_total + 25 WHERE document_id = ?", [$p->id]);
        self::assertCount(1, self::found("PO {$p->number}: totals net 25.00"));
        self::$db->exec("UPDATE purchase_order SET net_total = '24.00', gross_total = vat_total + 24 WHERE document_id = ?", [$p->id]);
        self::assertSame([], PurchaseInvariants::check(self::$db));
    }
}
