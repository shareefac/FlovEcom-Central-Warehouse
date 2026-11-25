<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Admin\ConfigInvariants;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\OpResult;
use CW\Settings;
use CW\StockOps\CostHints;
use CW\Tests\Support\StockTestCase;
use DateTimeImmutable;

/**
 * CW-1180 (0081; docs/decisions.md NR1-NR8): a cancelled paid unit the store marked "don't restock" leaves stock at once. Under the setting
 * stock.cancel_not_restockable = write_off (the owner's rule, seeded): ONE write-off at the unit's sale warehouse at the item's average cost
 * (noted cancel_not_restockable, the order and the unit on it, booking time), no VERIFY move, no recount; availability does not move. An
 * uncancel is its exact inverse (the write-off taken back, without a cost of its own, then allocated), unless the item was counted at that
 * warehouse since (then allocated and a count review for a person). `verify` is the way before (D37), and an uncancel follows what the
 * unit's last cancel did, whatever the setting says now.
 */
final class CancelWriteOffTest extends StockTestCase
{
    private const KEY = 'stock.cancel_not_restockable';

    private function cancel(Caller $site, string $ref, array $units, bool $restockable, ?string $key = null): OpResult
    {
        return $this->ok($this->res->cancel($site, $ref, $units, $restockable, $key ?? $this->key('cancel')));
    }

    private function uncancel(Caller $site, string $ref, array $units, ?string $key = null): OpResult
    {
        return $this->ok($this->res->uncancel($site, $ref, $units, $key ?? $this->key('uncancel')));
    }

    /** @return array<string, string> unit => result */
    private static function results(OpResult $r): array
    {
        return array_column($r->body['units'], 'result', 'unit_id');
    }

