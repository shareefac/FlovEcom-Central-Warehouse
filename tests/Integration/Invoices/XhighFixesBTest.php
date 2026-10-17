<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Invoices;

use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Invoices\InvoiceMatch;
use CW\Invoices\InvoiceMath;
use CW\Invoices\SupplierInvoices;
use CW\Tests\Support\HookedStatement;
use CW\Tests\Support\TestDb;

final class XhighFixesBTest extends InvoicesTestCase
{
    public function testAcceptanceRefusesFiguresChangedBeforeTheReceiptLock(): void
    {
        $this->setting('invoices.check_price', 'true');
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Stale Acceptance Ltd');
        $sid = (int) $s['id'];
        $x = $this->orderedAndDelivered($desk, $buyer, $sid, 10, 1, '10.0000', 10);
        $d = $this->invoices->createFromReceipt($desk, $x['grn']->id)['document'];
        $d = $this->invoices->saveLines($desk, $d->id, $d->version,
            [SupplierInvoicesTest::lineOf($this->invoices->lines($d->id)[0], ['pack_price' => '11.0000'])]);
        $inv = $this->postWithTotals($desk, $d);
        $shown = $this->invoices->matchOf($inv->id);
        self::assertSame(1000, $shown['difference_e2']);
        $fingerprint = InvoiceMatch::fingerprint($shown);

        $dn = $this->returns->createDraft($desk, $sid, ['reason_code' => 'faulty', 'grn_id' => $x['grn']->id]);
        $dn = $this->returns->saveLines($desk, $dn->id, $dn->version,
            [['sku_id' => $x['sku'], 'qty' => 5, 'unit_cost' => '10.00', 'grn_document_id' => $x['grn']->id, 'grn_line_no' => 1]]);
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        $svc = new SupplierInvoices($conn, new Documents($conn, DocumentHandlers::all($conn)));
        HookedStatement::$before = ['SELECT id, status, doc_type, number FROM document WHERE id IN (' => function () use ($desk, $dn): void {
            self::assertSame('posted', $this->returns->post($desk, $dn->id, $dn->version)->status);
        }];
        try {
            $e = $this->refusedCode('match_changed', fn () => $svc->accept($desk, $inv->id, 'Price rise agreed', $fingerprint));
            self::assertSame(409, $e->httpStatus);
        } finally {
            HookedStatement::$before = [];
        }
        self::assertSame(['needs_review', '1', '60.00'], array_values(array_map('strval', $this->stored($inv->id))));
        self::assertNull(self::$db->value('SELECT accepted_at FROM supplier_invoice WHERE document_id = ?', [$inv->id]));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'invoice.accept' AND entity_id = ?", [(string) $inv->id]));

        $current = InvoiceMatch::fingerprint($this->invoices->matchOf($inv->id));
        self::assertNotSame($fingerprint, $current);
        $this->refusedCode('match_changed', fn () => $this->invoices->accept($desk, $inv->id, 'No figures supplied', ''));
        $this->invoices->accept($desk, $inv->id, 'Checked the return and the price', $current);
        self::assertSame('accepted', $this->stored($inv->id)['match_state']);
    }

    public function testAutomaticCreditsAllocateAcrossEveryMatchingInvoiceLine(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Split Credit Ltd');
        $sid = (int) $s['id'];
        $sku = self::makeSku('Split credit item');
        $grn = $this->delivered($desk, $sid, [['sku_id' => $sku, 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => '2.00']]);
        $d = $this->invoices->createFromReceipt($desk, $grn->id)['document'];
        $line = SupplierInvoicesTest::lineOf($this->invoices->lines($d->id)[0], ['packs' => 5]);
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [$line, $line]);
        $inv = $this->postWithTotals($desk, $d);

