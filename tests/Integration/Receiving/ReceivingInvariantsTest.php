<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Receiving;

use CW\Receiving\ReceivingInvariants;

/**
 * G1-G8 (I142): each check catches a violation planted with admin SQL, and is quiet on what the services write. Every test puts
 * back what it planted, so the post-condition (Invariants::check, which includes G1-G8) holds again.
 */
final class ReceivingInvariantsTest extends ReceivingTestCase
{
    /** @return list<string> */
    private static function found(string $needle): array
    {
        return array_values(array_filter(ReceivingInvariants::check(self::$db), static fn (string $v): bool => str_contains($v, $needle)));
    }

    /** A posted receipt of 10 units with 2 damaged (an incident) against a PO line. @return array{id: int, po: int, sku: int, number: string} */
    private function posted(): array
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Invariant Supplies');
        ['doc' => $po, 'sku' => $sku] = $this->approvedPoFor($buyer, (int) $s['id'], 10, 1);
        $this->dry($sku);
        $d = $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'INV-G'], $po->id, true);
        $this->invoice($desk, $d->id);
        $this->bench($this->staffUser('goods_in'), $d->id, [1 => ['damaged_units' => '2']]);
        $p = $this->post($desk, $d->id);
        return ['id' => $d->id, 'po' => $po->id, 'sku' => $sku, 'number' => (string) $p->number];
    }

    public function testQuietOnWhatTheServicesWrite(): void
    {
        $r = $this->posted();
        ['desk' => $desk, 'supplier' => $s] = $this->people('Quiet Ltd');
        $sku = self::makeSku('Quiet item');
        $this->dry($sku);
        $draft = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1]]);
        $this->grns->cancel($desk, $draft->id, $draft->version, 'not needed');
        $this->docs->reverse($this->staffUser('purchasing_desk'), $r['id'], 'entered_in_error', null);
        self::assertSame([], ReceivingInvariants::check(self::$db));
    }

    public function testG1HeadersAndInvoiceKeys(): void
    {
        $r = $this->posted();
        self::$db->exec("UPDATE goods_receipt SET invoice_key = 'OTHER' WHERE document_id = ?", [$r['id']]);
        self::assertCount(1, self::found("receipt {$r['id']} ({$r['number']}): its invoice key is OTHER, not INV-G"));
        self::$db->exec("UPDATE goods_receipt SET invoice_key = 'INV-G' WHERE document_id = ?", [$r['id']]);
        $orphan = self::$db->insert("INSERT INTO document (doc_type, created_actor) VALUES ('GRN', 'system:test')");
        self::assertCount(1, self::found("GRN document {$orphan} (draft) has no goods_receipt row"));
        self::$db->exec('DELETE FROM document WHERE id = ?', [$orphan]);
        self::assertSame([], ReceivingInvariants::check(self::$db));
    }

    public function testG3TheAnchorFindsAChangeAfterPosting(): void
    {
        $r = $this->posted();
        self::$db->exec('UPDATE grn_line SET damaged_units = 1 WHERE document_id = ?', [$r['id']]);
        self::assertCount(1, self::found("receipt {$r['number']} (document {$r['id']}): its header, lines or bench findings changed after posting"));
        self::$db->exec('UPDATE grn_line SET damaged_units = 2 WHERE document_id = ?', [$r['id']]);
        self::$db->exec('UPDATE goods_receipt SET paperwork_ok = 0 WHERE document_id = ?', [$r['id']]);
        self::assertCount(1, self::found('changed after posting'));
        self::$db->exec('UPDATE goods_receipt SET paperwork_ok = 1 WHERE document_id = ?', [$r['id']]);
        $content = (string) self::$db->value('SELECT content FROM grn_posting WHERE document_id = ?', [$r['id']]);
        self::$db->exec("UPDATE grn_posting SET content = REPLACE(content, 'packs', 'pucks') WHERE document_id = ?", [$r['id']]);
        self::assertCount(1, self::found("grn_posting of document {$r['id']}: its content does not hash to its content_hash"));
        self::$db->exec('UPDATE grn_posting SET content = ? WHERE document_id = ?', [$content, $r['id']]);
        self::assertSame([], ReceivingInvariants::check(self::$db));
    }

    public function testG4TheStockOfTheLines(): void
    {
        $r = $this->posted();
        self::$db->exec('UPDATE grn_line SET accepted_units = 9 WHERE document_id = ?', [$r['id']]);
        self::assertCount(1, self::found("receipt {$r['number']} line 1: 8 units booked at MAIN, but the line says 9"));
        self::$db->exec('UPDATE grn_line SET accepted_units = 8 WHERE document_id = ?', [$r['id']]);
        self::assertSame([], self::found('booked at'));
    }

    public function testG5AnIncidentForEveryException(): void
    {
        $r = $this->posted();
        $id = (int) self::$db->value('SELECT id FROM incident WHERE document_id = ?', [$r['id']]);
        self::$db->exec('UPDATE incident SET units = 3 WHERE id = ?', [$id]);
        self::assertCount(1, self::found("incident {$id} (damaged) says 3 units verify at VERIFY, the line says 2 verify at VERIFY"));
        self::$db->exec('UPDATE incident SET units = 2 WHERE id = ?', [$id]);
        $row = (array) self::$db->one('SELECT * FROM incident WHERE id = ?', [$id]);
        self::$db->exec('DELETE FROM incident WHERE id = ?', [$id]);
        self::assertCount(1, self::found("receipt {$r['id']} line 1: its damaged units have no incident"));
        $cols = array_keys($row);
        self::$db->exec('INSERT INTO incident (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($row));
        self::assertSame([], ReceivingInvariants::check(self::$db));
    }

    public function testG6TheSellingModeIsItsLastLogRow(): void
    {
        $r = $this->posted();
        self::$db->exec("UPDATE item_selling_mode SET mode = 'In-Stock' WHERE sku_id = ?", [$r['sku']]);
        self::assertCount(1, self::found("item_selling_mode of item {$r['sku']} (In-Stock, previous none, version 1) is not its newest log row (From-Warehouse"));
        self::$db->exec("UPDATE item_selling_mode SET mode = 'From-Warehouse' WHERE sku_id = ?", [$r['sku']]);
        self::assertSame([], self::found('item_selling_mode'));
    }

    public function testG7ThePoReceiptsAreWhatThePostedReceiptsApplied(): void
    {
        $r = $this->posted();
        self::$db->transaction(fn () => $this->pos->applyReceipt($r['po'], [1 => 1], 'GRN-FORGED'));
        $po = (string) self::$db->value('SELECT number FROM document WHERE id = ?', [$r['po']]);
        self::assertCount(1, self::found("{$po} line 1: 9 units received, but its posted receipts applied 8"));
        self::$db->transaction(fn () => $this->pos->reverseReceipt($r['po'], [1 => 1], 'GRN-FORGED'));
        self::assertSame([], ReceivingInvariants::check(self::$db));
    }
}