    /** @return array<string, int> movement_type[/note] bucket => net units booked under $key */
    private function ledgerOf(string $key): array
    {
        $out = [];
        foreach (self::$db->all('SELECT movement_type, note, bucket, qty_delta FROM stock_ledger WHERE idem_key = ? ORDER BY id', [$key]) as $r) {
            $k = $r['movement_type'] . ($r['note'] === null ? '' : '/' . $r['note']) . ' ' . $r['bucket'];
            $out[$k] = ($out[$k] ?? 0) + (int) $r['qty_delta'];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> the write_off rows booked under $key */
    private function writeOffs(string $key): array
    {
        return self::$db->all('SELECT warehouse_id, sku_id, bucket, qty_delta, channel_id, order_ref, unit_id, note, actor, effective_at, '
            . 'CAST(unit_cost AS CHAR) AS unit_cost, cost_currency, CAST(cost_source AS CHAR) AS cost_source, document_id FROM stock_ledger '
            . "WHERE idem_key = ? AND movement_type = 'write_off' ORDER BY id", [$key]);
    }

    private static function reviews(?string $source = null): int
    {
        return (int) ($source === null ? self::$db->value('SELECT COUNT(*) FROM count_review')
            : self::$db->value('SELECT COUNT(*) FROM count_review WHERE source = ?', [$source]));
    }

    private static function feed(): int
    {
        return (int) self::$db->value('SELECT COUNT(*) FROM stock_change');
    }

    /** An item with $lots goods-in at MAIN, each [units, unit cost] (cost null: none), booked before the test's clock. */
    private function costed(string $policy, array $lots): int
    {
        $sku = $this->item($policy, 0);
        $now = $this->now;
        $this->now = new DateTimeImmutable(self::FIXTURE_TIME, Clock::utc());
        try {
            foreach ($lots as [$units, $cost]) {
                $this->book('goods_in', $sku, $units, 'MAIN', null, $cost);
            }
        } finally {
            $this->now = $now;
        }
        return $sku;
    }

    // ------------------------------------------------------------------------------------------
    // The setting
    // ------------------------------------------------------------------------------------------

    public function testTheSettingIsSeededAsWriteOffAtOnceWithItsBaselineAndTakesOnlyItsTwoChoices(): void
    {
        $r = self::$db->one("SELECT value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key = '" . self::KEY . "'");
        self::assertSame(['string', '"write_off"', 1, 'NR1'], [$r['value_type'] ?? null, $r['v'] ?? null, (int) ($r['provisional'] ?? -1), $r['decision'] ?? null]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = ? AND version = 1 "
            . "AND action = 'baseline' AND actor = 'system:migrate'", [self::KEY]));
        $s = new Settings(self::$db);
        self::assertSame('write_off', $s->get(self::KEY));
        self::assertSame(['in' => ['write_off', 'verify'], 'required' => true], Settings::RULES[self::KEY]);
        $s->checkRule(self::KEY, 'verify');
        foreach (['', 'write-off', 'VERIFY', 'park'] as $bad) {
            try {
                $s->checkRule(self::KEY, $bad);
                self::fail("{$bad} accepted");
            } catch (CwException $e) {
                self::assertSame('bad_value', $e->errorCode, $bad);
            }
        }
        // a change is audited like every setting: a version in config_change and an audit row
        try {
            $out = (new Settings(self::$db))->change(Caller::system('nr_test'), self::KEY, 'verify', 'back to the recount for a while');
            self::assertTrue($out['changed']);
            self::assertSame('verify', (new Settings(self::$db))->get(self::KEY));
            self::assertSame(2, (int) self::$db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'setting' AND subject_key = ?", [self::KEY]));
            self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'setting.change' AND entity_id = ?", [self::KEY]));
            self::assertSame([], ConfigInvariants::check(self::$db));
        } finally {
            self::$db->exec("UPDATE app_setting SET updated_actor = 'system:migrate' WHERE setting_key = ?", [self::KEY]);
        }
    }

    // ------------------------------------------------------------------------------------------
    // cancel
    // ------------------------------------------------------------------------------------------

    public function testANotRestockablePaidUnitIsWrittenOffAtOnceAtItsAverageCostAndAvailabilityDoesNotMove(): void
    {
        $site = $this->site();
        $sku = $this->costed('strict', [[10, '2'], [10, '3']]); // the average so far: £2.50
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'V2', $sku, 2); // a pack of two
        $this->commit($site, '1', [self::line('V1', 'a', 'b'), self::line('V2', 'p')]);
        $this->assertBal(20, 4, 0, $sku);
        self::assertSame(16, $this->view($site, 'V1')['available']);
        $feed = self::feed();
        $value = (new CostHints(self::$db))->average([$sku]);

        $r = $this->cancel($site, '1', ['a', 'p'], false, 'cnl-w');
        self::assertSame(['order_ref' => '1', 'status' => 'committed', 'units' => [['unit_id' => 'a', 'result' => 'cancelled_written_off'],
            ['unit_id' => 'p', 'result' => 'cancelled_written_off']]], $r->body);
        $this->assertBal(17, 1, 0, $sku);
        $this->assertBal(0, 0, 0, $sku, 'VERIFY');
        self::assertSame(16, $this->view($site, 'V1')['available'], 'the units leave allocated and the shelf together: availability does not move');
        self::assertSame($feed, self::feed(), 'no availability change, no feed row');
        self::assertSame(['cancel allocated' => -3, 'write_off/cancel_not_restockable on_hand' => -3], $this->ledgerOf('cnl-w'));
        self::assertSame(0, self::reviews(), 'no recount, no review');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE warehouse_id = ?', [self::warehouseId('VERIFY')]), 'nothing to VERIFY');
        $rows = $this->writeOffs('cnl-w');
        self::assertCount(2, $rows);
        $now = Clock::db($this->now);
        foreach ([['a', -1], ['p', -2]] as $i => [$unit, $qty]) {
            self::assertSame(['warehouse_id' => self::warehouseId('MAIN'), 'sku_id' => $sku, 'bucket' => 'on_hand', 'qty_delta' => $qty,
                'channel_id' => $site->channelId, 'order_ref' => '1', 'unit_id' => $unit, 'note' => 'cancel_not_restockable', 'actor' => $site->actor,
                'effective_at' => $now, 'unit_cost' => '2.500000', 'cost_currency' => 'GBP', 'cost_source' => 'estimate', 'document_id' => null],
                ['warehouse_id' => (int) $rows[$i]['warehouse_id'], 'sku_id' => (int) $rows[$i]['sku_id'], 'bucket' => $rows[$i]['bucket'],
                    'qty_delta' => (int) $rows[$i]['qty_delta'], 'channel_id' => (int) $rows[$i]['channel_id'], 'order_ref' => $rows[$i]['order_ref'],
                    'unit_id' => $rows[$i]['unit_id'], 'note' => $rows[$i]['note'], 'actor' => $rows[$i]['actor'], 'effective_at' => $rows[$i]['effective_at'],
                    'unit_cost' => $rows[$i]['unit_cost'], 'cost_currency' => $rows[$i]['cost_currency'], 'cost_source' => $rows[$i]['cost_source'],
                    'document_id' => $rows[$i]['document_id']], "unit {$unit}");
        }
        self::assertSame($value, (new CostHints(self::$db))->average([$sku]), 'a write-off never moves the average cost (only units that come in do)');
        self::assertSame(['cancelled', 'allocated', 'cancelled'], [$this->unitState($site, 'a'), $this->unitState($site, 'b'), $this->unitState($site, 'p')]);

        // replayed under its key: the stored answer, nothing booked twice
        $again = $this->cancel($site, '1', ['a', 'p'], false, 'cnl-w');
        self::assertEquals($r->body, $again->body, 'the stored answer (its keys in the stored JSON\'s order)');
        self::assertCount(2, $this->writeOffs('cnl-w'));
        // cancelled again under another key: already cancelled, nothing booked
        self::assertSame(['a' => 'already_cancelled'], self::results($this->cancel($site, '1', ['a'], false)));
        $this->assertBal(17, 1, 0, $sku);
        // the rest of the order ships as any paid unit
        self::assertSame(['b' => 'shipped'], self::results($this->ship($site, '1', ['b'])));
        $this->assertBal(16, 0, 0, $sku);
    }

    public function testAnItemNoUnitOfWhichCameInWithACostIsWrittenOffWithoutACost(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5); // its opening has no cost
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a')]);
        self::assertSame(['a' => 'cancelled_written_off'], self::results($this->cancel($site, '1', ['a'], false, 'cnl-n')));
        $this->assertBal(4, 0, 0, $sku);
        $row = $this->writeOffs('cnl-n')[0];
        self::assertSame([null, null, null], [$row['unit_cost'], $row['cost_currency'], $row['cost_source']], 'no cost is known: none is made up');
    }

