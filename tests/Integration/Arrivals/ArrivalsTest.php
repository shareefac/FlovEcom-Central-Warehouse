<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Arrivals;

use CW\Clock;
use CW\Invariants;
use CW\Ops\Alerts;
use CW\Settings;
use CW\Tests\Support\TestDb;

/**
 * Pack J (docs/decisions.md PJ1-PJ16), the owner's day-one purchasing workflow on the services: an invoice made final at the time a
 * person sets (once; changed, re-timed or stopped before it), "count on arrival" (the units wait in Incoming, sold on the websites
 * only when the supplier sells before arrival; Goods In receives them against the invoice; a short delivery takes the missing units
 * out at once and one click claims them), "book as received" (on hand at once, the duty stamp asked on the invoice), reversals, the
 * nightly checks and who may do what.
 */
final class ArrivalsTest extends ArrivalsTestCase
{
    public function testATimedInvoiceIsMadeFinalOnceAtItsTime(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Timed Ltd');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 10);
        $at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+10 minutes');
        $d = $this->invoices->schedule($desk, $x['doc']->id, $x['doc']->version, $at);
        $rec = $this->invoices->record($d->id);
        self::assertSame([$at->format('Y-m-d H:i'), $desk->staffUserId, 'count'], [substr((string) $rec['submit_at'], 0, 16), (int) $rec['submit_by'], $rec['receive_mode']]);

        self::assertSame([], $this->job()->runDue($at->modify('-1 minute'))['posted'], 'not before its time');
        self::assertSame('draft', $this->docStatus($d->id));
        self::assertSame([$d->id], $this->job()->runDue($at->modify('+1 minute'))['posted']);
        self::assertSame('posted', $this->docStatus($d->id));
        self::assertNull($this->invoices->record($d->id)['submit_at'], 'done: no time left on it');
        self::assertSame([], $this->job()->runDue($at->modify('+2 minutes'))['posted'], 'never twice');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'invoice.post' AND entity_id = ?", [(string) $d->id]));
        self::assertSame((string) $desk->actor, (string) self::$db->value('SELECT posted_actor FROM document WHERE id = ?', [$d->id]),
            'the posting of the person who set the time');
    }

