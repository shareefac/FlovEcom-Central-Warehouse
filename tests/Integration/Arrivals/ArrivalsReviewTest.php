<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Arrivals;

use CW\Arrivals\InvoiceSchedule;
use CW\Caller;
use CW\Documents\Document;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Invoices\SupplierInvoices;
use CW\Tests\Support\HookedStatement;
use CW\Tests\Support\TestDb;

/**
 * The Codex review of pack J (15 Oct 2026, on cd3868f; `runs/packj_codex_review.txt`), one test per finding, each written to fail first
 * (finding 6, the clock change, is on the screens: ArrivalScreensTest). Another session's write between a service's read and its write is
 * put there by HookedStatement on an extra connection.
 */
final class ArrivalsReviewTest extends ArrivalsTestCase
{
    /** A draft invoice "book as received" of $packs of a fresh item (the stamp answered, the copy attached), ready to be made final. @return array{doc: Document, sku: int, po: Document} */
    private function bookAsReceivedDraft(Caller $desk, Caller $buyer, int $supplierId, int $packs = 3): array
    {
        $x = $this->invoiceOfOrder($desk, $buyer, $supplierId, $packs);
        $d = $this->invoices->setReceiving($desk, $x['doc']->id, $x['doc']->version, 'received', [1 => 'digital']);
        $this->invoices->attach($desk, $d->id, $this->pdf(), 'invoice.pdf', 'supplier_invoice');
        return ['doc' => $this->docs->get($d->id)] + $x;
    }

    /** An extra connection whose statements run HookedStatement::$before. */
    private static function hooked(): \CW\Db
    {
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        return $conn;
    }

    /** The test's file store on another connection (its writes in that connection's transaction). */
    private function storeOn(\CW\Db $conn): FileStore
    {
        return new FileStore($conn, new \CW\Files\LocalFileStorage($this->dir . '/store'));
    }

    private function receiptsOf(int $invoiceId): int
    {
        return (int) self::$db->value('SELECT COUNT(*) FROM goods_receipt_invoice WHERE sinv_document_id = ?', [$invoiceId]);
    }