    public function testAHeldUnitCancelledNotRestockableIsOnlyFreed(): void
    {
        $site = $this->site();
        $sku = $this->costed('strict', [[5, '2']]);
        $this->listing($site, 'V1', $sku);
        $this->ok($this->reserve($site, '1', [self::line('V1', 'a')]));
        $this->assertBal(5, 0, 1, $sku);
        self::assertSame(['a' => 'cancelled'], self::results($this->cancel($site, '1', ['a'], false, 'cnl-h')));
        $this->assertBal(5, 0, 0, $sku);
        self::assertSame(['cancel held' => -1], $this->ledgerOf('cnl-h'), 'never paid, never picked: the goods never left, nothing is written off');
    }

    public function testARestockableCancelIsUnchanged(): void
    {
        $site = $this->site();
        $sku = $this->costed('strict', [[5, '2']]);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a')]);
        self::assertSame(['a' => 'cancelled'], self::results($this->cancel($site, '1', ['a'], true, 'cnl-r')));
        $this->assertBal(5, 0, 0, $sku);
        self::assertSame(['cancel allocated' => -1], $this->ledgerOf('cnl-r'));
    }

    public function testTheWayBeforeStillParksTheUnitInVerifyWhenTheSettingSaysSo(): void
    {
        self::notRestockable('verify');
        $site = $this->site();
        $sku = $this->costed('strict', [[5, '2']]);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a')]);
        self::assertSame(['a' => 'cancelled_to_verify'], self::results($this->cancel($site, '1', ['a'], false, 'cnl-v')));
        $this->assertBal(4, 0, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
        self::assertSame(1, self::reviews('verify_recount'));
        self::assertSame([], $this->writeOffs('cnl-v'));
    }

    public function testAMissingOrUnknownSettingKeepsTheWayBefore(): void
    {
        $site = $this->site();
        $sku = $this->costed('strict', [[5, '2']]);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $row = self::$db->one('SELECT * FROM app_setting WHERE setting_key = ?', [self::KEY]);
        try {
            // a value no screen accepts (only by hand in the database): the way before, never a guess
            self::$db->exec("UPDATE app_setting SET value_json = JSON_QUOTE('bin_it') WHERE setting_key = ?", [self::KEY]);
            self::assertSame(['a' => 'cancelled_to_verify'], self::results($this->cancel($site, '1', ['a'], false)));
            // the row not there (a release running a moment before 0081): the way before
            self::$db->exec('DELETE FROM app_setting WHERE setting_key = ?', [self::KEY]);
            self::assertSame(['b' => 'cancelled_to_verify'], self::results($this->cancel($site, '1', ['b'], false)));
        } finally {
            if (self::$db->value('SELECT 1 FROM app_setting WHERE setting_key = ?', [self::KEY]) === null) {
                self::$db->exec('INSERT INTO app_setting (setting_key, value_type, value_json, provisional, decision, description, updated_actor, updated_at) '
                    . 'VALUES (?, ?, CAST(? AS JSON), ?, ?, ?, ?, ?)', [$row['setting_key'], $row['value_type'], $row['value_json'], $row['provisional'],
                        $row['decision'], $row['description'], $row['updated_actor'], $row['updated_at']]);
            }
            self::notRestockable('write_off');
        }
        $this->assertBal(3, 0, 0, $sku);
        $this->assertBal(2, 0, 0, $sku, 'VERIFY');
    }

    public function testAWriteOffBelowZeroOpensTheUsualNegativeReviewAndFlagsNothingElse(): void
    {
        $site = $this->site();
        $sku = $this->costed('strict', [[1, '2']]);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a')]);
        $this->ship($site, '1', ['a']); // shelf 0
        $this->commit($site, '2', [self::line('V1', 'b')], 'unreserved'); // sold with nothing on the shelf: available -1
        $flags = (int) self::$db->value('SELECT COUNT(*) FROM oversell_event');
        self::assertSame(['b' => 'cancelled_written_off'], self::results($this->cancel($site, '2', ['b'], false)));
        $this->assertBal(-1, 0, 0, $sku);
        self::assertSame(1, self::reviews('negative_on_hand'), 'the shelf below zero is looked at, as for any movement');
        self::assertSame($flags, (int) self::$db->value('SELECT COUNT(*) FROM oversell_event'), 'availability did not fall: no oversell flag');
        self::assertSame(-1, $this->view($site, 'V1')['available'] ?? null, 'the cancel did not change availability');
    }

    // ------------------------------------------------------------------------------------------
    // uncancel
    // ------------------------------------------------------------------------------------------

    public function testAWrittenOffUnitTakenBackComesBackExactlyAndCanGoRoundAgain(): void
    {
        $site = $this->site();
        $sku = $this->costed('strict', [[10, '2.04']]);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $before = [$this->bal($sku), $this->view($site, 'V1')['available'], (new CostHints(self::$db))->average([$sku])];
        $this->cancel($site, '1', ['a'], false, 'cnl-1');
        $this->assertBal(9, 1, 0, $sku);
        $feed = self::feed();

        $r = $this->uncancel($site, '1', ['a'], 'unc-1');
        self::assertSame(['order_ref' => '1', 'status' => 'committed', 'units' => [['unit_id' => 'a', 'result' => 'uncancelled_from_write_off']], 'oversell' => []], $r->body);
        self::assertSame($before, [$this->bal($sku), $this->view($site, 'V1')['available'], (new CostHints(self::$db))->average([$sku])],
            'the shelf, the allocated figure, availability and the average cost are exactly as before the cancel');
        self::assertSame($feed, self::feed(), 'availability never moved: no feed row');
        self::assertSame(['write_off/uncancel_from_write_off on_hand' => 1, 'uncancel allocated' => 1], $this->ledgerOf('unc-1'));
        $back = $this->writeOffs('unc-1')[0];
        self::assertSame([self::warehouseId('MAIN'), 1, 'a', '1', null, null, Clock::db($this->now)],
            [(int) $back['warehouse_id'], (int) $back['qty_delta'], $back['unit_id'], $back['order_ref'], $back['unit_cost'], $back['cost_source'], $back['effective_at']],
            'the write-off taken back at the unit\'s warehouse, with no cost of its own (it is not a purchase: the average stays exact)');
        self::assertSame(0, self::reviews());
        self::assertSame('allocated', $this->unitState($site, 'a'));

        // a second uncancel changes nothing; the unit ships as any paid unit
        self::assertSame(['a' => 'not_cancelled'], self::results($this->uncancel($site, '1', ['a'])));
        // round again: written off, taken back, written off
        self::assertSame(['a' => 'cancelled_written_off'], self::results($this->cancel($site, '1', ['a'], false)));
        self::assertSame(['a' => 'uncancelled_from_write_off'], self::results($this->uncancel($site, '1', ['a'])));
        self::assertSame(['a' => 'cancelled_written_off'], self::results($this->cancel($site, '1', ['a'], false)));
        $this->assertBal(9, 1, 0, $sku);
        self::assertSame(['b' => 'shipped'], self::results($this->ship($site, '1', ['b'])));
        $this->assertBal(8, 0, 0, $sku);
        self::assertSame(['a' => 'uncancelled_from_write_off'], self::results($this->uncancel($site, '1', ['a'])));
        self::assertSame(['a' => 'shipped'], self::results($this->ship($site, '1', ['a'])));
        $this->assertBal(8, 0, 0, $sku);
        self::assertSame(0, self::reviews());
        // replayed under its key: nothing booked twice
        self::assertEquals($r->body, $this->uncancel($site, '1', ['a'], 'unc-1')->body);
        self::assertCount(1, $this->writeOffs('unc-1'));
    }

    public function testAWrittenOffUnitWhoseItemWasCountedSinceIsAllocatedAndReviewedNotPutBack(): void
    {
        $site = $this->site();
        $sku = $this->costed('strict', [[10, '2.04']]);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $this->cancel($site, '1', ['a'], false, 'cnl-c');
        $wo = (int) self::$db->value("SELECT id FROM stock_ledger WHERE idem_key = 'cnl-c' AND movement_type = 'write_off'");
        $cancelId = (int) self::$db->value("SELECT id FROM stock_ledger WHERE idem_key = 'cnl-c' AND movement_type = 'cancel'");
        $this->now = $this->now->modify('+5 minutes');
        $this->book('count', $sku, 9, 'MAIN', Clock::iso(Clock::db($this->now))); // the shelf counted: 9
        $count = (int) self::$db->value("SELECT MAX(id) FROM stock_ledger WHERE sku_id = ? AND movement_type = 'count'", [$sku]);
        $this->assertBal(9, 1, 0, $sku);

        $r = $this->uncancel($site, '1', ['a'], 'unc-c');
        self::assertSame(['a' => 'uncancelled_after_write_off'], self::results($r));
        self::assertSame([], $r->body['oversell']);
        $this->assertBal(9, 2, 0, $sku);
        self::assertSame(7, $this->view($site, 'V1')['available'], 'the count replaced the figure: CW does not guess the unit is back; lower, never higher');
        self::assertSame(['uncancel/after_write_off allocated' => 1], $this->ledgerOf('unc-c'));
        $review = self::$db->one("SELECT warehouse_id, sku_id, status, ref, detail, dedupe_key FROM count_review WHERE source = 'uncancel_after_write_off'");
        self::assertNotNull($review);
        self::assertSame([self::warehouseId('MAIN'), $sku, 'open', "{$site->channelId}:1:a", "uncancel_write_off:{$site->channelId}:{$cancelId}"],
            [(int) $review['warehouse_id'], (int) $review['sku_id'], $review['status'], $review['ref'], $review['dedupe_key']]);
        $detail = json_decode((string) $review['detail'], true);
        ksort($detail);
        self::assertSame(['count_ledger_id' => $count, 'order_ref' => '1', 'reason' => 'counted_since', 'unit_id' => 'a', 'units' => 1,
            'write_off_cost' => '2.040000', 'write_off_ledger_id' => $wo], $detail);
        self::assertSame(1, self::reviews(), 'one review, nothing else');
        // replayed: the stored answer, no second review
        self::assertEquals($r->body, $this->uncancel($site, '1', ['a'], 'unc-c')->body);
        self::assertSame(1, self::reviews('uncancel_after_write_off'));
    }

    public function testACountedSinceUncancelThatLeavesAStrictItemShortIsFlagged(): void
    {
        $site = $this->site();
        $sku = $this->costed('strict', [[2, '2']]);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $this->cancel($site, '1', ['a'], false);
        $this->now = $this->now->modify('+5 minutes');
        $this->book('count', $sku, 1, 'MAIN', Clock::iso(Clock::db($this->now)));
        $r = $this->uncancel($site, '1', ['a']);
        self::assertSame(['a' => 'uncancelled_after_write_off'], self::results($r));
        self::assertSame([['sku_code' => sprintf('CW-%06d', $sku), 'kind' => 'uncancel_short', 'shortfall' => 1, 'available_after' => -1]], $r->body['oversell']);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM oversell_event WHERE kind = 'uncancel_short'"));
    }

    public function testAnUncancelFollowsWhatTheLastCancelDidWhateverTheSettingSaysNow(): void
    {
        $site = $this->site();
        $sku = $this->costed('strict', [[10, '2']]);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $this->cancel($site, '1', ['a'], false); // written off
        self::notRestockable('verify');
        $this->cancel($site, '1', ['b'], false); // parked
        $this->assertBal(8, 0, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
        self::assertSame(['a' => 'uncancelled_from_write_off'], self::results($this->uncancel($site, '1', ['a'])));
        self::notRestockable('write_off');
        self::assertSame(['b' => 'uncancelled_from_verify'], self::results($this->uncancel($site, '1', ['b'])));
        $this->assertBal(10, 2, 0, $sku);
        $this->assertBal(0, 0, 0, $sku, 'VERIFY');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM count_review WHERE status = 'open'"));
    }

    public function testOneUncancelOfManyUnitsTakesEachBackItsOwnWay(): void
    {
        $site = $this->site();
        $sku = $this->costed('strict', [[10, '2']]);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b', 'c', 'd')]);
        $this->cancel($site, '1', ['a', 'b'], false); // written off
        $this->cancel($site, '1', ['c'], true);       // back on sale
        self::notRestockable('verify');
        $this->cancel($site, '1', ['d'], false);      // parked
        self::notRestockable('write_off');
        $this->assertBal(7, 0, 0, $sku);
        $r = $this->uncancel($site, '1', ['a', 'b', 'c', 'd'], 'unc-m');
        self::assertSame(['a' => 'uncancelled_from_write_off', 'b' => 'uncancelled_from_write_off', 'c' => 'uncancelled', 'd' => 'uncancelled_from_verify'],
            self::results($r));
        $this->assertBal(10, 4, 0, $sku);
        $this->assertBal(0, 0, 0, $sku, 'VERIFY');
        self::assertSame(['write_off/uncancel_from_write_off on_hand' => 2, 'uncancel allocated' => 4, 'transfer_out/uncancel_from_verify on_hand' => -1,
            'transfer_in/uncancel_from_verify on_hand' => 1], $this->ledgerOf('unc-m'));
    }
}