    /** The cron's own process (bin/submit_invoices.php, every minute): the due invoice once, then quiet; a failure exits 1 once. */
    public function testTheJobAsTheCronRunsIt(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Cron Ltd');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 3);
        $bad = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 2);
        $at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+10 minutes');
        $this->invoices->schedule($desk, $x['doc']->id, $x['doc']->version, $at);
        $b = $this->invoices->saveHeader($desk, $bad['doc']->id, $bad['doc']->version, ['net_total' => '1.00']); // the lines add up to £8.00
        $b = $this->invoices->schedule($desk, $b->id, $b->version, $at);
        self::$db->exec('UPDATE supplier_invoice SET submit_at = ? WHERE document_id IN (?, ?)', [Clock::db($at->modify('-20 minutes')), $x['doc']->id, $b->id]);
        $run = static function (): array {
            $root = dirname(__DIR__, 3);
            $p = proc_open([PHP_BINARY, "{$root}/bin/submit_invoices.php", '--db=' . TestDb::name(), '--admin'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            self::assertIsResource($p);
            $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return ['code' => proc_close($p), 'out' => $out];
        };
        $first = $run();
        self::assertSame(1, $first['code'], $first['out']);
        self::assertStringContainsString('posted=1 failed=1', $first['out']);
        self::assertSame(['posted', 'draft'], [$this->docStatus($x['doc']->id), $this->docStatus($b->id)]);
        self::assertNotNull($this->invoices->record($b->id)['submit_failed_at']);
        $again = $run();
        self::assertSame([0, ''], [$again['code'], trim($again['out'])], 'nothing due: no line in the log, the failed one not tried again');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'invoice.post' AND entity_id = ?", [(string) $x['doc']->id]));
    }

    public function testATimedInvoiceIsChangedReTimedOrStoppedBeforeItsTime(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Re-timed Ltd');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 4);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $d = $this->invoices->schedule($desk, $x['doc']->id, $x['doc']->version, $now->modify('+5 minutes'));
        $this->refusedCode('bad_submit_at', fn () => $this->invoices->schedule($desk, $d->id, $d->version, $now->modify('-1 hour')), 'a time in the past');
        // Changed while it waits: the time stays.
        $d = $this->invoices->saveHeader($desk, $d->id, $d->version, ['note' => 'Checked against the paper']);
        self::assertNotNull($this->invoices->record($d->id)['submit_at']);
        // Re-timed to tomorrow: nothing happens at the first time.
        $d = $this->invoices->schedule($desk, $d->id, $d->version, $now->modify('+1 day'));
        self::assertSame([], $this->job()->runDue($now->modify('+10 minutes'))['posted']);
        // The time taken off: never made final by the job.
        $d = $this->invoices->schedule($desk, $d->id, $d->version, null);
        self::assertSame([], $this->job()->runDue($now->modify('+2 days'))['posted']);
        self::assertSame('draft', $this->docStatus($d->id));
        // Timed again, then stopped: the stopped draft keeps no time.
        $d = $this->invoices->schedule($desk, $d->id, $d->version, $now->modify('+1 hour'));
        $this->invoices->cancel($desk, $d->id, $d->version, 'Keyed twice');
        self::assertNull($this->invoices->record($d->id)['submit_at']);
        self::assertSame([], $this->job()->runDue($now->modify('+2 hours'))['posted']);
        // Only the person who keys the draft times it.
        $y = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 2);
        $this->refusedCode('not_creator', fn () => $this->invoices->schedule($this->staffUser('purchasing_desk'), $y['doc']->id, $y['doc']->version, $now->modify('+1 hour')));
    }

    public function testAFailedTimedPostingIsListedAlertedAndNotTriedAgain(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Failing Ltd');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 3);
        $d = $this->invoices->saveHeader($desk, $x['doc']->id, $x['doc']->version, ['net_total' => '99.00']); // the lines add up to £12.00
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $d = $this->invoices->schedule($desk, $d->id, $d->version, $now->modify('+1 minute'));
        $r = $this->job()->runDue($now->modify('+2 minutes'));
        self::assertSame([[], [$d->id]], [$r['posted'], $r['failed']]);
        $rec = $this->invoices->record($d->id);
        self::assertSame('draft', $this->docStatus($d->id));
        self::assertNotNull($rec['submit_failed_at']);
        self::assertStringContainsString('£12.00', (string) $rec['submit_error']);
        self::assertSame([$d->id], array_map(static fn (array $r): int => (int) $r['id'], $this->invoices->scheduled()), 'listed with the timed ones');
        $alerts = (new Alerts(self::$db, static fn (): float => 1.0))->problems(new Settings(self::$db));
        self::assertContains('invoice_submit_failed', array_column($alerts, 'kind'));
        self::assertSame(['failed' => []], array_intersect_key($this->job()->runDue($now->modify('+3 minutes')), ['failed' => 0]), 'not tried again');
        // Put right and timed again: made final.
        $d = $this->invoices->saveHeader($desk, $d->id, $this->docs->get($d->id)->version, ['net_total' => '12.00']);
        $d = $this->invoices->schedule($desk, $d->id, $d->version, $now->modify('+4 minutes'));
        self::assertNull($this->invoices->record($d->id)['submit_failed_at']);
        self::assertSame([$d->id], $this->job()->runDue($now->modify('+5 minutes'))['posted']);
        self::assertNotContains('invoice_submit_failed', array_column((new Alerts(self::$db, static fn (): float => 1.0))->problems(new Settings(self::$db)), 'kind'));
    }

