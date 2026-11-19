<?php

declare(strict_types=1);

namespace CW\Tests\Integration\PurchaseOrders;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Documents\DraftChanges;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Tests\Support\LineUids;

/**
 * Each box of a draft order saves by itself (the live drafts plan, step 4, sections 4.2 and 5; docs/decisions.md LS1-LS14): one line or one
 * head box at a time, through PurchaseOrders::setLine / addLineLive / removeLine / restoreLine / setHeaderField, each with the line's own
 * version (document_line.rev) the page saw. Every row of the plan's section 5 table:
 *  - different lines at the same moment: both saved; the same line at different moments: saved;
 *  - the same line at the same moment: the first wins, the second is refused for that line only (409 line_conflict, naming who and with
 *    their values; a draft_change row `conflict`), and "Use mine" with the new version saves it;
 *  - both typed the same figure: saved once, no question (a save of what is stored is 200 and changes nothing);
 *  - the same head box: 409 field_conflict; a line someone removed: 409 line_gone; removing a line someone just changed: 409 line_conflict;
 *    both remove: done once; both add different products: both, in order; the same product within 2 minutes: 409 line_exists, unless asked
 *    for more; a retried add with the same key: done once;
 *  - Undo puts back MY removed line only, at its place, with a new id;
 *  - a confirmed or cancelled order refuses every one (409 not_draft) and changes nothing; Confirm with a stale version is refused;
 *  - the jobs are checked on every one (doc.PO.post, never admin, roles read again), never the creator;
 *  - the in-place paths and the whole save (saveDraft) leave identical rows over random sequences; the pack conversion of one line is
 *    saveDraft's; one audit row per person per draft per hour.
 */
final class LiveLinesTest extends PurchaseOrderTestCase
{
    use LineUids;

