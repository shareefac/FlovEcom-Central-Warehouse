<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Invoices;

use CW\Caller;
use CW\CwException;
use CW\Documents\Document;
use CW\Invoices\ErpApiReader;
use CW\Invoices\ErpInvoiceCopy;
use CW\Invoices\InvoiceInvariants;
use CW\Invoices\SupplierInvoiceHandler;
use CW\Movements;
use CW\Tests\Support\ErpFakeTransport;

/**
 * The copies of ERPNext purchase invoices (CW-917; docs/decisions.md EC1-EC16), against ERPNext's REST API answered from fixtures (no
 * network) and real relayed stock: the main switch off reads and writes nothing; a dry run writes nothing; an invoice is copied whole (its
 * supplier, number, date, lines, VAT) as a posted supplier invoice that books NO stock (the ledger, the balances, the value events and the
 * goods on their way unchanged), matched against what the relay booked; a row CW cannot place makes the whole invoice wait (outside
 * Approvals) and the next run copies it once it can; an unknown supplier is made as a draft from ERPNext (or the invoice waits); the
 * supplier's last price comes from a certain line; a debit note becomes a credit note on the copy; a cancellation cancels the copy (its
 * credit notes first); an amendment replaces its original; a changed invoice is copied again, never twice; reading the same invoice again
 * changes nothing; the nightly V8 finds a copy that booked stock; the handler and the services refuse what a copy may not do. The full
 * invariant check runs after every test (InvoicesTestCase).
 */