    public function testCountOnArrivalSellsTheUnitsOnlyWhenTheSupplierSellsBeforeArrival(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $a] = $this->people('Sells Early Ltd');
        $b = $this->activeSupplier($buyer, ['name' => 'Waits Ltd']);
        $this->arrivals->setPrefs($buyer, (int) $b['id'], false, 'count');
        self::assertSame(['sell_before' => true, 'mode' => 'count', 'own' => false], $this->arrivals->prefsOf((int) $a['id']), 'the settings');
        self::assertSame(['sell_before' => false, 'mode' => 'count', 'own' => true], $this->arrivals->prefsOf((int) $b['id']));
        self::assertContains('supplier.arrivals', array_column(self::$db->all("SELECT action FROM audit_log WHERE entity_type = 'supplier' AND entity_id = ?",
            [(string) $b['id']]), 'action'));
        $site = $this->site();
        $xa = $this->invoiceOfOrder($desk, $buyer, (int) $a['id'], 10);
        $xb = $this->invoiceOfOrder($desk, $buyer, (int) $b['id'], 6);
        $this->listing($site, 'VA', $xa['sku']);
        $this->listing($site, 'VB', $xb['sku']);
        $pa = $this->invoices->submit($desk, $xa['doc']->id, $xa['doc']->version);
        $pb = $this->invoices->submit($desk, $xb['doc']->id, $xb['doc']->version);
        self::assertSame(['posted', 'posted'], [$pa->status, $pb->status]);
        self::assertSame([[10, 0], [0, 0]], [[$this->incoming($xa['sku']), $this->onHand($xa['sku'])], [$this->incoming($xb['sku']), $this->onHand($xb['sku'])]]);
        self::assertSame([10, 0], [$this->view($site, 'VA')['available'], $this->view($site, 'VB')['available']], 'only the supplier that sells early is on the websites');
        self::assertSame([['state' => 'open', 'units' => 10, 'sellable' => 1, 'grn' => null]], $this->arrivalRows($pa->id));
        self::assertSame([['state' => 'open', 'units' => 6, 'sellable' => 0, 'grn' => null]], $this->arrivalRows($pb->id));
        self::assertSame(['matched', 'matched'], [$this->stored($pa->id)['match_state'], $this->stored($pb->id)['match_state']], 'billed as ordered: on its way');
        $list = $this->arrivals->arriving();
        self::assertSame([$pa->id => [10, 10], $pb->id => [6, 0]], array_column(array_map(static fn (array $r): array => [$r['invoice_id'], [$r['units'], $r['sellable_units']]], $list), 1, 0));
        self::assertSame([], array_filter(array_column($list, 'late')), 'none late yet');
        $this->setting('arrivals.late_hours', '1');
        $later = new \CW\Arrivals\Arrivals(self::$db, $this->docs, null, static fn (): \DateTimeImmutable => new \DateTimeImmutable('+2 hours'));
        self::assertSame([$pa->id, $pb->id], array_values(array_map(static fn (array $r): int => $r['invoice_id'], array_filter($later->arriving(),
            static fn (array $r): bool => $r['late']))), 'two hours on, both are late');
    }

    public function testGoodsInReceivesAgainstTheInvoiceIntoStock(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Arrives Ltd');
        $site = $this->site();
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 10);
        $this->listing($site, 'V1', $x['sku']);
        $p = $this->invoices->submit($desk, $x['doc']->id, $x['doc']->version);
        $g = $this->arrivals->receive($desk, $p->id);
        self::assertSame(['draft', $p->externalRef], [$g->status, $g->externalRef], 'the receipt carries the invoice number');
        $this->invoice($desk, $g->id); // the invoice's copy (this invoice has none to copy)
        self::assertSame([[$x['sku'], 10, 1]], array_map(static fn (array $l): array => [(int) $l['sku_id'], (int) $l['packs'], (int) $l['po_line_no']], $this->grns->lines($g->id)));
        self::assertSame($g->id, $this->arrivals->receive($desk, $p->id)->id, 'one receipt per invoice: the same draft again');
        $this->bench($this->staffUser('goods_in'), $g->id);
        $g = $this->post($desk, $g->id);
        self::assertSame([0, 10, 10], [$this->incoming($x['sku']), $this->onHand($x['sku']), $this->view($site, 'V1')['available']], 'Incoming to on hand: the figure stays');
        self::assertSame([['state' => 'received', 'units' => 10, 'sellable' => 1, 'grn' => $g->id]], $this->arrivalRows($p->id));
        self::assertSame('matched', $this->stored($p->id)['match_state']);
        self::assertSame([], $this->invoices->notInvoiced((int) $s['id']), 'the receipt is invoiced by the invoice it came from');
        self::assertSame('received', (string) self::$db->value('SELECT state FROM purchase_order WHERE document_id = ?', [$x['po']->id]));
        self::assertSame([], $this->arrivals->arriving());
        $this->refusedCode('arrived', fn () => $this->invoices->reverse($desk, $p->id, 'entered_in_error', null), 'reverse the receipt first');
        // The receipt's reversal: the units are on their way again.
        $this->docs->reverse($desk, $g->id, 'entered_in_error', null);
        self::assertSame([10, 0], [$this->incoming($x['sku']), $this->onHand($x['sku'])]);
        self::assertSame([['state' => 'open', 'units' => 10, 'sellable' => 1, 'grn' => null]], $this->arrivalRows($p->id));
        self::assertSame([], Invariants::check(self::$db));
    }

    public function testAShortDeliveryLowersAvailabilityAtOnceRaisesTheOversellAndIsClaimed(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Short Ltd');
        $site = $this->site();
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 10, 1, '4.0000');
        $this->strict($x['sku']);
        $this->listing($site, 'V1', $x['sku']);
        $p = $this->invoices->submit($desk, $x['doc']->id, $x['doc']->version);
        // Overnight: the websites sell all ten.
        $this->ok($this->commit($site, 'N1', [self::line('V1', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j')]));
        self::assertSame(0, $this->view($site, 'V1')['available']);
        // Seven arrive.
        $g = $this->arrivals->receive($desk, $p->id);
        $this->ready($desk, $g->id, [1 => ['short_units' => '3']]);
        $ledger = $this->ledgerCount();
        $g = $this->post($desk, $g->id);
        self::assertSame([0, 7, -3], [$this->incoming($x['sku']), $this->onHand($x['sku']), $this->view($site, 'V1')['available']]);
        $e = self::$db->one('SELECT kind, shortfall FROM oversell_event WHERE sku_id = ? ORDER BY id DESC LIMIT 1', [$x['sku']]);
        self::assertSame(['movement_short', 3], [$e['kind'] ?? null, (int) ($e['shortfall'] ?? 0)], 'three sold that will not come');
        self::assertSame(['needs_review', '1', '12.00'], array_values(array_map('strval', $this->stored($p->id))), 'billed ten, seven came');
        self::assertSame([['line' => 1, 'sku_id' => $x['sku'], 'units' => 3, 'amount' => '12.00']], array_map(static fn (array $m): array => ['line' => $m['line_no'],
            'sku_id' => $m['sku_id'], 'units' => $m['units'], 'amount' => $m['amount']], $this->arrivals->missingOf($g->id)));
        // One click: the claim, a debit note with no stock movement.
        $dn = $this->arrivals->claimMissing($desk, $g->id);
        self::assertSame($dn->id, $this->arrivals->claimMissing($desk, $g->id)->id, 'one claim per receipt');
        $lines = $this->returns->lines($dn->id);
        self::assertSame([[null, null, '12.000000']], array_map(static fn (array $l): array => [$l['sku_id'], $l['qty'], $l['amount']], $lines));
        self::assertSame([$g->id, $p->id], array_map('intval', array_values((array) self::$db->one('SELECT grn_document_id, invoice_document_id FROM supplier_return WHERE document_id = ?', [$dn->id]))));
        $ledger = $this->ledgerCount();
        $dn = $this->returns->post($desk, $dn->id, $this->docs->get($dn->id)->version);
        self::assertSame(['posted', $ledger], [$dn->status, $this->ledgerCount()], 'no stock movement');
        self::assertSame('12.00', (string) self::$db->value('SELECT claim_total FROM supplier_return WHERE document_id = ?', [$dn->id]));
        self::assertSame([], Invariants::check(self::$db));
    }

    public function testBookAsReceivedPutsTheUnitsOnHandAndAsksTheStampOnTheInvoice(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Sure Ltd');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 8, 1, '2.5000');
        $this->liquid($x['sku']);
        $d = $this->invoices->setReceiving($desk, $x['doc']->id, $x['doc']->version, 'received', []);
        self::assertSame(['stamp_answer_required', 'invoice_copy_required'], array_values(array_intersect(['stamp_answer_required', 'invoice_copy_required'],
            array_column($this->invoices->problems($d->id), 'code'))));
        $this->refusedCode('stamp_answer_required', fn () => $this->invoices->submit($desk, $d->id, $d->version));
        self::assertSame([0, 0], [$this->onHand($x['sku']), (int) self::$db->value('SELECT COUNT(*) FROM goods_receipt_invoice WHERE sinv_document_id = ?', [$d->id])],
            'nothing booked');
        $d = $this->invoices->setReceiving($desk, $d->id, $d->version, 'received', [1 => 'digital']);
        $this->invoices->attach($desk, $d->id, $this->pdf(), 'invoice.pdf', 'supplier_invoice');
        $p = $this->invoices->submit($desk, $d->id, $this->docs->get($d->id)->version);
        self::assertSame('posted', $p->status);
        self::assertSame([8, 0], [$this->onHand($x['sku']), $this->incoming($x['sku'])], 'on hand at once, nothing in Incoming');
        $grn = (int) self::$db->value('SELECT grn_document_id FROM goods_receipt_invoice WHERE sinv_document_id = ? AND auto = 1', [$p->id]);
        self::assertSame(['posted', $grn], [$this->docStatus($grn), (int) $this->invoices->record($p->id)['submit_receipt_id']]);
        self::assertSame([1, 'digital', 8], array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v, array_values((array) self::$db->one(
            'SELECT stamp_on_pack, stamp_type, accepted_units FROM grn_line WHERE document_id = ?', [$grn]))), 'the receipt carries the answer given on the invoice');
        self::assertSame('matched', $this->stored($p->id)['match_state']);
        self::assertSame([], $this->arrivalRows($p->id), 'nothing on its way');
        self::assertSame('received', (string) self::$db->value('SELECT state FROM purchase_order WHERE document_id = ?', [$x['po']->id]));
    }

    public function testAnArrivingInvoiceCancelledBeforeItsGoodsComeTakesThemOffTheWebsites(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Cancelled Ltd');
        $site = $this->site();
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 5);
        $this->listing($site, 'V1', $x['sku']);
        $p = $this->invoices->submit($desk, $x['doc']->id, $x['doc']->version);
        self::assertSame(5, $this->view($site, 'V1')['available']);
        $this->invoices->reverse($desk, $p->id, 'entered_in_error', null);
        self::assertSame([0, 0], [$this->incoming($x['sku']), $this->view($site, 'V1')['available']]);
        self::assertSame([['state' => 'cancelled', 'units' => 5, 'sellable' => 1, 'grn' => null]], $this->arrivalRows($p->id));
        $this->refusedCode('nothing_arriving', fn () => $this->arrivals->receive($desk, $p->id));
    }

    public function testTheNightlyChecksHoldIncomingToTheInvoicesOnTheirWay(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Checked Ltd');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 4);
        $p = $this->invoices->submit($desk, $x['doc']->id, $x['doc']->version);
        self::assertSame([], Invariants::check(self::$db));
        self::$db->exec("UPDATE invoice_arrival SET state = 'cancelled', closed_at = NOW(6) WHERE sinv_document_id = ?", [$p->id]);
        $v = implode("\n", Invariants::check(self::$db));
        self::$db->exec("UPDATE invoice_arrival SET state = 'open', closed_at = NULL WHERE sinv_document_id = ?", [$p->id]);
        self::assertStringContainsString("J1 item {$x['sku']} at MAIN: incoming 4, but the invoices on their way add up to 0", $v);
    }

    /** Units on their way are not stock to move (PJ1): an item with some is merged after they are received, not before. */
    public function testAnItemWithUnitsOnTheirWayIsMergedOnlyOnceTheyArrive(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Merge Waits Ltd');
        $site = $this->site();
        $lead = $this->staffUser('mapping_lead');
        $keep = $this->item('legacy', 3);
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 4);
        self::$db->exec("UPDATE sku SET sell_policy = 'legacy' WHERE id = ?", [$x['sku']]);
        $this->listing($site, 'MK', $keep);
        $dup = $this->listing($site, 'MD', $x['sku']);
        $p = $this->invoices->submit($desk, $x['doc']->id, $x['doc']->version);
        self::assertSame(4, $this->incoming($x['sku']));
        self::refused(409, 'incoming_on_item', fn () => $this->decide($lead, 'merge_skus', $dup, ['sku_id' => $keep, 'merge_from_sku_id' => $x['sku']]));
        self::assertNull(self::$db->value('SELECT merged_into_sku_id FROM sku WHERE id = ?', [$x['sku']]), 'nothing merged, nothing moved');
        self::assertSame([4, 0, 3], [$this->incoming($x['sku']), $this->onHand($x['sku']), $this->onHand($keep)]);
        // Received: the units are on hand on the item the invoice names, and the merge moves them as any stock.
        $g = $this->arrivals->receive($desk, $p->id);
        $this->invoice($desk, $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id);
        $this->post($desk, $g->id);
        $r = $this->decide($lead, 'merge_skus', $dup, ['sku_id' => $keep, 'merge_from_sku_id' => $x['sku']]);
        self::assertSame('applied', $r['state']);
        self::assertSame([0, 0, 7], [$this->incoming($x['sku']), $this->onHand($x['sku']), $this->onHand($keep)]);
    }

    public function testWhoMayDoWhat(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Who Arrives Ltd');
        $this->refusedCode('role_not_allowed', fn () => $this->arrivals->setPrefs($this->staffUser('goods_in'), (int) $s['id'], false, 'count'));
        $this->refusedCode('bad_mode', fn () => $this->arrivals->setPrefs($buyer, (int) $s['id'], true, 'sometimes'));
        // Not changed while a supplier waits for a reviewer's OK, as its other details.
        $w = $this->draft($buyer, ['name' => 'Waiting Arrivals Ltd']);
        $w = $this->sup->requestActivation($buyer, (int) $w['id'], (int) $w['version']);
        $this->refusedCode('supplier_pending', fn () => $this->arrivals->setPrefs($buyer, (int) $w['id'], false, 'count'));
        self::assertTrue($this->arrivals->prefsOf((int) $w['id'])['sell_before'], 'unchanged: the default');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 2);
        $p = $this->invoices->submit($desk, $x['doc']->id, $x['doc']->version);
        $this->refusedCode('role_not_allowed', fn () => $this->arrivals->receive($this->staffUser('reviewer'), $p->id));
        $this->refusedCode('role_not_allowed', fn () => $this->invoices->setReceiving($this->staffUser('goods_in'), $p->id, $p->version, 'count', []));
    }
}