    private \DateTimeImmutable $liveNow;
    private Caller $fazil;
    private Caller $shareef;
    /** @var array<string, mixed> */
    private array $vendor;
    /** @var array<string, int> name => supplier item id */
    private array $si = [];
    /** @var array<string, int> name => sku id */
    private array $sku = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveNow = new \DateTimeImmutable('2026-11-19 10:00:00.000000', Clock::utc());
        $clock = fn (): \DateTimeImmutable => $this->liveNow;
        $this->docs = new Documents(self::$db, DocumentHandlers::all(self::$db), $clock);
        $this->pos = new PurchaseOrders(self::$db, $this->docs, null, null, $clock);
        $this->fazil = $this->staffUser('buyer');
        $this->shareef = $this->staffUser('buyer');
        $this->vendor = $this->activeSupplier($this->fazil, ['name' => 'Live ' . bin2hex(random_bytes(3))]);
        foreach ([['pods', 10, '29.1000'], ['liquid', 1, '0.9200'], ['tank', 6, '12.0000'], ['coil', 5, '4.5000'], ['glass', 1, '1.2500']] as [$name, $upp, $price]) {
            $this->sku[$name] = self::makeSku('Live ' . $name . ' ' . bin2hex(random_bytes(2)));
            $this->si[$name] = (int) $this->supplierItem($this->fazil, (int) $this->vendor['id'], $this->sku[$name], $upp, $price, ['supplier_code' => strtoupper($name)])['id'];
        }
        $this->sku['loose'] = self::makeSku('Live loose ' . bin2hex(random_bytes(2)));
    }

    private function later(string $modify): void
    {
        $this->liveNow = $this->liveNow->modify($modify);
    }

    /** A draft of Fazil's: pods 3, liquid 50, loose 2 packs of 5 at £4.00 (uids 1, 2, 3). */
    private function liveDraft(): int
    {
        $d = $this->draftPo($this->fazil, (int) $this->vendor['id'], [['supplier_item_id' => $this->si['pods'], 'packs' => 3], ['supplier_item_id' => $this->si['liquid'], 'packs' => 50],
            ['sku_id' => $this->sku['loose'], 'units_per_pack' => 5, 'packs' => 2, 'pack_price' => '4.00']]);
        self::assertSame([1, 2, 3], self::uidsOf($d->id));
        return $d->id;
    }

    private function nameOf(Caller $c): string
    {
        return (string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$c->staffUserId]);
    }

    private static function rev(int $id, int $uid): int
    {
        return (int) self::$db->value('SELECT rev FROM document_line WHERE document_id = ? AND uid = ?', [$id, $uid]);
    }

    private static function docVersion(int $id): int
    {
        return (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed> the po_line and document_line of a line by its id */
    private static function lineOf(int $id, int $uid): array
    {
        return (array) self::$db->one('SELECT dl.line_no, dl.uid, dl.rev, dl.changed_by, dl.changed_at, dl.sku_id, dl.qty, dl.unit_cost, dl.amount, pl.supplier_item_id, pl.packs, '
            . 'pl.pack_price, pl.units_per_pack, pl.kind FROM document_line dl JOIN po_line pl ON pl.document_id = dl.document_id AND pl.line_no = dl.line_no '
            . 'WHERE dl.document_id = ? AND dl.uid = ?', [$id, $uid]);
    }

    /** @return list<array<string, mixed>> */
    private static function changes(int $id): array
    {
        return self::$db->all("SELECT staff_user_id, op, line_uid, field, op_key, doc_version, CAST(before_json AS CHAR) AS b, CAST(after_json AS CHAR) AS a FROM draft_change "
            . "WHERE kind = 'po' AND record_id = ? ORDER BY id", [$id]);
    }

    private static function refusal(callable $fn): CwException
    {
        try {
            $fn();
        } catch (CwException $e) {
            return $e;
        }
        self::fail('expected a refusal');
    }

    /** @return array<string, mixed> every row of a draft a change could touch */
    private static function snapshot(int $id): array
    {
        return ['doc' => self::$db->one('SELECT status, version, next_line_uid FROM document WHERE id = ?', [$id]),
            'lines' => self::$db->all('SELECT * FROM document_line WHERE document_id = ? ORDER BY line_no', [$id]),
            'po' => self::$db->all('SELECT * FROM po_line WHERE document_id = ? ORDER BY line_no', [$id]),
            'head' => self::$db->one('SELECT net_total, vat_total, gross_total, expected_date FROM purchase_order WHERE document_id = ?', [$id]),
            'changes' => self::$db->value("SELECT COUNT(*) FROM draft_change WHERE kind = 'po' AND record_id = ? AND op <> 'conflict'", [$id])];
    }

    public function testDifferentLinesAtTheSameMomentAreBothSavedAndTheSameLineLaterToo(): void
    {
        $id = $this->liveDraft();
        $v = self::docVersion($id);
        $r = $this->pos->setLine($this->fazil, $id, 1, 1, ['packs' => '8'], 'aaaaaaaaaaaaaaaa');
        self::assertSame(['saved', 1, 2], [$r['status'], $r['uid'], $r['rev']]);
        self::assertSame($v + 1, $r['document']->version, 'the draft\'s version moves by one');
        // Shareef's page still shows version $v: a different line is saved all the same.
        $r = $this->pos->setLine($this->shareef, $id, 2, 1, ['pack_price' => '0.95']);
        self::assertSame(['saved', 2], [$r['status'], $r['rev']]);
        self::assertSame([8, '29.1000'], [(int) self::lineOf($id, 1)['packs'], (string) self::lineOf($id, 1)['pack_price']]);
        self::assertSame([50, '0.9500'], [(int) self::lineOf($id, 2)['packs'], (string) self::lineOf($id, 2)['pack_price']]);
        self::assertSame([$this->fazil->staffUserId, $this->shareef->staffUserId], [(int) self::lineOf($id, 1)['changed_by'], (int) self::lineOf($id, 2)['changed_by']]);
        self::assertSame('2026-11-19 10:00:00.000000', (string) self::lineOf($id, 1)['changed_at']);
        // The same line at a later moment (Fazil's change had arrived on Shareef's screen: he saw rev 2): saved.
        $r = $this->pos->setLine($this->shareef, $id, 1, 2, ['packs' => '9']);
        self::assertSame(['saved', 3], [$r['status'], $r['rev']]);
        self::assertSame(9, (int) self::lineOf($id, 1)['packs']);
        // Totals: the sum of the lines (P6 is checked after every test too): 9 × 29.10 + 50 × 0.95 + 2 × 4.00.
        self::assertSame('317.40', number_format((float) $this->poRow($id)['net_total'], 2, '.', ''));
        self::assertSame(['set', 'set', 'set'], array_column(self::changes($id), 'op'));
        self::assertSame(['aaaaaaaaaaaaaaaa', null, null], array_map(static fn (array $r): ?string => $r['tab'] === null ? null : (string) $r['tab'],
            self::$db->all('SELECT tab FROM draft_change WHERE record_id = ? ORDER BY id', [$id])));
        $first = self::changes($id)[0];
        self::assertSame([3, 8], [json_decode((string) $first['b'], true)['packs'], json_decode((string) $first['a'], true)['packs']]);
        self::assertSame($v + 1, (int) $first['doc_version']);
    }

    public function testTheSameLineAtTheSameMomentHasOneWinnerAndTheOtherChooses(): void
    {
        $id = $this->liveDraft();
        $this->pos->setLine($this->fazil, $id, 1, 1, ['packs' => '8', 'pack_price' => '12.50']);
        $before = self::snapshot($id);
        $e = self::refusal(fn () => $this->pos->setLine($this->shareef, $id, 1, 1, ['packs' => '6'], 'bbbbbbbbbbbbbbbb'));
        self::assertSame(['line_conflict', 409], [$e->errorCode, $e->httpStatus]);
        self::assertSame([1, 2, 1, $this->fazil->staffUserId, $this->nameOf($this->fazil), '2026-11-19 10:00:00.000000'],
            [$e->detail['uid'], $e->detail['rev'], $e->detail['line'], $e->detail['by_id'], $e->detail['by'], $e->detail['at']]);
        self::assertSame([8, '12.5000', 10, $this->si['pods']], [$e->detail['theirs']['packs'], $e->detail['theirs']['pack_price'], $e->detail['theirs']['units_per_pack'],
            $e->detail['theirs']['supplier_item_id']]);
        self::assertStringStartsWith('Live pods', (string) $e->detail['theirs']['name']);
        self::assertSame($before, self::snapshot($id), 'nothing of Shareef\'s was saved');
        $c = self::changes($id);
        self::assertSame(['set', 'conflict'], array_column($c, 'op'), 'the clash is measured (the plan\'s 7.4)');
        self::assertSame([$this->shareef->staffUserId, 1, 6, 1], [(int) $c[1]['staff_user_id'], (int) $c[1]['line_uid'], (int) json_decode((string) $c[1]['a'], true)['packs'],
            json_decode((string) $c[1]['a'], true)['rev']], 'what he tried, on the version he saw');
        self::assertSame('bbbbbbbbbbbbbbbb', (string) self::$db->value("SELECT tab FROM draft_change WHERE record_id = ? AND op = 'conflict'", [$id]));
        // Every other line of Shareef's is saved meanwhile (only that line was refused).
        self::assertSame('saved', $this->pos->setLine($this->shareef, $id, 2, 1, ['packs' => '40'])['status']);
        // "Use mine": his value again with the version now.
        $r = $this->pos->setLine($this->shareef, $id, 1, 2, ['packs' => '6']);
        self::assertSame(['saved', 3], [$r['status'], $r['rev']]);
        self::assertSame([6, '12.5000'], [(int) self::lineOf($id, 1)['packs'], (string) self::lineOf($id, 1)['pack_price']], 'only the box he changed');
    }

    public function testBothTypedTheSameFigureIsSavedOnceAndAStoredValueChangesNothing(): void
    {
        $id = $this->liveDraft();
        $this->pos->setLine($this->fazil, $id, 1, 1, ['packs' => '8']);
        $before = self::snapshot($id);
        $r = $this->pos->setLine($this->shareef, $id, 1, 1, ['packs' => '8']);
        self::assertSame(['unchanged', 2], [$r['status'], $r['rev']], 'no question');
        self::assertSame($before, self::snapshot($id), 'nothing written: not the version');
        self::assertSame(['set'], array_column(self::changes($id), 'op'), 'no row for Shareef, no clash');
        // A retry of a save that arrived (its answer lost): the same.
        self::assertSame('unchanged', $this->pos->setLine($this->fazil, $id, 1, 1, ['packs' => ' 8 '])['status']);
        self::assertSame('unchanged', $this->pos->setLine($this->fazil, $id, 3, 1, ['pack_price' => '4', 'units_per_pack' => '5'])['status'], '£4 is £4.00');
        self::assertSame($before, self::snapshot($id));
    }

    public function testTheSameHeadBoxAtTheSameMomentHasTheSameChoice(): void
    {
        $id = $this->liveDraft();
        $r = $this->pos->setHeaderField($this->fazil, $id, 'note', null, '  Call before delivery ');
        self::assertSame(['saved', 'Call before delivery'], [$r['status'], $r['value']], 'the value as this save stored it');
        self::assertSame('Call before delivery', $this->docs->get($id)->note);
        $before = self::snapshot($id);
        $e = self::refusal(fn () => $this->pos->setHeaderField($this->shareef, $id, 'note', '', 'Ring first'));
        self::assertSame(['field_conflict', 409, 'note', 'Call before delivery', $this->fazil->staffUserId, $this->nameOf($this->fazil)],
            [$e->errorCode, $e->httpStatus, $e->detail['field'], $e->detail['theirs'], $e->detail['by_id'], $e->detail['by']]);
        self::assertSame($before, self::snapshot($id));
        self::assertSame('unchanged', $this->pos->setHeaderField($this->shareef, $id, 'note', '', 'Call before delivery')['status'], 'the same words: no question');
        self::assertSame('saved', $this->pos->setHeaderField($this->shareef, $id, 'note', 'Call before delivery', 'Ring first')['status'], 'use mine');
        self::assertSame('Ring first', $this->docs->get($id)->note);
        self::assertSame('saved', $this->pos->setHeaderField($this->shareef, $id, 'expected_date', null, '2026-11-25')['status']);
        self::assertSame('2026-11-25', (string) $this->poRow($id)['expected_date']);
        self::assertSame('saved', $this->pos->setHeaderField($this->fazil, $id, 'external_ref', '', 'Q-77')['status']);
        self::assertSame('Q-77', $this->docs->get($id)->externalRef);
        self::assertSame('saved', $this->pos->setHeaderField($this->fazil, $id, 'external_ref', 'Q-77', '')['status'], 'emptied');
        self::assertNull($this->docs->get($id)->externalRef);
        self::assertSame('bad_field', self::refusal(fn () => $this->pos->setHeaderField($this->fazil, $id, 'expected_date', '2026-11-25', '2026-02-31'))->errorCode);
        self::assertSame('bad_field', self::refusal(fn () => $this->pos->setHeaderField($this->fazil, $id, 'supplier_id', null, '3'))->errorCode, 'the supplier is a whole save');
        self::assertSame(['header', 'conflict', 'header', 'header', 'header', 'header'], array_column(self::changes($id), 'op'));
        self::assertSame(['note', 'note', 'note', 'expected_date', 'external_ref', 'external_ref'], array_column(self::changes($id), 'field'));
    }

    public function testALineSomeoneRemovedIsGoneAndPuttingItBackIsAnAdd(): void
    {
        $id = $this->liveDraft();
        $r = $this->pos->removeLine($this->fazil, $id, 2, 1);
        self::assertSame('removed', $r['status']);
        self::assertSame([1, 3], self::uidsOf($id), 'the others keep their ids and move up');
        self::assertSame([1, 2], array_map('intval', self::$db->column('SELECT line_no FROM document_line WHERE document_id = ? ORDER BY line_no', [$id])));
        self::assertSame([1, 1], [self::rev($id, 1), self::rev($id, 3)], 'a line moved up is not a line changed');
        $e = self::refusal(fn () => $this->pos->setLine($this->shareef, $id, 2, 1, ['packs' => '9']));
        self::assertSame(['line_gone', 409, 2, $this->fazil->staffUserId, $this->nameOf($this->fazil)], [$e->errorCode, $e->httpStatus, $e->detail['uid'], $e->detail['by_id'],
            $e->detail['by']]);
        self::assertStringStartsWith('Live liquid', (string) $e->detail['name']);
        // "Put it back with my change": his line again, as a new line with his value.
        $r = $this->pos->addLineLive($this->shareef, $id, ['supplier_item_id' => $this->si['liquid']], 9, null, '1111111111111111');
        self::assertSame(['added', 4], [$r['status'], $r['uid']]);
        self::assertSame([1, 3, 4], self::uidsOf($id));
        self::assertSame([9, '0.9200'], [(int) self::lineOf($id, 4)['packs'], (string) self::lineOf($id, 4)['pack_price']]);
    }

    public function testRemovingALineSomeoneJustChangedAsksAndBothRemovingIsDoneOnce(): void
    {
        $id = $this->liveDraft();
        $this->pos->setLine($this->fazil, $id, 1, 1, ['packs' => '8']);
        $before = self::snapshot($id);
        $e = self::refusal(fn () => $this->pos->removeLine($this->shareef, $id, 1, 1));
        self::assertSame(['line_conflict', 2, 8], [$e->errorCode, $e->detail['rev'], $e->detail['theirs']['packs']]);
        self::assertSame($before, self::snapshot($id));
        self::assertSame('removed', $this->pos->removeLine($this->shareef, $id, 1, 2)['status'], 'remove it anyway');
        self::assertSame([2, 3], self::uidsOf($id));
        // Both remove the same line: done once, no question.
        self::assertSame('removed', $this->pos->removeLine($this->fazil, $id, 3, 1)['status']);
        $before = self::snapshot($id);
        self::assertSame('unchanged', $this->pos->removeLine($this->shareef, $id, 3, 1)['status']);
        self::assertSame($before, self::snapshot($id));
        self::assertSame([2], self::uidsOf($id));
        self::assertSame(['set', 'conflict', 'remove', 'remove'], array_column(self::changes($id), 'op'));
        $removed = json_decode((string) self::changes($id)[2]['b'], true);
        self::assertSame([8, $this->si['pods'], 1], [$removed['packs'], $removed['supplier_item_id'], $removed['line']], 'enough to put it back');
    }

    public function testBothAddDifferentProductsAndTheSameProductWithinTwoMinutesAsks(): void
    {
        $id = $this->liveDraft();
        $a = $this->pos->addLineLive($this->fazil, $id, ['supplier_item_id' => $this->si['tank']], 5, null, 'a000000000000001');
        $b = $this->pos->addLineLive($this->shareef, $id, ['supplier_item_id' => $this->si['coil']], 2, '4.00', 'b000000000000001');
        self::assertSame([['added', 4], ['added', 5]], [[$a['status'], $a['uid']], [$b['status'], $b['uid']]], 'both, in the order they arrived');
        self::assertSame([4, 5], [(int) self::lineOf($id, 4)['line_no'], (int) self::lineOf($id, 5)['line_no']]);
        self::assertSame(['12.0000', '4.0000'], [(string) self::lineOf($id, 4)['pack_price'], (string) self::lineOf($id, 5)['pack_price']], 'the pack\'s price, or the one typed');
        self::assertSame([1, 1], [self::rev($id, 4), self::rev($id, 5)]);
        self::assertSame(6, self::nextUidOf($id));

        // Shareef adds the tank Fazil added a minute ago: asked, never added silently (a doubled order is a stock mistake).
        $this->later('+1 minute');
        $before = self::snapshot($id);
        $e = self::refusal(fn () => $this->pos->addLineLive($this->shareef, $id, ['supplier_item_id' => $this->si['tank']], 5, null, 'b000000000000002'));
        self::assertSame(['line_exists', 409, 4, 4, 5, $this->fazil->staffUserId, $this->nameOf($this->fazil)],
            [$e->errorCode, $e->httpStatus, $e->detail['uid'], $e->detail['line'], $e->detail['packs'], $e->detail['by_id'], $e->detail['by']]);
        self::assertSame($before, self::snapshot($id));
        // "Add 5 more": the packs go on Fazil's line.
        $r = $this->pos->addLineLive($this->shareef, $id, ['supplier_item_id' => $this->si['tank']], 5, null, 'b000000000000002', true);
        self::assertSame(['incremented', 4, 2], [$r['status'], $r['uid'], self::rev($id, 4)]);
        self::assertSame(10, (int) self::lineOf($id, 4)['packs']);
        // His own add of the same: today's rule (the packs added), no question.
        self::assertSame('incremented', $this->pos->addLineLive($this->shareef, $id, ['supplier_item_id' => $this->si['tank']], 1, null, 'b000000000000003')['status']);
        self::assertSame(11, (int) self::lineOf($id, 4)['packs']);
        // Fazil's own line, changed by Shareef a moment ago: Fazil is asked.
        self::assertSame('line_exists', self::refusal(fn () => $this->pos->addLineLive($this->fazil, $id, ['supplier_item_id' => $this->si['tank']], 1, null, 'a000000000000002'))
            ->errorCode);
        // Two minutes later nobody is asked.
        $this->later('+2 minutes');
        self::assertSame('incremented', $this->pos->addLineLive($this->fazil, $id, ['supplier_item_id' => $this->si['tank']], 1, null, 'a000000000000002')['status']);
        self::assertSame(12, (int) self::lineOf($id, 4)['packs']);
        // An item not set up with the supplier: the same item and pack is the same line.
        $l = $this->pos->addLineLive($this->fazil, $id, ['sku_id' => $this->sku['loose'], 'units_per_pack' => 5], 1, null, 'a000000000000003');
        self::assertSame(['incremented', 3], [$l['status'], $l['uid']]);
        $l = $this->pos->addLineLive($this->fazil, $id, ['sku_id' => $this->sku['loose'], 'units_per_pack' => 4], 1, '3.00', 'a000000000000004');
        self::assertSame(['added', 6], [$l['status'], $l['uid']], 'another pack: another line');
        // The delivery typed: a charge line (never "the same product").
        $c = $this->pos->addLineLive($this->fazil, $id, ['kind' => 'charge', 'description' => 'Delivery'], 1, '5.00', 'a000000000000005');
        $c2 = $this->pos->addLineLive($this->fazil, $id, ['kind' => 'charge', 'description' => 'Delivery'], 1, '5.00', 'a000000000000006');
        self::assertSame([['added', 7], ['added', 8]], [[$c['status'], $c['uid']], [$c2['status'], $c2['uid']]]);
        self::assertSame(['charge', '5.0000'], [(string) self::lineOf($id, 7)['kind'], (string) self::lineOf($id, 7)['pack_price']]);
    }

    public function testARetriedAddWithTheSameKeyIsDoneOnce(): void
    {
        $id = $this->liveDraft();
        $r = $this->pos->addLineLive($this->fazil, $id, ['supplier_item_id' => $this->si['glass']], 4, null, 'c0ffee0000000001');
        $before = self::snapshot($id);
        $again = $this->pos->addLineLive($this->fazil, $id, ['supplier_item_id' => $this->si['glass']], 4, null, 'c0ffee0000000001');
        self::assertSame(['added', 'done', $r['uid']], [$r['status'], $again['status'], $again['uid']]);
        self::assertSame(['added', 1, 'added', 1], [$r['status'], $r['rev'], $again['was'], $again['rev']], 'the replay says what the first time did, and the version it gave');
        // An add that went on a line already there, sent again: it says so (the page never takes its figures for a new line's).
        $more = $this->pos->addLineLive($this->fazil, $id, ['supplier_item_id' => $this->si['glass']], 2, null, 'c0ffee0000000002');
        $more2 = $this->pos->addLineLive($this->fazil, $id, ['supplier_item_id' => $this->si['glass']], 2, null, 'c0ffee0000000002');
        // ... with the version it gave that line (the page puts packs typed since on that version: the Codex verify of be061fd, 1).
        self::assertSame(['incremented', 2, 'done', 'incremented', 2], [$more['status'], $more['rev'], $more2['status'], $more2['was'], $more2['rev']]);
        $before = self::snapshot($id);
        self::assertSame($before, self::snapshot($id), 'done once');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM draft_change WHERE record_id = ? AND op_key = 'c0ffee0000000001'", [$id]));
        self::assertSame('bad_key', self::refusal(fn () => $this->pos->addLineLive($this->fazil, $id, ['supplier_item_id' => $this->si['glass']], 1, null, 'NOT A KEY'))->errorCode);
    }

    public function testUndoPutsBackMyOwnRemovedLineAtItsPlaceWithANewId(): void
    {
        $id = $this->liveDraft();
        $this->pos->setLine($this->shareef, $id, 2, 1, ['packs' => '40', 'pack_price' => '0.99']);
        $this->pos->removeLine($this->shareef, $id, 2, 2);
        self::assertSame([1, 3], self::uidsOf($id));
        // Fazil cannot undo Shareef's removal.
        self::assertSame('nothing_to_restore', self::refusal(fn () => $this->pos->restoreLine($this->fazil, $id, 2, 1, 'd000000000000001'))->errorCode);
        $r = $this->pos->restoreLine($this->shareef, $id, 2, 1, 'e000000000000001');
        self::assertSame(['restored', 4], [$r['status'], $r['uid']]);
        self::assertSame([1, 4, 3], self::uidsOf($id), 'at its place (after line 1), with a new id');
        self::assertSame([40, '0.9900', $this->si['liquid'], 1], [(int) self::lineOf($id, 4)['packs'], (string) self::lineOf($id, 4)['pack_price'],
            (int) self::lineOf($id, 4)['supplier_item_id'], (int) self::lineOf($id, 4)['rev']]);
        $before = self::snapshot($id);
        $again = $this->pos->restoreLine($this->shareef, $id, 2, 1, 'e000000000000001');
        self::assertSame(['done', 4], [$again['status'], $again['uid']], 'a retried undo: done once');
        self::assertSame($before, self::snapshot($id));
        self::assertSame('nothing_to_restore', self::refusal(fn () => $this->pos->restoreLine($this->shareef, $id, 2, 1, 'e00000000000000f'))->errorCode,
            'put back already: another key puts it back no second time');
        self::assertSame($before, self::snapshot($id));
        // The line before it gone meanwhile: back where it was, as near as the lines left allow (here: last).
        $this->pos->removeLine($this->shareef, $id, 3, 1);
        $this->pos->removeLine($this->fazil, $id, 1, 1);
        $r = $this->pos->restoreLine($this->shareef, $id, 3, 1, 'e000000000000002');
        self::assertSame(['restored', 5], [$r['status'], $r['uid']]);
        self::assertSame([4, 5], self::uidsOf($id));
        // The same product added by someone meanwhile: the question of both adding the same product.
        $this->pos->removeLine($this->shareef, $id, 5, 1);
        $this->pos->addLineLive($this->fazil, $id, ['sku_id' => $this->sku['loose'], 'units_per_pack' => 5], 1, '4.00', 'f000000000000001');
        $e = self::refusal(fn () => $this->pos->restoreLine($this->shareef, $id, 5, 4, 'e000000000000003'));
        self::assertSame(['line_exists', 6], [$e->errorCode, $e->detail['uid']]);
        self::assertSame('incremented', $this->pos->restoreLine($this->shareef, $id, 5, 4, 'e000000000000003', true)['status']);
        self::assertSame(3, (int) self::lineOf($id, 6)['packs'], 'his 2 packs added to Fazil\'s 1');
        self::assertSame(['set', 'remove', 'restore', 'remove', 'remove', 'restore', 'remove', 'add', 'restore'], array_column(self::changes($id), 'op'));
    }

    public function testAConfirmedOrCancelledOrderRefusesEveryChangeAndChangesNothing(): void
    {
        $id = $this->liveDraft();
        $d = $this->docs->get($id);
        $this->pos->approve($this->fazil, $id, $d->version);
        $number = (string) $this->docs->get($id)->number;
        $before = self::snapshot($id);
        foreach ([
            fn () => $this->pos->setLine($this->shareef, $id, 1, 1, ['packs' => '9']),
            fn () => $this->pos->addLineLive($this->shareef, $id, ['supplier_item_id' => $this->si['tank']], 1, null, '9000000000000001'),
            fn () => $this->pos->removeLine($this->shareef, $id, 1, 1),
            fn () => $this->pos->restoreLine($this->shareef, $id, 1, null, '9000000000000002'),
            fn () => $this->pos->setHeaderField($this->shareef, $id, 'note', null, 'x'),
        ] as $i => $fn) {
            $e = self::refusal($fn);
            self::assertSame(['not_draft', 409, 'posted', $number, $this->nameOf($this->fazil)], [$e->errorCode, $e->httpStatus, $e->detail['status'], $e->detail['number'],
                $e->detail['by']], "op {$i}");
        }
        self::assertSame($before, self::snapshot($id), 'nothing changed');

        $id = $this->liveDraft();
        $this->pos->cancel($this->fazil, $id, $this->docs->get($id)->version, 'not_needed', null);
        $e = self::refusal(fn () => $this->pos->setLine($this->shareef, $id, 1, 1, ['packs' => '9']));
        self::assertSame(['not_draft', 'cancelled', null, $this->nameOf($this->fazil)], [$e->errorCode, $e->detail['status'], $e->detail['number'], $e->detail['by']]);
    }

    public function testConfirmWithAStaleVersionIsRefused(): void
    {
        $id = $this->liveDraft();
        $shown = self::docVersion($id);
        $this->pos->setLine($this->fazil, $id, 3, 1, ['packs' => '4']);
        $e = self::refusal(fn () => $this->pos->approve($this->shareef, $id, $shown));
        self::assertSame(['version_conflict', $this->fazil->staffUserId, $this->nameOf($this->fazil)], [$e->errorCode, $e->detail['changed_by_id'], $e->detail['changed_by']],
            'naming who changed it, from the line-by-line changes');
        self::assertSame('draft', $this->docs->get($id)->status);
        self::assertSame('posted', $this->pos->approve($this->shareef, $id, self::docVersion($id))->status, 'the order seen is the order confirmed');
    }

    public function testTheJobIsCheckedOnEveryChangeNeverTheCreatorAndAdminIsRefused(): void
    {
        $id = $this->liveDraft();
        $ops = [
            'set' => fn (Caller $c) => $this->pos->setLine($c, $id, 1, self::rev($id, 1), ['packs' => (string) random_int(10, 99)]),
            'add' => fn (Caller $c) => $this->pos->addLineLive($c, $id, ['supplier_item_id' => $this->si['glass']], 1, null, bin2hex(random_bytes(8)), true),
            'remove' => fn (Caller $c) => $this->pos->removeLine($c, $id, 2, self::rev($id, 2)),
            'restore' => fn (Caller $c) => $this->pos->restoreLine($c, $id, 2, 1, bin2hex(random_bytes(8))),
            'head' => fn (Caller $c) => $this->pos->setHeaderField($c, $id, 'note', $this->docs->get($id)->note, 'by ' . $c->staffUserId),
        ];
        $desk = $this->staffUser('purchasing_desk');
        $admin = $this->staffUser(['admin', 'buyer']);
        $off = $this->staffUser('buyer', false);
        foreach ($ops as $what => $fn) {
            self::assertSame('role_not_allowed', self::refusal(fn () => $fn($desk))->errorCode, "{$what}: someone who only looks");
            self::assertSame('admin_cannot_post', self::refusal(fn () => $fn($admin))->errorCode, "{$what}: admin never writes documents (I12)");
            self::assertContains(self::refusal(fn () => $fn($off))->errorCode, ['role_not_allowed', 'staff_not_allowed'], "{$what}: a person switched off");
            self::assertSame('staff_required', self::refusal(fn () => $fn(Caller::system('test')))->errorCode, "{$what}: staff only");
        }
        // Shareef did not start the draft: every change is his to make (DA1).
        foreach (['set', 'add', 'remove', 'restore', 'head'] as $what) {
            self::assertContains($ops[$what]($this->shareef)['status'], ['saved', 'added', 'incremented', 'removed', 'restored'], $what);
        }
        // The roles are read again on every change: a buyer whose role was taken away is refused at once.
        self::$db->exec("UPDATE staff_role SET revoked_at = NOW(6), revoked_by = ? WHERE staff_user_id = ? AND revoked_at IS NULL", [$this->fazil->staffUserId,
            $this->shareef->staffUserId]);
        self::assertSame('role_not_allowed', self::refusal(fn () => $ops['set']($this->shareef))->errorCode);
    }

    public function testOneLinesPackConversionIsTheWholeSavesAndTheProductChangesInPlace(): void
    {
        $id = $this->liveDraft();
        // Items per pack, packs and price of the loose line: qty = packs × items per pack, the unit cost per item, the amount per pack.
        $r = $this->pos->setLine($this->fazil, $id, 3, 1, ['units_per_pack' => '12', 'packs' => '7', 'pack_price' => '10.0001']);
        self::assertSame('saved', $r['status']);
        $l = self::lineOf($id, 3);
        $twin = $this->draftPo($this->fazil, (int) $this->vendor['id'], [['sku_id' => $this->sku['loose'], 'units_per_pack' => 12, 'packs' => 7, 'pack_price' => '10.0001']]);
        $w = (array) self::$db->one('SELECT dl.qty, dl.unit_cost, dl.amount, pl.units_per_pack, pl.packs, pl.pack_price, pl.purchase_unit FROM document_line dl JOIN po_line pl '
            . 'ON pl.document_id = dl.document_id AND pl.line_no = dl.line_no WHERE dl.document_id = ?', [$twin->id]);
        self::assertSame([84, (string) $w['unit_cost'], (string) $w['amount'], 12, 7, '10.0001'], [(int) $l['qty'], (string) $l['unit_cost'], (string) $l['amount'],
            (int) $l['units_per_pack'], (int) $l['packs'], (string) $l['pack_price']]);
        self::assertSame([84, '0.833342', '70.00'], [(int) $w['qty'], (string) $w['unit_cost'], number_format((float) $w['amount'], 2, '.', '')]);
        // A line's product changed in place: the same line and id, the new pack and its price; the packs kept.
        $r = $this->pos->setLine($this->fazil, $id, 1, 1, ['supplier_item_id' => $this->si['tank']]);
        self::assertSame(['saved', 1, 2], [$r['status'], $r['uid'], $r['rev']]);
        $l = self::lineOf($id, 1);
        self::assertSame([1, $this->si['tank'], $this->sku['tank'], 6, 3, '12.0000', 18], [(int) $l['line_no'], (int) $l['supplier_item_id'], (int) $l['sku_id'],
            (int) $l['units_per_pack'], (int) $l['packs'], (string) $l['pack_price'], (int) $l['qty']]);
        // To an item not set up with the supplier, its items per pack given.
        $r = $this->pos->setLine($this->fazil, $id, 1, 2, ['sku_id' => $this->sku['loose'], 'units_per_pack' => '3', 'pack_price' => '2.00']);
        $l = self::lineOf($id, 1);
        self::assertSame([null, $this->sku['loose'], 3, 9], [$l['supplier_item_id'], (int) $l['sku_id'], (int) $l['units_per_pack'], (int) $l['qty']]);
        // Refused in words, nothing saved: 0 packs, a price that is not one, another supplier's pack, a line that is a charge given an item.
        foreach ([['packs' => '0'], ['packs' => 'x'], ['pack_price' => '1.23456'], ['supplier_item_id' => 999999999], ['units_per_pack' => '0']] as $bad) {
            $e = self::refusal(fn () => $this->pos->setLine($this->fazil, $id, 2, 1, $bad));
            self::assertContains($e->errorCode, ['bad_line', 'bad_lines'], json_encode($bad));
            self::assertSame(2, $e->detail['line'] ?? null, json_encode($bad) . ': its own line number');
        }
        self::assertSame('bad_field', self::refusal(fn () => $this->pos->setLine($this->fazil, $id, 2, 1, ['vat_code' => 'Z']))->errorCode, 'only the page\'s boxes');
    }

    public function testTheInPlacePathsAndTheWholeSaveLeaveIdenticalRows(): void
    {
        mt_srand(4242);
        $lines = [['supplier_item_id' => $this->si['pods'], 'packs' => 3], ['supplier_item_id' => $this->si['liquid'], 'packs' => 50],
            ['sku_id' => $this->sku['loose'], 'units_per_pack' => 5, 'packs' => 2, 'pack_price' => '4.00']];
        $a = $this->draftPo($this->fazil, (int) $this->vendor['id'], $lines)->id;
        $b = $this->draftPo($this->fazil, (int) $this->vendor['id'], $lines)->id;
        $rows = static function (int $id): array {
            return [
                self::$db->all('SELECT line_no, uid, rev, changed_by, sku_id, warehouse_id, qty, unit_cost, amount, reason_code, description FROM document_line '
                    . 'WHERE document_id = ? ORDER BY line_no', [$id]),
                self::$db->all('SELECT line_no, kind, supplier_item_id, supplier_code, purchase_unit, units_per_pack, packs, pack_price, vat_code, vat_rate, suggested_units, '
                    . 'received_units FROM po_line WHERE document_id = ? ORDER BY line_no', [$id]),
                self::$db->one('SELECT supplier_id, expected_date, net_total, vat_total, gross_total FROM purchase_order WHERE document_id = ?', [$id]),
                (int) self::$db->value('SELECT next_line_uid FROM document WHERE id = ?', [$id]),
            ];
        };
        self::assertSame($rows($a), $rows($b));
        $who = [$this->fazil, $this->shareef];
        $picks = [['supplier_item_id' => $this->si['pods']], ['supplier_item_id' => $this->si['tank']], ['supplier_item_id' => $this->si['glass']],
            ['sku_id' => $this->sku['loose'], 'units_per_pack' => 5], ['sku_id' => $this->sku['loose'], 'units_per_pack' => 2]];
        for ($step = 0; $step < 60; $step++) {
            $me = $who[mt_rand(0, 1)];
            $uids = self::uidsOf($a);
            $op = $uids === [] ? 'add' : ['set', 'set', 'add', 'remove'][mt_rand(0, 3)];
            $whole = $this->pos->lines($b);
            if ($op === 'set') {
                $uid = $uids[mt_rand(0, count($uids) - 1)];
                $at = (int) array_search($uid, array_column($whole, 'uid'), true);
                $fields = match (mt_rand(0, 2)) {
                    0 => ['packs' => (string) mt_rand(1, 30)],
                    1 => ['pack_price' => mt_rand(0, 3000) / 100 . ''],
                    default => $whole[$at]['supplier_item_id'] === null && $whole[$at]['kind'] === 'item' ? ['units_per_pack' => (string) mt_rand(1, 9)]
                        : ['packs' => (string) mt_rand(1, 30)],
                };
                $this->pos->setLine($me, $a, $uid, self::rev($a, $uid), $fields);
                foreach ($fields as $k => $v) {
                    $whole[$at][$k] = $k === 'pack_price' ? $v : (int) $v;
                }
            } elseif ($op === 'remove') {
                $uid = $uids[mt_rand(0, count($uids) - 1)];
                $this->pos->removeLine($me, $a, $uid, self::rev($a, $uid));
                $whole = array_values(array_filter($whole, static fn (array $l): bool => $l['uid'] !== $uid));
            } else {
                $pick = $picks[mt_rand(0, count($picks) - 1)];
                $packs = mt_rand(1, 9);
                $this->pos->addLineLive($me, $a, $pick, $packs, null, bin2hex(random_bytes(8)), true);
                $same = null;
                foreach ($whole as $i => $l) {
                    $p = $pick['supplier_item_id'] ?? null;
                    if ($l['kind'] === 'item' && ($p !== null ? $l['supplier_item_id'] === $p
                        : $l['supplier_item_id'] === null && $l['sku_id'] === $pick['sku_id'] && $l['units_per_pack'] === $pick['units_per_pack'])) {
                        $same = $i;
                        break;
                    }
                }
                if ($same !== null) {
                    $whole[$same]['packs'] += $packs;
                } else {
                    $whole[] = ['kind' => 'item'] + $pick + ['packs' => $packs];
                }
            }
            $this->pos->saveDraft($me, $b, self::docVersion($b), [], $whole);
            self::assertSame($rows($a), $rows($b), "step {$step}: {$op}");
        }
    }

    public function testOneAuditRowPerPersonPerDraftPerHourTheDetailInTheChanges(): void
    {
        $id = $this->liveDraft();
        $audits = fn (?Caller $c = null): int => (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'document' AND entity_id = ? AND action = 'po.live_edit'"
            . ($c === null ? '' : ' AND staff_user_id = ' . (int) $c->staffUserId), [(string) $id]);
        $this->pos->setLine($this->fazil, $id, 1, 1, ['packs' => '4']);
        $this->later('+10 minutes');
        $this->pos->setLine($this->fazil, $id, 1, 2, ['packs' => '5']);
        $this->pos->addLineLive($this->fazil, $id, ['supplier_item_id' => $this->si['glass']], 1, null, 'aa00000000000001');
        $this->pos->setHeaderField($this->fazil, $id, 'note', null, 'n');
        self::assertSame(1, $audits($this->fazil), 'the first change in the hour');
        $this->pos->setLine($this->shareef, $id, 2, 1, ['packs' => '7']);
        self::assertSame([1, 1], [$audits($this->fazil), $audits($this->shareef)], 'per person');
        $this->later('+55 minutes'); // 11:05: another hour
        $this->pos->removeLine($this->fazil, $id, 4, 1);
        self::assertSame(2, $audits($this->fazil));
        $row = self::$db->one("SELECT staff_user_id, detail FROM audit_log WHERE entity_type = 'document' AND entity_id = ? AND action = 'po.live_edit' ORDER BY id DESC LIMIT 1",
            [(string) $id]);
        self::assertSame($this->fazil->staffUserId, (int) $row['staff_user_id']);
        self::assertSame(6, count(array_filter(self::changes($id), static fn (array $c): bool => $c['op'] !== 'conflict')), 'every change in detail, in the changes');
        // The newest change names who (the conflict text, the poll's "saved a change"): the changes are read, not only the audit.
        self::assertSame($this->fazil->staffUserId, DraftChanges::last(self::$db, $id)['staff_user_id']);
        $this->pos->setLine($this->shareef, $id, 2, 2, ['packs' => '8']);
        self::assertSame($this->shareef->staffUserId, DraftChanges::last(self::$db, $id)['staff_user_id']);
    }

    public function testAWholeSaveKeepsTheVersionOfALineItLeavesAsItWas(): void
    {
        $id = $this->liveDraft();
        $lines = $this->pos->lines($id);
        $d = $this->pos->saveDraft($this->shareef, $id, self::docVersion($id), ['note' => 'only the note'], $lines);
        self::assertSame([1, 1, 1], array_map(fn (int $u): int => self::rev($id, $u), [1, 2, 3]), 'nothing of a line changed: its version kept');
        $lines = $this->pos->lines($id);
        $lines[1]['packs'] = 51;
        $lines[2]['vat_code'] = 'Z';
        $this->pos->saveDraft($this->shareef, $id, $d->version, [], $lines);
        self::assertSame([1, 2, 2], array_map(fn (int $u): int => self::rev($id, $u), [1, 2, 3]), 'a line changed (its packs, or only its VAT on the order\'s own row): moved');
        self::assertSame([$this->fazil->staffUserId, $this->shareef->staffUserId, $this->shareef->staffUserId], array_map(fn (int $u): int => (int) self::lineOf($id, $u)['changed_by'],
            [1, 2, 3]), 'a line left as it was keeps who made it');
    }

    public function testTheNightlyCleanUpRemovesTheChangesOfAFinalOrderAfterTheKeepDays(): void
    {
        $draft = $this->liveDraft();
        $final = $this->liveDraft();
        $this->pos->setLine($this->fazil, $draft, 1, 1, ['packs' => '4']);
        $this->pos->setLine($this->fazil, $final, 1, 1, ['packs' => '4']);
        $this->pos->setLine($this->fazil, $final, 2, 1, ['packs' => '5']);
        $this->pos->approve($this->fazil, $final, self::docVersion($final));
        self::$db->exec("UPDATE draft_change SET created_at = NOW(6) - INTERVAL 91 DAY WHERE record_id IN (?, ?)", [$draft, $final]);
        self::$db->exec("UPDATE draft_change SET created_at = NOW(6) - INTERVAL 89 DAY WHERE record_id = ? AND line_uid = 2", [$final]);
        // Confirmed a day ago: its changes are kept 90 days from then, however old (the Codex review of b40f96a, a3).
        self::$db->exec('UPDATE document SET updated_at = NOW(6) - INTERVAL 1 DAY WHERE id = ?', [$final]);
        $none = (new \CW\Ops\Housekeeping(self::$db))->run(new \CW\Settings(self::$db), true);
        self::assertSame(0, $none['draft_change']['removed'], 'confirmed a day ago: nothing of it goes yet');
        self::$db->exec('UPDATE document SET updated_at = NOW(6) - INTERVAL 91 DAY WHERE id = ?', [$final]);
        $dry = (new \CW\Ops\Housekeeping(self::$db))->run(new \CW\Settings(self::$db), true);
        self::assertSame(1, $dry['draft_change']['removed']);
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM draft_change WHERE record_id IN (?, ?)', [$draft, $final]), 'a dry run removes nothing');
        $r = (new \CW\Ops\Housekeeping(self::$db))->run(new \CW\Settings(self::$db));
        self::assertSame([1, true], [$r['draft_change']['removed'], $r['draft_change']['done']]);
        self::assertSame([[$draft, 1], [$final, 2]], array_map(static fn (array $c): array => [(int) $c['record_id'], (int) $c['line_uid']],
            self::$db->all('SELECT record_id, line_uid FROM draft_change WHERE record_id IN (?, ?) ORDER BY id', [$draft, $final])),
            'a draft keeps its changes however old; a final order\'s go after 90 days');
    }
}