final class ErpInvoiceCopyTest extends InvoicesTestCase
{
    private ErpFakeTransport $erp;
    private Caller $site;
    private int $erpSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->erp = new ErpFakeTransport();
        $this->site = $this->site('vapeandgo');
        $this->grant($this->site, 'goods_in', 'supplier_return');
        $p = $this->people('ERP Supplies');
        $this->erpSupplier = (int) $p['supplier']['id'];
        self::$db->exec('UPDATE supplier SET erp_name = ? WHERE id = ?', ['ERP Supplies Ltd', $this->erpSupplier]);
    }

    // ------------------------------------------------------------------------------------------
    // The switch, the dry run

    public function testWithTheSwitchOffNothingIsReadAndNothingWritten(): void
    {
        $this->erp->invoices['PI-1'] = $this->pi('PI-1', [['item' => 'A', 'qty' => 2, 'rate' => 5]]);
        self::assertSame(['state' => 'off'], $this->copy()->run($this->reader(), true));
        self::assertSame([], $this->erp->calls, 'ERPNext is not read at all');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM erp_pi') + (int) self::$db->value('SELECT COUNT(*) FROM erp_copy_run'));
    }

    public function testADryRunWritesNothingAndSaysWhatARealRunWouldDo(): void
    {
        $this->on();
        $skuA = $this->linked('VA', 'A');
        $this->relay('PI-1', [['line_index' => 0, 'variant_id' => 'VA', 'qty' => 2]]);
        $this->erp->invoices['PI-1'] = $this->pi('PI-1', [['item' => 'A', 'qty' => 2, 'rate' => 5]]);
        $this->erp->invoices['PI-2'] = $this->pi('PI-2', [['item' => 'NOPE', 'qty' => 1, 'rate' => 3]], ['modified' => '2026-10-02 10:00:00.000000']);
        $this->erp->items['NOPE'] = ['is_stock_item' => 1, 'custom_variant_id' => 'V-NOPE'];
        $before = $this->tableCounts();
        $r = $this->copy()->run($this->reader(), false);
        self::assertTrue($r['dry_run']);
        self::assertSame(['copied' => 1, 'waiting' => 1], self::sorted($r['counts']));
        self::assertSame(['no_item' => 1], $r['problems']);
        self::assertSame($before, $this->tableCounts(), 'a dry run writes nothing: no copy, no erp_pi row, no run row, no audit row, no number');
        self::assertSame(2, $this->onHand($skuA), 'the relay\'s stock only');
        $lines = ErpInvoiceCopy::logLines($r);
        self::assertStringStartsWith('DRY RUN (nothing written) read=2 would_copy=1 waiting=1', $lines[0]);
        self::assertStringContainsString('PI-2: waiting (no_item, row 1: NOPE (variant V-NOPE))', $lines[1]);
    }

    // ------------------------------------------------------------------------------------------
    // The copy

    public function testAnInvoiceIsCopiedWholeAndBooksNoStockAtAll(): void
    {
        $this->on();
        $skuA = $this->linked('VA', 'A');
        $skuB = $this->linked('VB', 'B', 10); // a pack of 10 on the website: 3 packs are 30 central units
        $this->relay('PI-1', [['line_index' => 0, 'variant_id' => 'VA', 'qty' => 4], ['line_index' => 1, 'variant_id' => 'VB', 'qty' => 3]]);
        $this->erp->items['FREIGHT'] = ['is_stock_item' => 0, 'custom_variant_id' => null];
        $this->erp->invoices['PI-1'] = $this->pi('PI-1', [['item' => 'A', 'qty' => 4, 'rate' => 2.5], ['item' => 'FREIGHT', 'qty' => 1, 'rate' => 7.25, 'item_name' => 'Freight'],
            ['item' => 'B', 'qty' => 3, 'rate' => 12.3456]], ['due_date' => '2026-10-31']);
        $ledger = $this->ledgerCount();
        $onHand = [$this->onHand($skuA), $this->onHand($skuB)];
        $r = $this->copy()->run($this->reader(), true);
        self::assertSame(['copied' => 1], $r['counts']);
        self::assertSame($ledger, $this->ledgerCount(), 'a copy books no stock');
        self::assertSame($onHand, [$this->onHand($skuA), $this->onHand($skuB)]);
        self::assertSame([4, 30], $onHand, 'what the relay booked, once');
        $p = $this->erpRow('PI-1');
        self::assertSame(['copied', null], [$p['state'], $p['problem']]);
        $d = (array) self::$db->one('SELECT d.*, si.origin, si.receive_mode, si.net_total, si.vat_total, si.gross_total, si.supplier_id, si.due_date, si.match_state '
            . 'FROM document d JOIN supplier_invoice si ON si.document_id = d.id WHERE d.id = ?', [(int) $p['document_id']]);
        self::assertSame(['SINV', 'posted', 'erpnext', null, 'B-PI-1', '2026-10-01', '2026-10-31', $this->erpSupplier], [$d['doc_type'], $d['status'], $d['origin'],
            $d['receive_mode'], $d['external_ref'], $d['doc_date'], $d['due_date'], (int) $d['supplier_id']]);
        self::assertSame([null, 'system:erp_copy', null, 'system:erp_copy', 'not_required'], [$d['created_by'], $d['created_actor'], $d['posted_by'], $d['posted_actor'],
            $d['review_state']], 'made and posted by the job, no person, no review');
        // 4 x 2.50 + 7.25 + 3 x 12.3456 (= 37.04 to the penny in ERPNext) = 54.29 without VAT; VAT 20 % = 10.86.
        self::assertSame(['54.29', '10.86', '65.15'], [$d['net_total'], $d['vat_total'], $d['gross_total']]);
        $lines = self::$db->all('SELECT sl.kind, dl.sku_id, dl.qty, sl.packs, sl.units_per_pack, sl.pack_price, dl.amount, sl.grn_document_id, sl.po_document_id, sl.purchase_unit '
            . 'FROM sinv_line sl JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no WHERE sl.document_id = ? ORDER BY sl.line_no',
            [(int) $p['document_id']]);
        self::assertSame([
            ['kind' => 'goods', 'sku_id' => $skuA, 'qty' => 4, 'packs' => 4, 'units_per_pack' => 1, 'pack_price' => '2.5000', 'amount' => '10.000000', 'grn_document_id' => null,
                'po_document_id' => null, 'purchase_unit' => 'Nos'],
            ['kind' => 'freight', 'sku_id' => null, 'qty' => null, 'packs' => null, 'units_per_pack' => 1, 'pack_price' => null, 'amount' => '7.250000', 'grn_document_id' => null,
                'po_document_id' => null, 'purchase_unit' => 'each'],
            ['kind' => 'goods', 'sku_id' => $skuB, 'qty' => 30, 'packs' => 3, 'units_per_pack' => 10, 'pack_price' => '12.3456', 'amount' => '37.040000', 'grn_document_id' => null,
                'po_document_id' => null, 'purchase_unit' => 'Nos'],
        ], $lines);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM invoice_value_event'), 'never the cost: no value event');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM invoice_arrival'), 'nothing on its way');
        self::assertSame(0, $this->documentTasks(), 'nothing for Approvals');
        self::assertSame('matched', $d['match_state'], 'matched against what the relay booked, never "no delivery"');
        $m = $this->invoices->matchOf((int) $p['document_id']);
        self::assertSame([4, 30], [$m['lines'][1]['available'], $m['lines'][3]['available']]);
        self::assertSame(['relay', 'relay'], self::$db->column('SELECT via FROM erp_pi_line WHERE erp_pi_id = ? AND is_stock = 1 ORDER BY idx', [(int) $p['id']]));
        self::assertNotNull(self::$db->value("SELECT 1 FROM audit_log WHERE action = 'invoice.copy' AND actor = 'system:erp_copy' AND entity_id = ?", [(string) $p['document_id']]));
        $run = (array) self::$db->one('SELECT * FROM erp_copy_run ORDER BY id DESC LIMIT 1');
        self::assertSame(['done', '2026-10-01 10:00:00.000000', 'PI-1'], [$run['status'], $run['cursor_to'], $run['cursor_name']]);

        // The next run reads what comes after the cursor: nothing. Read again from the start (--from): unchanged, never copied twice.
        $docs = $this->docCount();
        $r2 = $this->copy()->run($this->reader(), true);
        self::assertSame([0, []], [$r2['read'], $r2['counts']]);
        $list = array_values(array_filter($this->erp->calls, static fn (array $c): bool => $c['path'] === '/api/resource/Purchase Invoice'));
        self::assertSame([['modified', '>', '2026-10-01 10:00:00.000000'], ['name', '>', 'PI-1']], json_decode((string) end($list)['query']['or_filters'], true),
            'the second run reads after the cursor');
        $r3 = $this->copy()->run($this->reader(), true, '2026-09-01');
        self::assertSame(['unchanged' => 1], $r3['counts']);
        self::assertSame($docs, $this->docCount());
    }

    public function testARowCwCannotPlaceMakesTheWholeInvoiceWaitUntilItCan(): void
    {
        $this->on();
        $skuA = $this->linked('VA', 'A');
        $this->erp->items['C'] = ['is_stock_item' => 1, 'custom_variant_id' => 'VC'];
        $this->erp->invoices['PI-1'] = $this->pi('PI-1', [['item' => 'A', 'qty' => 2, 'rate' => 5], ['item' => 'C', 'qty' => 1, 'rate' => 4]]);
        $r = $this->copy()->run($this->reader(), true);
        self::assertSame(['waiting' => 1], $r['counts']);
        $p = $this->erpRow('PI-1');
        self::assertSame(['waiting', 'no_item', 2, null], [$p['state'], $p['problem'], (int) $p['problem_line'], $p['document_id']]);
        self::assertSame(0, $this->docCount(), 'never half copied');
        self::assertSame(0, $this->documentTasks(), 'a waiting invoice is not in Approvals');
        $waiting = (new ErpInvoiceCopy(self::$db))->list();
        self::assertSame(['PI-1'], array_column($waiting, 'name'));
        // The website product is matched: the next run copies it from what CW kept (here ERPNext no longer lists it).
        $skuC = self::makeSku('Item C');
        $this->listing($this->site, 'VC', $skuC);
        unset($this->erp->invoices['PI-1']);
        $r2 = $this->copy()->run($this->reader(), true);
        self::assertSame(1, $r2['retried']);
        self::assertSame('copied', $this->erpRow('PI-1')['state']);
        self::assertSame([$skuA, $skuC], array_map('intval', self::$db->column('SELECT dl.sku_id FROM document_line dl WHERE dl.document_id = ? AND dl.sku_id IS NOT NULL ORDER BY dl.line_no',
            [(int) $this->erpRow('PI-1')['document_id']])));
        self::assertSame('within_tolerance', self::$db->value('SELECT match_state FROM supplier_invoice WHERE document_id = ?', [(int) $this->erpRow('PI-1')['document_id']]),
            'its rows are "not booked in CW" (the relay sent nothing): within, never To match');
        self::assertSame([], $this->invoices->toMatch());
    }

    public function testUnitsThatDifferFromWhatTheRelayBookedMakeItWait(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        // ERPNext bought 2 boxes of 12 (stock_qty 24); the add-on sent 2 (the purchase unit): CW booked 2.
        $this->relay('PI-1', [['line_index' => 0, 'variant_id' => 'VA', 'qty' => 2]]);
        $this->erp->invoices['PI-1'] = $this->pi('PI-1', [['item' => 'A', 'qty' => 2, 'cf' => 12, 'rate' => 30, 'uom' => 'Box']]);
        $this->copy()->run($this->reader(), true);
        $p = $this->erpRow('PI-1');
        self::assertSame(['waiting', 'units_differ', '24 / relay 2'], [$p['state'], $p['problem'], $p['problem_detail']]);
    }

    public function testAnUnknownSupplierIsMadeAsADraftFromErpnextOrTheInvoiceWaits(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        $this->setting(ErpInvoiceCopy::CREATE_SUPPLIERS, 'false');
        $this->erp->invoices['PI-1'] = $this->pi('PI-1', [['item' => 'A', 'qty' => 1, 'rate' => 5]], ['supplier' => 'New Vapes Co', 'supplier_name' => 'New Vapes Company']);
        $this->copy()->run($this->reader(), true);
        self::assertSame(['waiting', 'unknown_supplier'], [$this->erpRow('PI-1')['state'], $this->erpRow('PI-1')['problem']]);
        self::assertNull(self::$db->value('SELECT id FROM supplier WHERE erp_name = ?', ['New Vapes Co']));
        $this->setting(ErpInvoiceCopy::CREATE_SUPPLIERS, 'true');
        $r = $this->copy()->run($this->reader(), true);
        self::assertSame(1, $r['suppliers_created']);
        $s = (array) self::$db->one('SELECT * FROM supplier WHERE erp_name = ?', ['New Vapes Co']);
        self::assertSame(['New Vapes Company', 'draft', null, 'system:erp_copy'], [$s['name'], $s['status'], $s['created_by'], $s['created_actor']]);
        self::assertStringContainsString('ERPNext supplier: New Vapes Co', (string) $s['notes']);
        self::assertSame((int) $s['id'], (int) self::$db->value('SELECT supplier_id FROM supplier_invoice WHERE document_id = ?', [(int) $this->erpRow('PI-1')['document_id']]));
    }

    public function testACertainLineSetsTheSuppliersLastPriceAndAnUncertainOneDoesNot(): void
    {
        $this->on();
        $skuA = $this->linked('VA', 'A');
        $skuB = $this->linked('VB', 'B');
        $this->relay('PI-1', [['line_index' => 0, 'variant_id' => 'VA', 'qty' => 6]]); // B's stock not booked by the relay: not certain
        $this->erp->invoices['PI-1'] = $this->pi('PI-1', [['item' => 'A', 'qty' => 6, 'rate' => 1.95, 'uom' => 'Bottle'], ['item' => 'B', 'qty' => 2, 'rate' => 8]]);
        $r = $this->copy()->run($this->reader(), true);
        self::assertSame(1, $r['prices']);
        $si = (array) self::$db->one('SELECT * FROM supplier_item WHERE supplier_id = ? AND sku_id = ?', [$this->erpSupplier, $skuA]);
        self::assertSame(['1.9500', '2026-10-01', 'invoice', 1, 'Bottle', 0, 'system:erp_copy'], [$si['last_pack_price'], $si['last_price_on'], $si['last_price_source'],
            (int) $si['units_per_pack'], $si['purchase_unit'], (int) $si['is_preferred'], $si['created_actor']]);
        $price = (array) self::$db->one('SELECT * FROM supplier_item_price WHERE supplier_item_id = ?', [(int) $si['id']]);
        self::assertSame(['invoice', 'PI-1', (int) $this->erpRow('PI-1')['document_id']], [$price['source'], $price['source_ref'], (int) $price['document_id']]);
        self::assertNull(self::$db->value('SELECT id FROM supplier_item WHERE supplier_id = ? AND sku_id = ?', [$this->erpSupplier, $skuB]));
        // Off: the price stays on the copy only.
        $this->setting(ErpInvoiceCopy::UPDATE_PRICES, 'false');
        $this->relay('PI-2', [['line_index' => 0, 'variant_id' => 'VA', 'qty' => 6]]);
        $this->erp->invoices['PI-2'] = $this->pi('PI-2', [['item' => 'A', 'qty' => 6, 'rate' => 2.10, 'uom' => 'Bottle']], ['modified' => '2026-10-03 10:00:00.000000',
            'bill_date' => '2026-10-03', 'posting_date' => '2026-10-03']);
        self::assertSame(0, $this->copy()->run($this->reader(), true)['prices']);
        self::assertSame('1.9500', self::$db->value('SELECT last_pack_price FROM supplier_item WHERE id = ?', [(int) $si['id']]));
    }

    // ------------------------------------------------------------------------------------------
    // Debit notes, cancellations, amendments, changes

    public function testADebitNoteBecomesACreditNoteOnTheCopyAndACancellationCancelsBothCreditNoteFirst(): void
    {
        $this->on();
        $skuA = $this->linked('VA', 'A');
        $this->relay('PI-1', [['line_index' => 0, 'variant_id' => 'VA', 'qty' => 10]]);
        $this->erp->invoices['PI-1'] = $this->pi('PI-1', [['item' => 'A', 'qty' => 10, 'rate' => 3]]);
        $this->copy()->run($this->reader(), true);
        $inv = (int) $this->erpRow('PI-1')['document_id'];
        // The debit note: 2 returned, the relay took them out (supplier_return, its own document name).
        $this->relay('PI-1-RET', [['line_index' => 0, 'variant_id' => 'VA', 'qty' => 2]], 'supplier_return');
        $this->erp->invoices['PI-1-RET'] = $this->pi('PI-1-RET', [['item' => 'A', 'qty' => -2, 'rate' => 3, 'of' => 'PI-1-row1']], ['is_return' => 1, 'return_against' => 'PI-1',
            'modified' => '2026-10-05 10:00:00.000000', 'bill_no' => 'CN-7', 'bill_date' => '2026-10-05', 'posting_date' => '2026-10-05']);
        $ledger = $this->ledgerCount();
        $r = $this->copy()->run($this->reader(), true);
        self::assertSame(['copied' => 1], $r['counts']);
        $cn = (int) $this->erpRow('PI-1-RET')['document_id'];
        $h = (array) self::$db->one('SELECT d.doc_type, d.status, d.external_ref, si.kind, si.origin, si.credits_document_id, si.net_total, si.vat_total FROM document d '
            . 'JOIN supplier_invoice si ON si.document_id = d.id WHERE d.id = ?', [$cn]);
        self::assertSame(['SCN', 'posted', 'CN-7', 'credit_note', 'erpnext', $inv, '6.00', '1.20'], [$h['doc_type'], $h['status'], $h['external_ref'], $h['kind'],
            $h['origin'], (int) $h['credits_document_id'], $h['net_total'], $h['vat_total']], 'a credit note, never a CW debit note (which would book stock)');
        self::assertSame([1, 2], array_map('intval', array_values((array) self::$db->one('SELECT sl.credit_of_line_no, dl.qty FROM sinv_line sl JOIN document_line dl ON dl.document_id = sl.document_id '
            . 'AND dl.line_no = sl.line_no WHERE sl.document_id = ?', [$cn]))));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'DN'"));
        self::assertSame($ledger, $this->ledgerCount());
        self::assertSame(8, $this->onHand($skuA), 'the relay\'s 10 in and 2 out, nothing else');
        self::assertSame(8, $this->invoices->matchOf($inv)['lines'][1]['units'], 'the invoice bills 8 once credited');

        // ERPNext cancels both (the debit note is read later than the invoice): the credit note's copy is cancelled first, then the invoice's.
        $this->erp->invoices['PI-1']['docstatus'] = 2;
        $this->erp->invoices['PI-1']['modified'] = '2026-10-06 09:00:00.000000';
        $this->erp->invoices['PI-1-RET']['docstatus'] = 2;
        $this->erp->invoices['PI-1-RET']['modified'] = '2026-10-06 10:00:00.000000';
        $r2 = $this->copy()->run($this->reader(), true);
        self::assertSame(['reversed' => 1, 'unchanged' => 1], self::sorted($r2['counts']));
        self::assertSame(['reversed', 'reversed'], [$this->erpRow('PI-1')['state'], $this->erpRow('PI-1-RET')['state']]);
        self::assertSame(['reversed', 'reversed'], [self::$db->value('SELECT status FROM document WHERE id = ?', [$inv]), self::$db->value('SELECT status FROM document WHERE id = ?', [$cn])]);
        $revs = self::$db->all('SELECT reverses_id, reason_code, created_actor FROM document WHERE reverses_id IN (?, ?) ORDER BY id', [$inv, $cn]);
        self::assertSame([[$cn, 'erp_cancelled', 'system:erp_copy'], [$inv, 'erp_cancelled', 'system:erp_copy']],
            array_map(static fn (array $x): array => [(int) $x['reverses_id'], $x['reason_code'], $x['created_actor']], $revs), 'the credit note first');
        self::assertSame($ledger, $this->ledgerCount(), 'a cancellation books nothing either');
        self::assertSame(0, $this->documentTasks());
    }

    public function testAnAmendmentCancelsTheCopyOfItsOriginalThenIsCopied(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        $this->erp->invoices['PI-9'] = $this->pi('PI-9', [['item' => 'A', 'qty' => 5, 'rate' => 2]]);
        $this->copy()->run($this->reader(), true);
        $first = (int) $this->erpRow('PI-9')['document_id'];
        // Amended in ERPNext: PI-9 cancelled, PI-9-1 made from it with the same supplier's number. Only the amendment is read here.
        $this->erp->invoices['PI-9-1'] = $this->pi('PI-9-1', [['item' => 'A', 'qty' => 6, 'rate' => 2]], ['amended_from' => 'PI-9', 'bill_no' => 'B-PI-9',
            'modified' => '2026-10-07 10:00:00.000000']);
        $this->copy()->run($this->reader(), true);
        self::assertSame(['reversed', 'copied'], [$this->erpRow('PI-9')['state'], $this->erpRow('PI-9-1')['state']]);
        self::assertSame('reversed', self::$db->value('SELECT status FROM document WHERE id = ?', [$first]));
        $second = (int) $this->erpRow('PI-9-1')['document_id'];
        self::assertSame(['B-PI-9', 'posted', 6], [self::$db->value('SELECT external_ref FROM document WHERE id = ?', [$second]),
            self::$db->value('SELECT status FROM document WHERE id = ?', [$second]), (int) self::$db->value('SELECT SUM(qty) FROM document_line WHERE document_id = ?', [$second])]);
        self::assertSame('PI-9', $this->erpRow('PI-9-1')['amended_from']);
    }

    public function testAnInvoiceChangedAfterItWasCopiedIsCopiedAgainNeverTwice(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        $this->erp->invoices['PI-3'] = $this->pi('PI-3', [['item' => 'A', 'qty' => 5, 'rate' => 2]]);
        $this->copy()->run($this->reader(), true);
        $first = (int) $this->erpRow('PI-3')['document_id'];
        $this->erp->invoices['PI-3'] = $this->pi('PI-3', [['item' => 'A', 'qty' => 5, 'rate' => 2.2]], ['modified' => '2026-10-08 10:00:00.000000']);
        $r = $this->copy()->run($this->reader(), true);
        self::assertSame(['copied' => 1], $r['counts']);
        $p = $this->erpRow('PI-3');
        self::assertNotSame($first, (int) $p['document_id']);
        self::assertSame(['reversed', 'erp_changed'], [self::$db->value('SELECT status FROM document WHERE id = ?', [$first]),
            self::$db->value('SELECT reason_code FROM document WHERE reverses_id = ?', [$first])]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM supplier_invoice si JOIN document d ON d.id = si.document_id AND d.status = 'posted' WHERE si.origin = 'erpnext'"),
            'one live copy');
        self::assertSame('11.00', self::$db->value('SELECT net_total FROM supplier_invoice WHERE document_id = ?', [(int) $p['document_id']]));
    }

    public function testTheRelayBookingAfterTheCopyIsKeptAndTheMatchWorkedOutAgain(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        $this->erp->invoices['PI-4'] = $this->pi('PI-4', [['item' => 'A', 'qty' => 3, 'rate' => 2]]);
        $this->copy()->run($this->reader(), true);
        $doc = (int) $this->erpRow('PI-4')['document_id'];
        self::assertSame('within_tolerance', self::$db->value('SELECT match_state FROM supplier_invoice WHERE document_id = ?', [$doc]));
        self::assertSame(['not_booked'], $this->invoices->matchOf($doc)['lines'][1]['why']);
        $this->relay('PI-4', [['line_index' => 0, 'variant_id' => 'VA', 'qty' => 3]]);
        $r = $this->copy()->run($this->reader(), true);
        self::assertSame(1, $r['relay_rechecked']);
        self::assertSame('matched', self::$db->value('SELECT match_state FROM supplier_invoice WHERE document_id = ?', [$doc]));
        self::assertSame(3, (int) self::$db->value('SELECT relay_units FROM erp_pi_line WHERE erp_pi_id = ? AND idx = 1', [(int) $this->erpRow('PI-4')['id']]));
    }

    /** The Codex review of 0ccc04c, MEDIUM 3: --limit goes on from the last invoice read, even through invoices of the same time. */
    public function testALimitedRunGoesOnFromTheLastInvoiceItRead(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        foreach (['PI-T1', 'PI-T2', 'PI-T3'] as $i => $name) {
            $this->erp->invoices[$name] = $this->pi($name, [['item' => 'A', 'qty' => $i + 1, 'rate' => 1]]); // the same modified time
        }
        $names = [];
        for ($k = 0; $k < 4; $k++) {
            foreach ($this->copy()->run($this->reader(), true, null, 1)['outcomes'] as $o) {
                $names[] = $o['name'];
            }
        }
        self::assertSame(['PI-T1', 'PI-T2', 'PI-T3'], $names, 'one a run, each once; the fourth run reads nothing');
        self::assertSame(3, $this->docCount());
    }

    /**
     * The Codex review of d8af941, MEDIUM 2: a run reads at most erp_copy.per_run invoices and keeps its cursor; the next run goes on from
     * there, the cron's too after a --from back-fill (an earlier date's included), and nothing is copied twice.
     */
    public function testARunReadsAtMostThePerRunSettingAndTheNextGoesOn(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        $this->setting(ErpInvoiceCopy::PER_RUN, '2');
        foreach (['PI-P1', 'PI-P2', 'PI-P3'] as $i => $name) {
            $this->erp->invoices[$name] = $this->pi($name, [['item' => 'A', 'qty' => $i + 1, 'rate' => 1]], ['modified' => '2026-10-0' . ($i + 1) . ' 10:00:00.000000']);
        }
        $r1 = $this->copy()->run($this->reader(), true);
        self::assertSame([2, ['copied' => 2], true], [$r1['read'], $r1['counts'], $r1['capped']]);
        self::assertStringContainsString('(the most a run reads: the next run goes on)', ErpInvoiceCopy::logLines($r1)[0]);
        $r2 = $this->copy()->run($this->reader(), true);
        self::assertSame([1, ['copied' => 1], false], [$r2['read'], $r2['counts'], $r2['capped']]);
        self::assertSame(3, $this->docCount(), 'each once');
        // A back-fill from an earlier date reaching the cap: the cron (from the setting's date) goes on from where it stopped.
        foreach (['PI-Q1', 'PI-Q2', 'PI-Q3'] as $i => $name) {
            $this->erp->invoices[$name] = $this->pi($name, [['item' => 'A', 'qty' => 1, 'rate' => $i + 2]], ['modified' => '2026-10-1' . ($i + 1) . ' 10:00:00.000000',
                'bill_date' => '2026-09-20', 'posting_date' => '2026-09-20']);
        }
        $this->setting(ErpInvoiceCopy::PER_RUN, '4');
        $b = $this->copy()->run($this->reader(), true, '2026-08-15', 4); // --limit over the setting: P1-P3 again (unchanged), then Q1
        self::assertSame([4, ['copied' => 1, 'unchanged' => 3]], [$b['read'], self::sorted($b['counts'])]);
        self::assertSame(['2026-10-11 10:00:00.000000', 'PI-Q1'], (new ErpInvoiceCopy(self::$db))->cursor('VAPE AND GO', '2026-09-01'),
            'the cron of 1 Sep picks up the back-fill of 15 Aug');
        $cron = $this->copy()->run($this->reader(), true);
        self::assertSame([2, ['copied' => 2]], [$cron['read'], $cron['counts']], 'Q2 and Q3, from where the back-fill stopped');
        self::assertSame(6, $this->docCount());
        self::assertNull((new ErpInvoiceCopy(self::$db))->cursor('VAPE AND GO', '2026-07-01'), 'an earlier date than any run: from the start');
    }

    /**
     * The Codex re-check of fac1ea9: ONE budget of invoices read a run, what a debit note needs included (an invoice whose needs no longer
     * fit waits for the next run, the cursor before it); and a back-fill from a date before the setting's that reached the cap is drained
     * with its own date, so nothing it had still to read is skipped.
     */
    public function testOneBudgetOfReadsARunAndAnEarlierBackFillIsDrainedWithItsDate(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        $this->setting(ErpInvoiceCopy::PER_RUN, '2');
        // B is dated before the copies start (not listed); its debit note RET-B comes second in the list.
        $this->erp->invoices['PI-B'] = $this->pi('PI-B', [['item' => 'A', 'qty' => 2, 'rate' => 1]], ['modified' => '2026-09-01 10:00:00.000000',
            'posting_date' => '2026-08-01', 'bill_date' => '2026-08-01']);
        $this->erp->invoices['PI-A'] = $this->pi('PI-A', [['item' => 'A', 'qty' => 1, 'rate' => 1]], ['modified' => '2026-10-01 10:00:00.000000']);
        $this->erp->invoices['RET-B'] = $this->pi('RET-B', [['item' => 'A', 'qty' => -1, 'rate' => 1]], ['modified' => '2026-10-02 10:00:00.000000', 'is_return' => 1,
            'return_against' => 'PI-B']);
        $this->erp->invoices['PI-C'] = $this->pi('PI-C', [['item' => 'A', 'qty' => 3, 'rate' => 1]], ['modified' => '2026-10-03 10:00:00.000000']);
        $gets = function (): int {
            $n = count(array_filter($this->erp->calls, static fn (array $c): bool => str_starts_with($c['path'], '/api/resource/Purchase Invoice/')));
            $this->erp->calls = [];
            return $n;
        };
        $r1 = $this->copy()->run($this->reader(), true);
        self::assertSame([2, ['copied' => 1], true, 'PI-A'], [$gets(), $r1['counts'], $r1['capped'], $r1['cursor_name']],
            'RET-B is read, but with PI-B it needs it would be 3: it is left for the next run');
        $r2 = $this->copy()->run($this->reader(), true);
        self::assertSame([2, 'RET-B', 'original_skipped'], [$gets(), $r2['cursor_name'], $this->erpRow('RET-B')['problem']], 'RET-B first, with what it needs');
        $r3 = $this->copy()->run($this->reader(), true);
        self::assertSame([1, ['copied' => 1]], [$gets(), $r3['counts']]);
        self::assertSame(['PI-A', 'PI-C'], self::$db->column("SELECT name FROM erp_pi WHERE state = 'copied' ORDER BY name"));

        // An August back-fill (the setting says September) of three, two a run: the cron reads the third with August's date.
        foreach (['PI-G1', 'PI-G2', 'PI-G3'] as $i => $name) {
            $this->erp->invoices[$name] = $this->pi($name, [['item' => 'A', 'qty' => 1, 'rate' => $i + 5]], ['modified' => '2026-10-2' . ($i + 1) . ' 10:00:00.000000',
                'posting_date' => '2026-08-20', 'bill_date' => '2026-08-20']);
        }
        $this->erp->invoices = array_intersect_key($this->erp->invoices, array_flip(['PI-G1', 'PI-G2', 'PI-G3'])); // only these from here on
        $b = $this->copy()->run($this->reader(), true, '2026-08-15');
        self::assertSame([['copied' => 2], true], [$b['counts'], $b['capped']]);
        $cron = $this->copy()->run($this->reader(), true);
        self::assertSame(['2026-08-15', ['copied' => 1], false], [$cron['from'], $cron['counts'], $cron['capped']], 'drained with its own date');
        $next = $this->copy()->run($this->reader(), true);
        self::assertSame(['2026-09-01', 0], [$next['from'], $next['read']], 'drained: back to the setting\'s date');
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM erp_pi WHERE name LIKE 'PI-G%' AND state = 'copied'"));
    }

    /** The Codex re-check of ce9e1a0: a back-fill from an earlier date switched off before its end is gone on with from its date. */
    public function testAnEarlierBackFillSwitchedOffBeforeItsEndGoesOnWithItsDate(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        $this->setting(ErpInvoiceCopy::PER_RUN, '2');
        foreach (['PI-H1', 'PI-H2', 'PI-H3', 'PI-H4'] as $i => $name) {
            $this->erp->invoices[$name] = $this->pi($name, [['item' => 'A', 'qty' => 1, 'rate' => $i + 5]], ['modified' => '2026-10-2' . ($i + 1) . ' 10:00:00.000000',
                'posting_date' => '2026-08-20', 'bill_date' => '2026-08-20']);
        }
        self::assertSame(['copied' => 2], $this->copy()->run($this->reader(), true, '2026-08-15')['counts']);
        $this->setting(ErpInvoiceCopy::PER_RUN, '4'); // H3 and H4 are fewer than it reads: not capped
        $off = $this->copy()->run($this->reader(), true, null, null, static function (string $name): void {
            self::$db->exec("UPDATE app_setting SET value_json = CAST('false' AS JSON) WHERE setting_key = ?", [ErpInvoiceCopy::SWITCH]); // off after H3
        });
        self::assertSame(['2026-08-15', true, ['copied' => 1]], [$off['from'], $off['stopped'], $off['counts']]);
        $this->setting(ErpInvoiceCopy::SWITCH, 'true');
        $back = $this->copy()->run($this->reader(), true);
        self::assertSame(['2026-08-15', ['copied' => 1, 'unchanged' => 1]], [$back['from'], self::sorted($back['counts'])], 'H4 is copied: August still');
        self::assertSame(4, (int) self::$db->value("SELECT COUNT(*) FROM erp_pi WHERE name LIKE 'PI-H%' AND state = 'copied'"));
    }

    /**
     * The Codex re-check of 438dfb1: a copy whose cancellation stays refused does not keep out the invoice a waiting debit note needs (the
     * two kinds in turn; what fresh changes leave of the budget reads more), and a debit note whose invoice cannot be read waits for it
     * instead of stopping the run.
     */
    public function testAStuckRefusalNeverKeepsOutTheInvoiceADebitNoteWaitsFor(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        $this->erp->invoices['PI-K'] = $this->pi('PI-K', [['item' => 'A', 'qty' => 1, 'rate' => 4]]);
        $this->copy()->run($this->reader(), true);
        // ERPNext cancels PI-K; its cancellation here is refused, and stays so (a cancellation of it waits for an approval).
        $this->erp->invoices['PI-K']['docstatus'] = 2;
        $this->erp->invoices['PI-K']['modified'] = '2026-10-05 10:00:00.000000';
        $pending = self::$db->insert("INSERT INTO document (doc_type, status, version, external_ref, doc_date, created_actor, created_at, updated_at, reverses_id, submitted_at) "
            . "VALUES ('SINV', 'awaiting_approval', 1, 'B-PI-K', '2026-10-05', 'system:test', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6))",
            [(int) $this->erpRow('PI-K')['document_id']]);
        try {
            $this->copy()->run($this->reader(), true);
            self::assertSame(['copied', 'refused'], [$this->erpRow('PI-K')['state'], $this->erpRow('PI-K')['problem']]);
            // A debit note against PI-M, which ERPNext cannot give now: the run goes on and the debit note waits for it.
            $this->erp->invoices['RET-M'] = $this->pi('RET-M', [['item' => 'A', 'qty' => -1, 'rate' => 3, 'of' => 'PI-M-row1']], ['modified' => '2026-10-06 10:00:00.000000',
                'is_return' => 1, 'return_against' => 'PI-M']);
            $r = $this->copy()->run($this->reader(), true);
            self::assertSame(['waiting', 'original_missing'], [$this->erpRow('RET-M')['state'], $this->erpRow('RET-M')['problem']]);
            self::assertSame(['waiting' => 2], $r['counts'], 'PI-K (still refused) and RET-M');
            // PI-M can be read now (changed long ago: behind the cursor). Two a run, one kept for what is read again, and a fresh change
            // in every run: the kind that goes first changes from run to run, so PI-M is read in one of two runs, PI-K in the other.
            $this->erp->invoices['PI-M'] = $this->pi('PI-M', [['item' => 'A', 'qty' => 3, 'rate' => 3]], ['modified' => '2026-09-15 10:00:00.000000']);
            $this->setting(ErpInvoiceCopy::PER_RUN, '2');
            $reads = [];
            foreach (['PI-N1', 'PI-N2'] as $i => $name) {
                $this->erp->invoices[$name] = $this->pi($name, [['item' => 'A', 'qty' => 1, 'rate' => $i + 6]], ['modified' => '2026-10-0' . ($i + 7) . ' 10:00:00.000000']);
                $this->erp->calls = [];
                $this->copy()->run($this->reader(), true);
                $reads[] = array_values(array_map(static fn (array $c): string => substr($c['path'], strlen('/api/resource/Purchase Invoice/')),
                    array_filter($this->erp->calls, static fn (array $c): bool => str_starts_with($c['path'], '/api/resource/Purchase Invoice/'))));
            }
            self::assertSame([2, 2, 'PI-N1', 'PI-N2'], [count($reads[0]), count($reads[1]), $reads[0][0], $reads[1][0]], 'two a run, the fresh one first');
            self::assertEqualsCanonicalizing(['PI-K', 'PI-M'], [$reads[0][1], $reads[1][1]], 'then each kind in turn');
            self::assertSame(['copied', 'copied'], [$this->erpRow('PI-M')['state'], $this->erpRow('RET-M')['state']]);
            self::assertSame(['copied', 'refused'], [$this->erpRow('PI-K')['state'], $this->erpRow('PI-K')['problem']]);
        } finally {
            self::$db->exec('DELETE FROM document WHERE id = ?', [$pending]);
        }
    }

    /** The Codex review of d8af941, MEDIUM 3: the job's reasons must be able to cancel a copy, or the run stops before it reads anything. */
    public function testARunStopsBeforeReadingWhenItsReasonsCannotCancelACopy(): void
    {
        $this->on();
        $this->erp->invoices['PI-Z'] = $this->pi('PI-Z', [['item' => 'A', 'qty' => 1, 'rate' => 1]]);
        foreach (["UPDATE reason_code SET is_active = 0 WHERE code = 'erp_changed'" => 'the reason erp_changed is switched off',
            "UPDATE reason_code SET applies_to = 'adjustment' WHERE code = 'erp_cancelled'" => 'the reason erp_cancelled is not offered for cancelling records'] as $sql => $says) {
            self::$db->exec($sql);
            try {
                foreach ([false, true] as $apply) {
                    try {
                        $this->copy()->run($this->reader(), $apply);
                        self::fail('refused');
                    } catch (CwException $e) {
                        self::assertSame('erp_copy_reasons', $e->errorCode);
                        self::assertStringContainsString($says, $e->getMessage());
                        self::assertStringContainsString('Settings > Reasons', $e->getMessage());
                    }
                }
            } finally {
                self::$db->exec("UPDATE reason_code SET is_active = 1, applies_to = 'reversal' WHERE code IN ('erp_cancelled', 'erp_changed')");
            }
        }
        self::assertSame([], $this->erp->calls, 'nothing read');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM erp_copy_run') + (int) self::$db->value('SELECT COUNT(*) FROM erp_pi'), 'nothing written');
        self::assertSame([], (new ErpInvoiceCopy(self::$db))->reasonProblems());
    }

    /** The Codex review of 0ccc04c, MEDIUM 7: a relay booking of an older copy is found whatever number of newer copies still wait for theirs. */
    public function testTheRelayRecheckTakesOnlyTheCopiesTheRelayBookedSince(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        foreach (['PI-R1', 'PI-R2', 'PI-R3'] as $name) {
            $this->erp->invoices[$name] = $this->pi($name, [['item' => 'A', 'qty' => 2, 'rate' => 1]]);
        }
        $this->copy()->run($this->reader(), true);
        $this->relay('PI-R1', [['line_index' => 0, 'variant_id' => 'VA', 'qty' => 2]]); // the oldest only
        self::assertSame(1, $this->copy()->relayRecheck(true));
        self::assertSame(['matched', 'within_tolerance', 'within_tolerance'], self::$db->column('SELECT si.match_state FROM erp_pi p JOIN supplier_invoice si '
            . 'ON si.document_id = p.document_id ORDER BY p.name'));
        self::assertSame(0, $this->copy()->relayRecheck(true), 'nothing new: nothing looked at again');
    }

    /** The Codex re-check of 6102935, NEW 1: a copy whose cancellation was refused is read again on the next run, past the cursor. */
    public function testARefusedCancellationIsTriedAgainOnTheNextRun(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        $this->erp->invoices['PI-C1'] = $this->pi('PI-C1', [['item' => 'A', 'qty' => 1, 'rate' => 4]]);
        $this->copy()->run($this->reader(), true);
        $this->erp->invoices['PI-C1']['docstatus'] = 2;
        $this->erp->invoices['PI-C1']['modified'] = '2026-10-09 10:00:00.000000';
        // The cancellation is refused: a cancellation of the copy already waits for an approval (reversal_pending). Made by hand here, and
        // removed before the next run (the job's reasons are fine: the run starts).
        $copy = (int) $this->erpRow('PI-C1')['document_id'];
        $pending = self::$db->insert("INSERT INTO document (doc_type, status, version, external_ref, doc_date, created_actor, created_at, updated_at, reverses_id, submitted_at) "
            . "VALUES ('SINV', 'awaiting_approval', 1, 'B-PI-C1', '2026-10-09', 'system:test', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6))", [$copy]);
        try {
            $this->copy()->run($this->reader(), true);
        } finally {
            self::$db->exec('DELETE FROM document WHERE id = ?', [$pending]);
        }
        $p = $this->erpRow('PI-C1');
        self::assertSame(['copied', 'refused'], [$p['state'], $p['problem']]);
        self::assertStringContainsString('reversal_pending', (string) $p['problem_detail']);
        self::assertSame(['PI-C1'], array_column((new ErpInvoiceCopy(self::$db))->list(), 'name'), 'listed with the waiting ones');
        // Fresh changes fill more than a run reads (two a run): PI-C1 is read again all the same, in the share kept for it (the Codex
        // re-check of ce9e1a0).
        $this->setting(ErpInvoiceCopy::PER_RUN, '2');
        foreach (['PI-F1', 'PI-F2', 'PI-F3'] as $i => $name) {
            $this->erp->invoices[$name] = $this->pi($name, [['item' => 'A', 'qty' => 1, 'rate' => $i + 2]], ['modified' => '2026-10-1' . ($i + 1) . ' 10:00:00.000000']);
        }
        $r = $this->copy()->run($this->reader(), true);
        self::assertSame([2, ['copied' => 1, 'reversed' => 1], true], [$r['read'], self::sorted($r['counts']), $r['capped']]);
        self::assertSame(['reversed', null], [$this->erpRow('PI-C1')['state'], $this->erpRow('PI-C1')['problem']]);
        self::assertSame('reversed', self::$db->value('SELECT status FROM document WHERE id = ?', [(int) $p['document_id']]));
    }

    public function testAnApprovalRuleOnInvoicesNeverStopsACopy(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        $this->rule('SINV', ['size_approval' => 1, 'size_units' => 1, 'review_rule' => 'all']);
        $this->erp->invoices['PI-5'] = $this->pi('PI-5', [['item' => 'A', 'qty' => 50, 'rate' => 1]]);
        $this->copy()->run($this->reader(), true);
        self::assertSame('posted', self::$db->value('SELECT status FROM document WHERE id = ?', [(int) $this->erpRow('PI-5')['document_id']]));
        self::assertSame(0, $this->documentTasks(), 'no approval, no review: Approvals stays clear');
    }

    public function testADuplicateSupplierNumberWaitsForAPerson(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        $this->erp->invoices['PI-6'] = $this->pi('PI-6', [['item' => 'A', 'qty' => 1, 'rate' => 1]], ['bill_no' => 'SAME-1']);
        $this->erp->invoices['PI-7'] = $this->pi('PI-7', [['item' => 'A', 'qty' => 2, 'rate' => 1]], ['bill_no' => 'SAME-1', 'modified' => '2026-10-02 10:00:00.000000']);
        $this->copy()->run($this->reader(), true);
        self::assertSame('copied', $this->erpRow('PI-6')['state']);
        self::assertSame(['waiting', 'duplicate_number'], [$this->erpRow('PI-7')['state'], $this->erpRow('PI-7')['problem']]);
        self::assertSame(1, $this->docCount(), 'the refused copy left nothing: no number, no document');
        self::assertSame(1, (int) self::$db->value("SELECT last_no FROM number_series WHERE prefix = 'SINV'"));
    }

    // ------------------------------------------------------------------------------------------
    // The guards

    public function testTheNightlyCheckFindsACopyThatBookedStock(): void
    {
        $this->on();
        $sku = $this->linked('VA', 'A');
        $this->erp->invoices['PI-8'] = $this->pi('PI-8', [['item' => 'A', 'qty' => 1, 'rate' => 1]]);
        $this->copy()->run($this->reader(), true);
        $p = $this->erpRow('PI-8');
        self::assertSame([], InvoiceInvariants::check(self::$db));
        $pdo = self::$db->pdo();
        $pdo->beginTransaction();
        try {
            (new Movements(self::$db))->bookForDocument(Caller::system('v8_test'), ['document_id' => (int) $p['document_id'], 'doc_ref' => (string) self::$db->value('SELECT number FROM document WHERE id = ?',
                [(int) $p['document_id']])], 'v8-test', [['type' => 'goods_in', 'warehouse' => 'MAIN', 'lines' => [['document_line' => 1, 'sku_id' => $sku, 'qty' => 1]]]]);
            self::$db->exec('INSERT INTO invoice_value_event (document_id, line_no, kind, sku_id, units, amount, basis, effective_on) VALUES (?, 1, ?, ?, 1, 1.00, ?, ?)', // created_at: its default
                [(int) $p['document_id'], 'price_difference', $sku, 'line', '2026-10-01']);
            $v = array_values(array_filter(InvoiceInvariants::check(self::$db), static fn (string $x): bool => str_starts_with($x, 'V8')));
            self::assertCount(2, $v, implode("\n", $v));
            self::assertStringContainsString('stock booked under it or its cancellation', $v[0]);
            self::assertStringContainsString('value events', $v[1]);
        } finally {
            $pdo->rollBack();
        }
    }

    public function testNobodyButTheJobChangesACopyAndACopyNeverMovesStock(): void
    {
        $this->on();
        $this->linked('VA', 'A');
        $this->erp->invoices['PI-10'] = $this->pi('PI-10', [['item' => 'A', 'qty' => 1, 'rate' => 1]]);
        $this->copy()->run($this->reader(), true);
        $id = (int) $this->erpRow('PI-10')['document_id'];
        ['desk' => $desk] = $this->people('Someone else');
        $this->refusedCode('erp_copy', fn () => $this->invoices->reverse($desk, $id, 'entered_in_error', null), 'a person does not cancel a copy');
        $this->refusedCode('erp_copy', fn () => $this->docs->reverse($desk, $id, 'entered_in_error', null), 'not through the document base either');
        $this->refusedCode('erp_copy', fn () => $this->invoices->cancel($desk, $id, 1, 'not a draft anyway'));
        $draft = $this->invoices->createDraft($desk, 'credit_note', $this->erpSupplier, ['invoice_number' => 'CN-X']);
        $this->refusedCode('credits_copy', fn () => $this->invoices->saveHeader($desk, $draft->id, $draft->version, ['credits_id' => $id]), 'a copy is credited in ERPNext');
        $this->refusedCode('system_job_only', fn () => $this->invoices->createCopy($desk, 'invoice', $this->erpSupplier, ['invoice_number' => 'X', 'invoice_date' => '2026-10-01',
            'net_total' => '1.00', 'vat_total' => '0.00'], [['kind' => 'other', 'amount' => '1.00', 'description' => 'x']], 'X'));
        $this->refusedCode('system_job_only', fn () => $this->docs->postBySystem(Caller::system('other_job'), 'SINV', [], [], static function (): void {
        }));
        $this->refusedCode('system_job_only', fn () => $this->docs->reverseBySystem($desk, $id, 'erp_cancelled', null));
        $job = Caller::system(ErpInvoiceCopy::JOB);
        $this->refusedCode('system_job_only', fn () => $this->docs->postBySystem($job, 'GRN', [], [], static function (): void {
        }), 'the job keeps supplier invoices and credit notes only');
        // A copy naming a delivery or an order is refused before anything is read or written; one that would bring goods in, by the handler.
        foreach (['grn_document_id' => 'grn_line_no', 'po_document_id' => 'po_line_no'] as $doc => $line) {
            $this->refusedCode('copy_links', fn () => $this->invoices->createCopy($job, 'invoice', $this->erpSupplier, ['invoice_number' => 'Y', 'invoice_date' => '2026-10-01',
                'net_total' => '1.00', 'vat_total' => '0.00'], [['kind' => 'goods', 'sku_id' => self::makeSku('Linked'), 'packs' => 1, 'units_per_pack' => 1,
                    'pack_price' => '1.0000', $doc => $id, $line => 1]], 'Y'));
        }
        $doc = Document::fromRow((array) self::$db->one('SELECT * FROM document WHERE id = ?', [$id]));
        $this->refusedCode('copy_moves_stock', static fn () => SupplierInvoiceHandler::refuseStockOnCopy(self::$db, $doc, ['receive_mode' => 'count'], $job, 'SINV'));
        $this->refusedCode('system_job_only', static fn () => SupplierInvoiceHandler::refuseStockOnCopy(self::$db, $doc, ['receive_mode' => null], $desk, 'SINV'));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM supplier_invoice WHERE origin = 'erpnext'"));
    }

    public function testAFailedReadLeavesTheCursorWhereItWas(): void
    {
        $this->on();
        $this->erp->status = 403;
        try {
            $this->copy()->run($this->reader(), true);
            self::fail('a refused read stops the run');
        } catch (CwException $e) {
            self::assertSame('erp_read_failed', $e->errorCode);
            self::assertStringNotContainsString('secret456', $e->getMessage());
        }
        $run = (array) self::$db->one('SELECT * FROM erp_copy_run');
        self::assertSame(['failed', null], [$run['status'], $run['cursor_to']]);
        self::assertNull((new ErpInvoiceCopy(self::$db))->cursor('VAPE AND GO', '2026-09-01'));
    }

    // ------------------------------------------------------------------------------------------

    /** @param array<string, int> $a @return array<string, int> */
    private static function sorted(array $a): array
    {
        ksort($a);
        return $a;
    }

    private function on(): void
    {
        $this->setting(ErpInvoiceCopy::SWITCH, 'true');
    }

    private function copy(): ErpInvoiceCopy
    {
        return new ErpInvoiceCopy(self::$db);
    }

    private function reader(): ErpApiReader
    {
        return new ErpApiReader($this->erp, 'https://erp.example.test', 'abc123key', 'secret456', 5, 2); // pages of 2: paging is used
    }

    /** A CW item linked to the Vape and Go variant $variant ($u units per item), and the ERPNext stock item $code naming that variant. */
    private function linked(string $variant, string $code, int $u = 1): int
    {
        $sku = self::makeSku('Item ' . $code);
        $this->listing($this->site, $variant, $sku, $u);
        $this->erp->items[$code] = ['is_stock_item' => 1, 'custom_variant_id' => $variant];
        return $sku;
    }

    /** What the shop's relay sends for an ERPNext purchase invoice (or a debit note: supplier_return). @param list<array<string, mixed>> $lines */
    private function relay(string $doc, array $lines, string $type = 'goods_in'): void
    {
        $r = $this->moves->record($this->site, ['type' => $type, 'doc_ref' => $doc, 'doc_type' => 'purchase_invoice', 'lines' => $lines], $this->key('relay'));
        self::assertSame(200, $r->status, json_encode($r->body, JSON_THROW_ON_ERROR));
        self::assertSame(['booked'], array_values(array_unique(array_column($r->body['lines'], 'result'))));
    }

    /**
     * An ERPNext Purchase Invoice as the REST API gives it: rows of item, qty, rate (base_net_rate), conversion factor, unit; VAT 20 % as a
     * tax row; totals as ERPNext works them out.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function pi(string $name, array $rows, array $over = []): array
    {
        $items = [];
        $net = 0.0;
        foreach ($rows as $i => $r) {
            $cf = $r['cf'] ?? 1;
            $amount = round($r['qty'] * $r['rate'], 2);
            $items[] = ['name' => $name . '-row' . ($i + 1), 'idx' => $i + 1, 'item_code' => $r['item'], 'item_name' => $r['item_name'] ?? 'Item ' . $r['item'], 'qty' => $r['qty'],
                'stock_qty' => $r['qty'] * $cf, 'conversion_factor' => $cf, 'uom' => $r['uom'] ?? 'Nos', 'base_net_rate' => $r['rate'], 'base_net_amount' => $amount]
                + (isset($r['of']) ? ['purchase_invoice_item' => $r['of']] : []);
            $net += $amount;
        }
        $vat = round($net * 0.2, 2);
        return $over + ['name' => $name, 'company' => 'VAPE AND GO', 'supplier' => 'ERP Supplies Ltd', 'supplier_name' => 'ERP Supplies Ltd', 'bill_no' => 'B-' . $name,
            'bill_date' => '2026-10-01', 'posting_date' => '2026-10-01', 'due_date' => null, 'docstatus' => 1, 'is_return' => 0, 'modified' => '2026-10-01 10:00:00.000000',
            'base_net_total' => round($net, 2), 'base_grand_total' => round($net + $vat, 2), 'items' => $items,
            'taxes' => [['account_head' => 'VAT - VPG', 'description' => 'VAT 20%', 'charge_type' => 'On Net Total', 'category' => 'Total', 'add_deduct_tax' => 'Add',
                'base_tax_amount_after_discount_amount' => $vat]]];
    }

    /** @return array<string, mixed> */
    private function erpRow(string $name): array
    {
        return (array) self::$db->one('SELECT * FROM erp_pi WHERE name = ?', [$name]);
    }

    /** Review and approval tasks of records (the suppliers' own activations are not Approvals' business here). */
    private function documentTasks(): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM review_task WHERE subject_type = 'document'");
    }

    private function docCount(): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type IN ('SINV', 'SCN')");
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $out = [];
        foreach (['erp_pi', 'erp_pi_line', 'erp_copy_run', 'document', 'supplier_invoice', 'audit_log', 'supplier', 'supplier_item', 'supplier_item_price', 'stock_ledger'] as $t) {
            $out[$t] = (int) self::$db->value("SELECT COUNT(*) FROM `{$t}`");
        }
        $out['series'] = (int) self::$db->value("SELECT COALESCE(SUM(last_no), 0) FROM number_series WHERE prefix IN ('SINV', 'SCN')");
        return $out;
    }
}