    /** Finding 1: two invoice lines of one item, each received on its own receipt line, bill their own line: nothing is short. */
    public function testTwoLinesOfOneItemAreReceivedLineByLine(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Two Lines Ltd');
        $sku = self::makeSku('Two lines item');
        $x = ['sku' => $sku];
        $line = ['kind' => 'goods', 'sku_id' => $sku, 'packs' => 5, 'units_per_pack' => 1, 'pack_price' => '4.0000', 'supplier_code' => null, 'purchase_unit' => 'each',
            'entry' => 'manual', 'description' => null];
        $d = $this->invoices->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => 'TWO-1', 'invoice_date' => self::day('-1 day')]);
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [$line, $line]); // the same item twice (two boxes of it billed apart)
        $d = $this->invoices->saveHeader($desk, $d->id, $d->version, ['net_total' => '40.00', 'vat_total' => '0.00']);
        $p = $this->invoices->submit($desk, $d->id, $d->version);
        self::assertSame([10, [5, 5]], [$this->incoming($x['sku']), array_column($this->arrivalRows($p->id), 'units')]);
        $g = $this->arrivals->receive($desk, $p->id);
        self::assertSame([[1, $x['sku'], 5], [2, $x['sku'], 5]], array_map(static fn (array $l): array => [(int) $l['line_no'], (int) $l['sku_id'], (int) $l['packs']],
            $this->grns->lines($g->id)));
        $this->ready($desk, $g->id);
        $g = $this->post($desk, $g->id);
        self::assertSame([0, 10], [$this->incoming($x['sku']), $this->onHand($x['sku'])]);
        self::assertSame([[1, 1], [2, 2]], array_map(static fn (array $r): array => [(int) $r['line_no'], (int) $r['grn_line_no']],
            self::$db->all('SELECT line_no, grn_line_no FROM invoice_arrival WHERE sinv_document_id = ? ORDER BY line_no', [$p->id])), 'each invoice line its own receipt line');
        self::assertSame([], $this->arrivals->missingOf($g->id), 'nothing is short');
        self::assertSame([0, 0], array_map('intval', array_values((array) self::$db->one('SELECT match_lines_off, match_difference FROM supplier_invoice WHERE document_id = ?',
            [$p->id]))), 'no line off: each bills the units of its own receipt line');
        self::assertSame([], $this->invoices->notInvoiced((int) $s['id']), 'both receipt lines are invoiced');
    }

    /** Finding 2: re-timed between the version check of "Make it final" and the receipt's booking: no receipt, no stock. */
    public function testAStaleMakeItFinalBooksNoReceipt(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Stale Ltd');
        $x = $this->bookAsReceivedDraft($desk, $buyer, (int) $s['id']);
        $id = $x['doc']->id;
        $tomorrow = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 day');
        $conn = self::hooked();
        HookedStatement::$before = ['SELECT d.id, d.number, d.status, d.version, d.external_ref, d.doc_date, si.supplier_id, si.receive_mode' => function () use ($desk, $id, $tomorrow): void {
            $this->invoices->schedule($desk, $id, $this->docs->get($id)->version, $tomorrow); // the person sets a time meanwhile
        }];
        try {
            $si = new SupplierInvoices($conn, new Documents($conn, DocumentHandlers::all($conn)), fn (): FileStore => $this->storeOn($conn));
            $this->refusedCode('version_conflict', fn () => $si->submit($desk, $id, $x['doc']->version));
        } finally {
            HookedStatement::$before = [];
        }
        self::assertSame(['draft', 0, 0, null], [$this->docStatus($id), $this->onHand($x['sku']), $this->receiptsOf($id), $this->invoices->record($id)['submit_receipt_id']],
            'nothing booked: the invoice waits for its new time');
        self::assertNotNull($this->invoices->record($id)['submit_at']);
    }

    /** Finding 3: a person who may book receipts but not make invoices final: refused before the receipt is booked. */
    public function testMakeItFinalWithoutTheInvoiceRightBooksNoReceipt(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Rights Ltd');
        $x = $this->bookAsReceivedDraft($desk, $buyer, (int) $s['id']);
        $goodsIn = $this->staffUser('goods_in');
        self::assertTrue(Documents::mayPost(\CW\Staff\StaffRoles::active(self::$db, (int) $goodsIn->staffUserId), 'GRN'), 'goods in books receipts');
        $this->refusedCode('role_not_allowed', fn () => $this->invoices->submit($goodsIn, $x['doc']->id, $x['doc']->version));
        self::assertSame(['draft', 0, 0], [$this->docStatus($x['doc']->id), $this->onHand($x['sku']), $this->receiptsOf($x['doc']->id)], 'nothing booked');
    }

    /** Finding 4: an attempt overtaken by a new time is skipped; the new time is not marked failed and is kept. */
    public function testAnAttemptOvertakenByANewTimeLeavesTheNewTimeAlone(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Overtaken Ltd');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 4);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $d = $this->invoices->schedule($desk, $x['doc']->id, $x['doc']->version, $now->modify('+5 minutes'));
        $tomorrow = $now->modify('+1 day');
        $conn = self::hooked();
        HookedStatement::$before = ['SELECT * FROM supplier_invoice WHERE document_id' => function () use ($desk, $d, $tomorrow): void {
            $this->invoices->schedule($desk, $d->id, $this->docs->get($d->id)->version, $tomorrow); // re-timed after the job read it as due
        }];
        try {
            $r = (new InvoiceSchedule($conn, fn (): FileStore => $this->storeOn($conn)))->runDue($now->modify('+10 minutes'));
        } finally {
            HookedStatement::$before = [];
        }
        self::assertSame([[], [], [$d->id]], [$r['posted'], $r['failed'], $r['skipped']]);
        $rec = $this->invoices->record($d->id);
        self::assertSame([null, $tomorrow->format('Y-m-d H:i')], [$rec['submit_failed_at'], substr((string) $rec['submit_at'], 0, 16)], 'the new time stands');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'invoice.submit_failed' AND entity_id = ?", [(string) $d->id]));
        self::assertSame([$d->id], $this->job()->runDue($tomorrow->modify('+1 minute'))['posted'], 'made final at its new time');
    }

    /** Finding 5: a receipt of a large invoice with exceptions on every line books its stock (more than 2,000 ledger rows). */
    public function testALargeReceiptFromAnInvoiceIsBookedWhole(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Large Ltd');
        $n = 667; // 667 on hand + 667 set aside + 667 out of Incoming = 2,001 rows
        $lines = [];
        $skus = [];
        for ($i = 0; $i < $n; $i++) {
            $skus[] = $sku = self::makeSku('Large item ' . $i);
            $lines[] = ['kind' => 'goods', 'sku_id' => $sku, 'packs' => 2, 'units_per_pack' => 1, 'pack_price' => '1.0000', 'supplier_code' => null,
                'purchase_unit' => 'each', 'entry' => 'manual', 'description' => null];
        }
        $d = $this->invoices->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => 'LARGE-1', 'invoice_date' => self::day('-1 day')]);
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, $lines);
        $d = $this->invoices->saveHeader($desk, $d->id, $d->version, ['net_total' => number_format(2 * $n, 2, '.', ''), 'vat_total' => '0.00']);
        $p = $this->invoices->submit($desk, $d->id, $d->version);
        self::assertSame(2 * $n, (int) self::$db->value('SELECT SUM(incoming) FROM stock_balance'));
        $g = $this->arrivals->receive($desk, $p->id);
        $bench = [];
        for ($i = 1; $i <= $n; $i++) {
            $bench[$i] = ['damaged_units' => '1'];
        }
        $this->ready($desk, $g->id, $bench);
        $g = $this->post($desk, $g->id);
        self::assertSame('posted', $g->status);
        self::assertSame([0, $n], [(int) self::$db->value('SELECT SUM(incoming) FROM stock_balance'), (int) self::$db->value(
            "SELECT SUM(b.on_hand) FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id AND w.code = 'MAIN'")]);
        self::assertSame(3 * $n, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE document_id = ?', [$g->id]));
    }

    /** Finding 7: an item whose goods are on their way but not sold before they arrive is not merged either. */
    public function testAnItemOnItsWayUnsoldIsNotMerged(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Unsold Ltd');
        $this->arrivals->setPrefs($buyer, (int) $s['id'], false, 'count');
        $site = $this->site();
        $lead = $this->staffUser('mapping_lead');
        $keep = $this->item('legacy', 3);
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 4);
        self::$db->exec("UPDATE sku SET sell_policy = 'legacy' WHERE id = ?", [$x['sku']]);
        $this->listing($site, 'UK', $keep);
        $dup = $this->listing($site, 'UD', $x['sku']);
        $p = $this->invoices->submit($desk, $x['doc']->id, $x['doc']->version);
        self::assertSame([0, [['state' => 'open', 'units' => 4, 'sellable' => 0, 'grn' => null]]], [$this->incoming($x['sku']), $this->arrivalRows($p->id)]);
        self::refused(409, 'incoming_on_item', fn () => $this->decide($lead, 'merge_skus', $dup, ['sku_id' => $keep, 'merge_from_sku_id' => $x['sku']]));
        self::assertNull(self::$db->value('SELECT merged_into_sku_id FROM sku WHERE id = ?', [$x['sku']]));
        // Received, it merges.
        $g = $this->arrivals->receive($desk, $p->id);
        $this->ready($desk, $g->id);
        $this->post($desk, $g->id);
        self::assertSame('applied', $this->decide($lead, 'merge_skus', $dup, ['sku_id' => $keep, 'merge_from_sku_id' => $x['sku']])['state']);
    }
}