        foreach ([8, 2] as $i => $qty) {
            $dn = $this->returns->createDraft($desk, $sid, ['reason_code' => 'faulty', 'grn_id' => $grn->id, 'invoice_id' => $inv->id]);
            $dn = $this->returns->saveLines($desk, $dn->id, $dn->version,
                [['sku_id' => $sku, 'qty' => $qty, 'unit_cost' => '2.00', 'grn_document_id' => $grn->id, 'grn_line_no' => 1]]);
            $dn = $this->returns->post($desk, $dn->id, $dn->version);
            $cn = $this->invoices->createFromReturn($desk, $dn->id);
            $want = $i === 0 ? [['goods', 5, 1], ['goods', 3, 2]] : [['goods', 2, 2]];
            self::assertSame($want, array_map(static fn (array $l): array => [$l['kind'], $l['qty'], $l['credit_of_line_no']],
                $this->invoices->lines($cn->id)), 'allocate units before making any residual money-only credit');
            $this->postWithTotals($desk, $cn, $i === 0 ? '16.00' : '4.00', '0.00',
                ['invoice_number' => 'SPLIT-CN-' . $i, 'invoice_date' => self::day('-1 day')]);
            self::assertSame('matched', $this->stored($inv->id)['match_state']);
        }
    }

    public function testAutomaticCreditsPreservePackPricesAndReconcileRounding(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Rounding Credit Ltd');
        $sid = (int) $s['id'];
        // Whole packs preserve £2 / 3; partial packs need a residual of either sign.
        foreach ([['2.0000', 3000, '0.666667', '2000.00', null],
            ['2.0000', 2999, '0.666667', '1999.33', '-0.100000'],
            ['1.0000', 2999, '0.333333', '999.67', '0.100000']] as $i => [$price, $qty, $cost, $claim, $residual]) {
            $x = $this->orderedAndDelivered($desk, $buyer, $sid, 1000, 3, $price, 1000);
            $inv = $this->invoiceDelivery($desk, $x['grn']->id);
            $dn = $this->returns->createDraft($desk, $sid, ['reason_code' => 'faulty', 'grn_id' => $x['grn']->id, 'invoice_id' => $inv->id]);
            $dn = $this->returns->saveLines($desk, $dn->id, $dn->version,
                [['sku_id' => $x['sku'], 'qty' => $qty, 'unit_cost' => $cost, 'grn_document_id' => $x['grn']->id, 'grn_line_no' => 1]]);
            $dn = $this->returns->post($desk, $dn->id, $dn->version);
            self::assertSame('posted', $dn->status);
            $cn = $this->invoices->createFromReturn($desk, $dn->id);
            $lines = $this->invoices->lines($cn->id);
            self::assertSame(InvoiceMath::e2($claim), array_sum(array_map(static fn (array $l): int => InvoiceMath::e2((string) $l['amount']), $lines)));
            self::assertSame($qty, $lines[0]['qty']);
            if ($residual === null) {
                self::assertCount(1, $lines);
                self::assertSame([1000, 3, '2.0000'], [$lines[0]['packs'], $lines[0]['units_per_pack'], $lines[0]['pack_price']]);
            } else {
                self::assertSame(['goods', 'other'], array_column($lines, 'kind'));
                self::assertSame($residual, $lines[1]['amount']);
            }
            $posted = $this->postWithTotals($desk, $cn, $claim, '0.00',
                ['invoice_number' => 'ROUND-CN-' . $i, 'invoice_date' => self::day('-1 day')]);
            self::assertSame('posted', $posted->status, 'the exact paper total posts with the default tolerance');
        }
    }

    public function testAddingReturnLinesCountsExplicitDraftAllocations(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Allocated Return Ltd');
        $sid = (int) $s['id'];
        $sku = self::makeSku('Repeated receipt item');
        $line = ['sku_id' => $sku, 'packs' => 2, 'units_per_pack' => 1, 'pack_price' => '2.00'];
        $grn = $this->delivered($desk, $sid, [$line, $line]);
        $dn = $this->returns->createDraft($desk, $sid, ['reason_code' => 'faulty', 'grn_id' => $grn->id]);
        $first = $this->returns->addItem($desk, $dn->id, $dn->version, '', 2, null, '2.00', $sku)['document'];
        $second = $this->returns->addItem($desk, $dn->id, $first->version, '', 2, null, '2.00', $sku)['document'];
        self::assertSame([1, 2], array_column($this->returns->lines($dn->id), 'grn_line_no'));

        // Explicit links later in a submitted list reserve their quantities too.
        $dn = $this->returns->saveLines($desk, $dn->id, $second->version,
            [['sku_id' => $sku, 'qty' => 2, 'unit_cost' => '2.00'],
                ['sku_id' => $sku, 'qty' => 2, 'unit_cost' => '2.00', 'grn_document_id' => $grn->id, 'grn_line_no' => 1]]);
        self::assertSame([2, 1], array_column($this->returns->lines($dn->id), 'grn_line_no'));
        self::assertSame('posted', $this->returns->post($desk, $dn->id, $dn->version)->status);
        self::assertSame(0, $this->onHand($sku));
    }

    public function testAddingAnOrderCountsOnlyThatOrdersDraftLines(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Several Orders Ltd');
        $sid = (int) $s['id'];
        $a = $this->approvedPoFor($buyer, $sid, 10, 1, '1.0000');
        $b = $this->approvedPoFor($buyer, $sid, 10, 1, '2.0000');
        $d = $this->invoices->createDraft($desk, 'invoice', $sid);
        $d = $this->invoices->addOrder($desk, $d->id, $d->version, $a['doc']->id);
        $d = $this->invoices->addOrder($desk, $d->id, $d->version, $b['doc']->id);
        self::assertSame([[$a['doc']->id, 1, $a['sku'], 10], [$b['doc']->id, 1, $b['sku'], 10]],
            array_map(static fn (array $l): array => [$l['po_document_id'], $l['po_line_no'], $l['sku_id'], $l['qty']], $this->invoices->lines($d->id)));
        $this->refusedCode('nothing_to_invoice', fn () => $this->invoices->addOrder($desk, $d->id, $d->version, $b['doc']->id));
    }
}
