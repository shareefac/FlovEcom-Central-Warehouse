<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Counts;

use CW\Caller;
use CW\Counts\CountSheets;
use CW\Counts\StockDoubt;

/**
 * Doubtful stock (0066; docs/decisions.md DS1-): the load (a dry run writes nothing; an open row is refreshed, never doubled; an unknown or
 * merged item refuses the whole list; a row answered in the same list is not opened again), "Save count" (ONE count of that ONE item at the
 * store's selling warehouse through the counts engine: a one-line count sheet, posted, its ledger row; the row cleared with what was counted
 * and booked, in one transaction under the key doubt:<id>: a second click replays the first answer, another answer is refused 409), its
 * refusals (nothing booked), "Not needed" (a note, nothing booked) and the list the page shows (open rows of one store, CW's figure now).
 */
final class StockDoubtTest extends CountsTestCase
{
    private StockDoubt $doubt;
    private Caller $site;
    private Caller $counter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doubt = new StockDoubt(self::$db, $this->sheets);
        $this->site = $this->site('vapeandgo');
        $this->counter = $this->staffUser('stock_controller');
    }

    private static function code(int $sku): string
    {
        return (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
    }

    /** A parsed row of a list (StockDoubt::readFile's shape). @return array<string, mixed> */
    private static function row(int $sku, string $reason = 'differs', ?int $shop = 10, int $cw = 12, ?int $sold = 5, ?string $note = null, int $n = 1): array
    {
        return ['row' => $n, 'cw_code' => self::code($sku), 'shop_qty' => $shop, 'cw_qty' => $cw, 'reason' => $reason, 'sold_30d' => $sold, 'note' => $note];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function load(array $rows, string $batch = 'list_12nov', ?int $channel = null): array
    {
        return $this->doubt->load(Caller::system('doubt_load'), $channel ?? (int) $this->site->channelId, $rows, $batch, 'list.csv');
    }

    private static function openId(int $sku): int
    {
        return (int) self::$db->value("SELECT id FROM stock_doubt WHERE sku_id = ? AND status = 'open'", [$sku]);
    }

    /** @return array<string, mixed> */
    private static function doubtRow(int $id): array
    {
        return (array) self::$db->one('SELECT CAST(status AS CHAR) AS status, counted_qty, booked_delta, count_document_id, resolved_by, resolved_at, resolved_note, '
            . 'shop_qty, cw_qty, CAST(reason AS CHAR) AS reason, sold_30d, batch, note FROM stock_doubt WHERE id = ?', [$id]);
    }

    private static function cntDocs(): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'CNT'");
    }

    // ------------------------------------------------------------------------------------------
    // The load
    // ------------------------------------------------------------------------------------------

    public function testADryRunWritesNothingAndALoadAddsOpenRowsThenRefreshesThemNeverASecond(): void
    {
        $a = $this->item('strict', 112, 'Xros pods');
        $b = $this->item('strict', 0, 'Cool Mint');
        $rows = [self::row($a, 'differs', 75, 112, 182), self::row($b, 'below_zero', 28, -2, 42, 'also differs', 2)];
        $plan = $this->doubt->plan((int) $this->site->channelId, $rows, 'list_12nov');
        self::assertSame(['add', 'add'], array_column($plan['rows'], 'action'));
        self::assertSame(['add' => 2, 'refresh' => 0, 'same' => 0, 'skip' => 0], $plan['counts']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_doubt'), 'a plan writes nothing');

        $r = $this->load($rows);
        self::assertSame(['add' => 2, 'refresh' => 0, 'same' => 0, 'skip' => 0], $r['counts']);
        self::assertSame(['status' => 'open', 'counted_qty' => null, 'booked_delta' => null, 'count_document_id' => null, 'resolved_by' => null, 'resolved_at' => null,
            'resolved_note' => null, 'shop_qty' => 75, 'cw_qty' => 112, 'reason' => 'differs', 'sold_30d' => 182, 'batch' => 'list_12nov', 'note' => null], self::doubtRow(self::openId($a)));
        self::assertSame(['below_zero', 'also differs'], [self::doubtRow(self::openId($b))['reason'], self::doubtRow(self::openId($b))['note']]);
        $audit = self::$db->one("SELECT actor, entity_type, entity_id, detail FROM audit_log WHERE action = 'stock_doubt.load'");
        self::assertSame(['system:doubt_load', 'channel', (string) $this->site->channelId], [$audit['actor'] ?? null, $audit['entity_type'] ?? null, $audit['entity_id'] ?? null]);
        $detail = json_decode((string) $audit['detail'], true);
        self::assertSame(['list_12nov', 'list.csv', 2, 2, 0], [$detail['batch'], $detail['file'], $detail['rows'], $detail['add'], $detail['refresh']]);

        // Again, a's figures changed and a new list's name: a is refreshed in place, b the same; still one open row each.
        $first = self::openId($a);
        $r = $this->load([self::row($a, 'below_zero', 70, -1, 190), self::row($b, 'below_zero', 28, -2, 42, 'also differs', 2)], 'list_12nov');
        self::assertSame(['add' => 0, 'refresh' => 1, 'same' => 1, 'skip' => 0], $r['counts']);
        self::assertSame($first, self::openId($a), 'the same row');
        self::assertSame([70, -1, 'below_zero', 190], array_values(array_intersect_key(self::doubtRow($first), array_flip(['shop_qty', 'cw_qty', 'reason', 'sold_30d']))));
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM stock_doubt'));
        $r = $this->load([self::row($b, 'below_zero', 28, -2, 42, 'also differs')], 'list_13nov');
        self::assertSame(['add' => 0, 'refresh' => 1, 'same' => 0, 'skip' => 0], $r['counts'], 'another list\'s name is a refresh');
        self::assertSame('list_13nov', self::doubtRow(self::openId($b))['batch']);
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'stock_doubt.load'"), 'one audit row per load');

        // The database refuses a second open row of a store and item as well.
        self::assertSame(1062, self::mysqlError(fn () => self::$db->exec("INSERT INTO stock_doubt (channel_id, sku_id, reason, cw_qty, batch) VALUES (?, ?, 'other', 0, 'x')",
            [$this->site->channelId, $a])));
    }

    public function testAnUnknownOrMergedItemRefusesTheWholeList(): void
    {
        $a = $this->item('strict', 5, 'Kept pod');
        $b = $this->item('strict', 5, 'Joined pod');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$a, $b]);
        $e = self::refused(422, 'unknown_item', fn () => $this->load([self::row($a), ['row' => 2, 'cw_code' => 'CW-999999'] + self::row($a)]));
        self::assertSame(2, $e->detail['row'] ?? null);
        $e = self::refused(422, 'merged_item', fn () => $this->load([self::row($a), self::row($b, n: 7)]));
        self::assertSame(7, $e->detail['row'] ?? null);
        self::refused(422, 'merged_item', fn () => $this->doubt->plan((int) $this->site->channelId, [self::row($b)], 'x'));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_doubt'), 'nothing of the list was written');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'stock_doubt.load'"));
        self::refused(400, 'bad_file', fn () => $this->load([self::row($a), self::row($a, n: 2)]));
        self::refused(400, 'bad_file', fn () => $this->load([]));
    }

    public function testARowAnsweredInTheSameListIsNotOpenedAgainButANewListOpensIt(): void
    {
        $a = $this->item('strict', 5, 'Answered pod');
        $this->load([self::row($a)], 'list_12nov');
        $this->doubt->dismiss($this->counter, self::openId($a), 'Checked on the shelf already');
        $r = $this->load([self::row($a)], 'list_12nov');
        self::assertSame(['add' => 0, 'refresh' => 0, 'same' => 0, 'skip' => 1], $r['counts'], 'the same list run again opens nothing answered');
        self::assertSame(0, self::openId($a));
        $r = $this->load([self::row($a)], 'list_13nov');
        self::assertSame(['add' => 1, 'refresh' => 0, 'same' => 0, 'skip' => 0], $r['counts'], 'a new list opens it again');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM stock_doubt WHERE sku_id = ?', [$a]));
    }

    // ------------------------------------------------------------------------------------------
    // Save count
    // ------------------------------------------------------------------------------------------

    public function testSaveCountBooksOneCountOfThatItemAtTheSellingWarehouseAndClearsTheRow(): void
    {
        $a = $this->item('strict', 40, 'Counted pod');
        $other = $this->item('strict', 9, 'Not touched');
        $this->load([self::row($a, 'differs', 41, 40, 12), self::row($other, n: 2)]);
        $id = self::openId($a);
        $ledger = $this->ledgerCount();

        $r = $this->doubt->count($this->counter, $id, 42);
        self::assertSame(['counted', 42, 40, 2, false], [$r['result'], $r['counted'], $r['had'], $r['delta'], $r['replayed']]);
        self::assertSame(1, self::cntDocs(), 'one count record');
        $doc = (array) self::$db->one("SELECT id, number, CAST(status AS CHAR) AS status, warehouse_id, note, created_by, posted_by FROM document WHERE doc_type = 'CNT'");
        self::assertSame(['posted', self::warehouseId('MAIN'), 'Doubtful stock #' . $id, $this->counter->staffUserId, $this->counter->staffUserId],
            [$doc['status'], (int) $doc['warehouse_id'], $doc['note'], (int) $doc['created_by'], (int) $doc['posted_by']]);
        self::assertSame($doc['number'], $r['number']);
        self::assertSame([[$a, 42]], array_map(static fn (array $l): array => [(int) $l['sku_id'], (int) $l['qty']],
            self::$db->all('SELECT sku_id, qty FROM document_line WHERE document_id = ?', [(int) $doc['id']])), 'ONE line: that item, what was counted');
        self::assertSame([[$a, 'count', 2, $this->nowDb()]], $this->ledgerOf((int) $doc['id']), 'one count on hand: the difference');
        self::assertSame($ledger + 1, $this->ledgerCount());
        self::assertSame(42, $this->onHand($a));
        self::assertSame(9, $this->onHand($other), 'nothing else moved');
        $row = self::doubtRow($id);
        self::assertSame(['cleared', 42, 2, (int) $doc['id'], $this->counter->staffUserId], [$row['status'], $row['counted_qty'], $row['booked_delta'],
            (int) $row['count_document_id'], (int) $row['resolved_by']]);
        self::assertNotNull($row['resolved_at']);
        self::assertSame('open', self::doubtRow(self::openId($other))['status']);
        $audit = self::$db->one("SELECT idem_key, detail FROM audit_log WHERE action = 'stock_doubt.clear'");
        self::assertSame(StockDoubt::KEY . $id, $audit['idem_key'] ?? null);
        $detail = (array) json_decode((string) $audit['detail'], true);
        self::assertSame([42, 40, 2, $doc['number'], self::warehouseId('MAIN')], [$detail['counted'] ?? null, $detail['had'] ?? null, $detail['delta'] ?? null,
            $detail['number'] ?? null, $detail['warehouse_id'] ?? null]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'stock_doubt.count' AND idem_key = ?", [StockDoubt::KEY . $id]));

        // A count of no difference books a record and no movement, and clears the row all the same.
        $r = $this->doubt->count($this->counter, self::openId($other), 9);
        self::assertSame([9, 9, 0], [$r['counted'], $r['had'], $r['delta']]);
        self::assertSame(9, $this->onHand($other));
    }

    public function testASecondClickReplaysTheFirstAnswerAndAClearedRowRefusesAnother(): void
    {
        $a = $this->item('strict', 10, 'Twice pod');
        $this->load([self::row($a, 'below_zero', 0, -3, 30)]);
        $id = self::openId($a);
        $first = $this->doubt->count($this->counter, $id, 7);
        $again = $this->doubt->count($this->counter, $id, 7);
        self::assertTrue($again['replayed']);
        self::assertSame([$first['counted'], $first['had'], $first['delta'], $first['number']], [$again['counted'], $again['had'], $again['delta'], $again['number']]);
        self::assertSame(1, self::cntDocs(), 'booked once');
        self::assertSame(7, $this->onHand($a));
        $e = self::refused(409, 'doubt_settled', fn () => $this->doubt->count($this->counter, $id, 8));
        self::assertSame('cleared', $e->detail['status'] ?? null);
        $other = $this->staffUser('warehouse');
        self::refused(409, 'doubt_settled', fn () => $this->doubt->count($other, $id, 9));
        self::refused(409, 'doubt_settled', fn () => $this->doubt->dismiss($this->counter, $id, 'Not needed after all'));
        self::assertSame(1, self::cntDocs());
        self::assertSame(7, $this->onHand($a));
    }

    public function testRefusalsBookNothingAndLeaveTheRowOpen(): void
    {
        $a = $this->item('strict', 10, 'Refused pod');
        $this->load([self::row($a)]);
        $id = self::openId($a);
        self::refused(422, 'bad_quantity', fn () => $this->doubt->count($this->counter, $id, -1));
        self::refused(422, 'bad_quantity', fn () => $this->doubt->count($this->counter, $id, CountSheets::MAX_QTY + 1));
        self::refused(404, 'unknown_doubt', fn () => $this->doubt->count($this->counter, $id + 1000, 5));
        self::refused(403, 'role_not_allowed', fn () => $this->doubt->count($this->staffUser('buyer'), $id, 5));
        self::refused(403, 'admin_cannot_post', fn () => $this->doubt->count($this->staffUser(['admin', 'stock_controller']), $id, 5));
        self::refused(403, 'staff_required', fn () => $this->doubt->count(Caller::system('doubt_load'), $id, 5));
        self::refused(403, 'role_not_allowed', fn () => $this->doubt->dismiss($this->staffUser('buyer'), $id, 'Not mine to say'));
        // A store with no selling warehouse: nothing to count against.
        $bare = Caller::channel(self::makeChannel('bare'), 'bare');
        $b = $this->item('strict', 3, 'Bare pod');
        $this->load([self::row($b)], 'list_12nov', (int) $bare->channelId);
        self::refused(409, 'no_selling_warehouse', fn () => $this->doubt->count($this->counter, self::openId($b), 3));
        self::assertSame(0, self::cntDocs(), 'nothing booked');
        self::assertSame(10, $this->onHand($a));
        self::assertSame('open', self::doubtRow($id)['status']);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE idem_key LIKE 'doubt:%'"), 'no answer kept: the same key may try again');
        // After the refusals, the row is counted as normal.
        self::assertSame(5, $this->doubt->count($this->counter, $id, 5)['counted']);
    }

    // ------------------------------------------------------------------------------------------
    // Not needed
    // ------------------------------------------------------------------------------------------

    public function testNotNeededNeedsANoteAndBooksNothing(): void
    {
        $a = $this->item('strict', 10, 'Dismissed pod');
        $this->load([self::row($a, note: 'from the list')]);
        $id = self::openId($a);
        self::refused(400, 'note_required', fn () => $this->doubt->dismiss($this->counter, $id, ' ok '));
        self::refused(404, 'unknown_doubt', fn () => $this->doubt->dismiss($this->counter, $id + 1000, 'No such row'));
        $this->doubt->dismiss($this->counter, $id, "  Counted on paper\x01this morning ");
        $row = self::doubtRow($id);
        self::assertSame(['dismissed', null, null, null, 'Counted on paper this morning', 'from the list', $this->counter->staffUserId],
            [$row['status'], $row['counted_qty'], $row['booked_delta'], $row['count_document_id'], $row['resolved_note'], $row['note'], (int) $row['resolved_by']]);
        self::assertSame(0, self::cntDocs());
        self::assertSame(10, $this->onHand($a));
        $audit = self::$db->one("SELECT actor, detail FROM audit_log WHERE action = 'stock_doubt.dismiss'");
        self::assertSame('staff:' . $this->counter->staffUserId, $audit['actor'] ?? null);
        self::assertSame('Counted on paper this morning', json_decode((string) $audit['detail'], true)['note'] ?? null);
        // The same press again (a double click, a resend): the first answer stands, nothing more; any other answer is refused.
        $this->doubt->dismiss($this->counter, $id, 'Counted on paper this morning');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'stock_doubt.dismiss'"), 'one answer, one audit row');
        self::refused(409, 'doubt_settled', fn () => $this->doubt->dismiss($this->counter, $id, 'Again'));
        self::refused(409, 'doubt_settled', fn () => $this->doubt->dismiss($this->staffUser('warehouse'), $id, 'Counted on paper this morning'));
        self::refused(409, 'doubt_settled', fn () => $this->doubt->count($this->counter, $id, 3));
        self::assertSame(0, self::cntDocs());
    }

    // ------------------------------------------------------------------------------------------
    // The lists the page shows
    // ------------------------------------------------------------------------------------------

    public function testTheListShowsOnlyOpenRowsOfOneStoreInOrderWithCwsFigureNow(): void
    {
        $big = $this->item('strict', 112, 'Xros Corex pods');
        $small = $this->item('strict', 30, 'Elux Mr Blue');
        $neg = $this->item('strict', 0, 'Fumot Cool Mint');
        $best = $this->item('strict', 0, 'Elux Blueberry Sour');
        $done = $this->item('strict', 4, 'Already counted');
        $this->book('adjustment', $neg, -2);
        $this->load([self::row($small, 'differs', 25, 30, 4), self::row($neg, 'below_zero', 0, -2, 10, n: 2), self::row($big, 'differs', 75, 112, 182, n: 3),
            self::row($best, 'below_zero', 0, -1, 900, n: 4), self::row($done, n: 5)]);
        $elsewhere = $this->site('electrofag');
        $this->load([self::row($big, 'other', null, 1, null)], 'ef_list', (int) $elsewhere->channelId);
        $this->doubt->count($this->counter, self::openId($done), 4);

        $list = $this->doubt->open((int) $this->site->channelId, null, '', 1);
        self::assertSame(4, $list['total']);
        self::assertSame([$big, $small, $best, $neg], array_column($list['rows'], 'sku_id'), 'differs by the size of the difference, then below zero by the units sold');
        self::assertSame([112, 30, 0, -2], array_column($list['rows'], 'cw_now'), 'CW\'s figure now at the store\'s selling warehouse');
        self::assertSame([75, 25, 0, 0], array_column($list['rows'], 'shop_qty'));
        self::assertSame([$best, $neg], array_column($this->doubt->open((int) $this->site->channelId, 'below_zero', '', 1)['rows'], 'sku_id'), 'the filter by why');
        self::assertSame([$big, $small], array_column($this->doubt->open((int) $this->site->channelId, 'differs', '', 1)['rows'], 'sku_id'));
        self::assertSame([$small, $best], array_column($this->doubt->open((int) $this->site->channelId, null, 'elux', 1)['rows'], 'sku_id'), 'a search by name');
        self::assertSame([$neg], array_column($this->doubt->open((int) $this->site->channelId, null, strtolower(self::code($neg)), 1)['rows'], 'sku_id'), 'by code');
        self::assertSame([], $this->doubt->open((int) $this->site->channelId, null, '100%_', 1)['rows'], 'the search\'s own signs are letters');
        self::assertSame([$big], array_column($this->doubt->open((int) $elsewhere->channelId, null, '', 1)['rows'], 'sku_id'), 'another store\'s own rows');

        self::assertSame([['code' => 'vapeandgo', 'open' => 4], ['code' => 'electrofag', 'open' => 1]],
            array_map(static fn (array $s): array => ['code' => $s['code'], 'open' => $s['open']], $this->doubt->stores()));
        self::assertSame(5, $this->doubt->openCount());
        self::assertSame(4, $this->doubt->openCount((int) $this->site->channelId));

        $this->doubt->dismiss($this->counter, self::openId($small), 'Shop figure is right');
        $checked = $this->doubt->checked((int) $this->site->channelId, 1);
        self::assertSame([$small, $done], array_column($checked['rows'], 'sku_id'), 'newest first');
        self::assertSame(['dismissed', 'cleared'], array_column($checked['rows'], 'status'));
        self::assertSame([null, 4], array_column($checked['rows'], 'counted_qty'));
        self::assertSame([null, 0], array_column($checked['rows'], 'booked_delta'));
        self::assertSame(['stock_controller 1', 'stock_controller 1'], array_column($checked['rows'], 'resolved_by_name'));
        self::assertSame(3, $this->doubt->openCount((int) $this->site->channelId));
    }

    public function testFiftyAPage(): void
    {
        $rows = [];
        for ($i = 1; $i <= StockDoubt::PAGE + 3; $i++) {
            $rows[] = self::row($this->item('strict', 0, 'Paged pod ' . $i), 'below_zero', 0, -1, $i, n: $i);
        }
        $this->load($rows);
        $one = $this->doubt->open((int) $this->site->channelId, null, '', 1);
        $two = $this->doubt->open((int) $this->site->channelId, null, '', 2);
        self::assertSame([StockDoubt::PAGE + 3, 2, StockDoubt::PAGE, 3], [$one['total'], $one['pages'], count($one['rows']), count($two['rows'])]);
        self::assertSame(StockDoubt::PAGE + 3, (int) $one['rows'][0]['sold_30d'], 'the best seller first');
        self::assertSame(1, (int) $two['rows'][2]['sold_30d']);
        self::assertSame(2, $this->doubt->open((int) $this->site->channelId, null, '', 99)['page'], 'a page past the end is the last one');
    }
}
