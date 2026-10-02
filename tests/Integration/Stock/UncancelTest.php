<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Caller;
use CW\CwException;
use CW\OpResult;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\WorkerPool;

/**
 * POST /v1/reservations/{ref}/uncancel (F7, decisions.md D46): a cancel the site took back puts the
 * cancelled paid units back into allocated, as the inverse of the cancel.
 */
final class UncancelTest extends StockTestCase
{
    private function uncancel(Caller $site, string $ref, array $units, ?string $key = null): OpResult
    {
        return $this->ok($this->res->uncancel($site, $ref, $units, $key ?? $this->key('uncancel')));
    }

    private function cancel(Caller $site, string $ref, array $units, bool $restockable, ?string $key = null): OpResult
    {
        return $this->ok($this->res->cancel($site, $ref, $units, $restockable, $key ?? $this->key('cancel')));
    }

    /** @return array<string, string> unit => result */
    private static function results(OpResult $r): array
    {
        return array_column($r->body['units'], 'result', 'unit_id');
    }

    /** @return array<string, mixed>|null the unit's verify recount, found by its id in `ref` */
    private function recount(Caller $site, string $ref, string $unit): ?array
    {
        return self::$db->one("SELECT id, status, resolution, dedupe_key, note FROM count_review WHERE source = 'verify_recount' AND ref = ? ORDER BY id DESC LIMIT 1",
            ["{$site->channelId}:{$ref}:{$unit}"]);
    }

    private function openRecounts(): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'verify_recount' AND status = 'open'");
    }

    /** @return array<string, int> movement_type[/note] => rows booked under $key */
    private function ledgerOf(string $key): array
    {
        $out = [];
        foreach (self::$db->all('SELECT movement_type, note, bucket, qty_delta FROM stock_ledger WHERE idem_key = ? ORDER BY id', [$key]) as $r) {
            $k = $r['movement_type'] . ($r['note'] === null ? '' : '/' . $r['note']) . ' ' . $r['bucket'];
            $out[$k] = ($out[$k] ?? 0) + (int) $r['qty_delta'];
        }
        return $out;
    }

    public function testARestockableCancelTakenBackIsAllocatedAgainAndShips(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $this->cancel($site, '1', ['a'], true);
        $this->assertBal(5, 1, 0, $sku);
        self::assertSame(4, $this->view($site, 'V1')['available']);
        $feed = (int) self::$db->value('SELECT COUNT(*) FROM stock_change WHERE sku_id = ?', [$sku]);

        $r = $this->uncancel($site, '1', ['a'], 'unc-1');
        self::assertSame(['order_ref' => '1', 'status' => 'committed', 'units' => [['unit_id' => 'a', 'result' => 'uncancelled']], 'oversell' => []], $r->body);
        $this->assertBal(5, 2, 0, $sku);
        self::assertSame('allocated', $this->unitState($site, 'a'));
        self::assertSame(3, $this->view($site, 'V1')['available']);
        self::assertSame(['uncancel allocated' => 1], $this->ledgerOf('unc-1'));
        self::assertSame($feed + 1, (int) self::$db->value('SELECT COUNT(*) FROM stock_change WHERE sku_id = ?', [$sku]), 'the sites learn the lower figure');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'reservation.uncancel' AND idem_key = 'unc-1'"));

        // a second uncancel under another key changes nothing; the unit ships as any paid unit
        self::assertSame(['a' => 'not_cancelled'], self::results($this->uncancel($site, '1', ['a'])));
        $this->assertBal(5, 2, 0, $sku);
        self::assertSame(['a' => 'shipped', 'b' => 'shipped'], self::results($this->ship($site, '1', ['a', 'b'])));
        $this->assertBal(3, 0, 0, $sku);
    }

    public function testAnUntouchedUnitInVerifyComesBackWithThePairedMovementAndItsRecountIsDismissed(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $this->cancel($site, '1', ['a'], false);
        $this->assertBal(4, 1, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
        self::assertSame(3, $this->view($site, 'V1')['available']);
        $feed = (int) self::$db->value('SELECT COUNT(*) FROM stock_change');
        $review = $this->recount($site, '1', 'a');
        self::assertSame('open', $review['status']);

        $r = $this->uncancel($site, '1', ['a'], 'unc-v');
        self::assertSame(['a' => 'uncancelled_from_verify'], self::results($r));
        self::assertSame([], $r->body['oversell']);
        $this->assertBal(5, 2, 0, $sku);
        $this->assertBal(0, 0, 0, $sku, 'VERIFY');
        self::assertSame(3, $this->view($site, 'V1')['available'], 'the unit never left the shelf: availability does not move');
        self::assertSame($feed, (int) self::$db->value('SELECT COUNT(*) FROM stock_change'), 'no availability change, no feed row');
        self::assertSame(['transfer_out/uncancel_from_verify on_hand' => -1, 'transfer_in/uncancel_from_verify on_hand' => 1, 'uncancel allocated' => 1],
            $this->ledgerOf('unc-v'));
        $after = self::$db->one('SELECT status, resolution, resolved_at, dedupe_key, note FROM count_review WHERE id = ?', [$review['id']]);
        self::assertSame(['dismissed', 'uncancelled'], [$after['status'], $after['resolution']]);
        self::assertNotNull($after['resolved_at']);
        self::assertSame("verify:{$site->channelId}:a#{$review['id']}", $after['dedupe_key'], 'the key is retired');
        self::assertStringContainsString('unc-v', (string) $after['note']);
        self::assertSame(0, $this->openRecounts());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM oversell_event'));

        // cancelled to VERIFY again: a FRESH recount opens (the old key no longer catches it) ...
        $this->cancel($site, '1', ['a'], false);
        $this->assertBal(4, 1, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
        self::assertSame(1, $this->openRecounts());
        self::assertNotSame($review['id'], $this->recount($site, '1', 'a')['id']);
        // ... and it comes back again
        self::assertSame(['a' => 'uncancelled_from_verify'], self::results($this->uncancel($site, '1', ['a'])));
        $this->assertBal(5, 2, 0, $sku);
        $this->assertBal(0, 0, 0, $sku, 'VERIFY');
        self::assertSame(0, $this->openRecounts());
    }

    public function testAUnitInVerifyAPersonAlreadyDealtWithIsAllocatedAndReviewedNotMovedBack(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b', 'c', 'd')]);
        $this->cancel($site, '1', ['a', 'b', 'c', 'd'], false);
        $this->assertBal(6, 0, 0, $sku);
        $this->assertBal(4, 0, 0, $sku, 'VERIFY');

        // a: the recount was closed by a person (and the unit written off at VERIFY)
        self::$db->exec("UPDATE count_review SET status = 'resolved', resolution = 'written_off', resolved_at = NOW(6) WHERE id = ?",
            [$this->recount($site, '1', 'a')['id']]);
        $this->book('write_off', $sku, 1, 'VERIFY');
        $r = $this->uncancel($site, '1', ['a']);
        self::assertSame(['a' => 'uncancelled_after_verify'], self::results($r));
        $this->assertBal(6, 1, 0, $sku);
        $this->assertBal(3, 0, 0, $sku, 'VERIFY');
        $rv = self::$db->one("SELECT warehouse_id, sku_id, ref, detail, status FROM count_review WHERE source = 'uncancel_after_verify'");
        self::assertSame([self::warehouseId('MAIN'), $sku, "{$site->channelId}:1:a", 'open'], [$rv['warehouse_id'], $rv['sku_id'], $rv['ref'], $rv['status']]);
        self::assertSame('recount_closed', json_decode((string) $rv['detail'], true)['reason']);
        self::assertSame('resolved', $this->recount($site, '1', 'a')['status'], 'a person\'s decision is left as it is');
        self::assertStringContainsString('#', (string) $this->recount($site, '1', 'a')['dedupe_key']);

        // b: VERIFY was counted after the unit arrived (a count booked later replaces the figure,
        // whatever its counted_at, R13)
        $this->book('count', $sku, 3, 'VERIFY', '2026-09-26T17:00:00Z');
        self::assertSame(['b' => 'uncancelled_after_verify'], self::results($this->uncancel($site, '1', ['b'])));
        $this->assertBal(6, 2, 0, $sku);
        $this->assertBal(3, 0, 0, $sku, 'VERIFY');
        self::assertSame('open', $this->recount($site, '1', 'b')['status'], 'the recount still stands; only its key is retired');
        self::assertSame('verify_counted', json_decode((string) self::$db->value(
            "SELECT detail FROM count_review WHERE source = 'uncancel_after_verify' AND ref = ?", ["{$site->channelId}:1:b"]), true)['reason']);

        // c, d: a person also moved two units back to MAIN by hand; the count above was booked after
        // c and d arrived as well, so they go to review too (VERIFY is left alone)
        $this->ok($this->moves->record(self::staff(), ['type' => 'transfer_out', 'warehouse' => 'VERIFY', 'lines' => [['sku_id' => $sku, 'qty' => 2]]], $this->key('t')));
        $this->ok($this->moves->record(self::staff(), ['type' => 'transfer_in', 'warehouse' => 'MAIN', 'lines' => [['sku_id' => $sku, 'qty' => 2]]], $this->key('t')));
        $this->assertBal(8, 2, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
        self::assertSame(['c' => 'uncancelled_after_verify', 'd' => 'uncancelled_after_verify'], self::results($this->uncancel($site, '1', ['c', 'd'])));
        $this->assertBal(8, 4, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
        self::assertSame(4, (int) self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'uncancel_after_verify'"));
    }

    /**
     * Review fix (D46 "untouched"): a person who settles VERIFY by hand (D37: transfer back, or write-off)
     * without closing the recounts has touched every unit parked there before; CW cannot tell whose unit
     * moved, so none of them is moved back. The probe: two parked units, one moved back to MAIN by staff.
     */
    public function testVerifyMovedByHandSendsEveryUnitParkedBeforeToReview(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $this->cancel($site, '1', ['a', 'b'], false);
        $this->assertBal(8, 0, 0, $sku);
        $this->assertBal(2, 0, 0, $sku, 'VERIFY');
        // staff move one unit back to MAIN without closing either recount
        $this->ok($this->moves->record(self::staff(), ['type' => 'transfer_out', 'warehouse' => 'VERIFY', 'lines' => [['sku_id' => $sku, 'qty' => 1]]], $this->key('t')));
        $this->ok($this->moves->record(self::staff(), ['type' => 'transfer_in', 'warehouse' => 'MAIN', 'lines' => [['sku_id' => $sku, 'qty' => 1]]], $this->key('t')));
        $this->assertBal(9, 0, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
        self::assertSame(9, $this->view($site, 'V1')['available']);

        self::assertSame(['a' => 'uncancelled_after_verify'], self::results($this->uncancel($site, '1', ['a'])));
        $this->assertBal(9, 1, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
        self::assertSame(8, $this->view($site, 'V1')['available'], 'MAIN holds 9 with 1 allocated (it was 9 before the fix: b\'s unverified unit came back)');
        $detail = json_decode((string) self::$db->value("SELECT detail FROM count_review WHERE source = 'uncancel_after_verify' AND ref = ?",
            ["{$site->channelId}:1:a"]), true);
        self::assertSame('verify_moved', $detail['reason']);
        self::assertNotNull($detail['verify_moved_ledger_id']);
        self::assertSame('open', $this->recount($site, '1', 'b')['status'], 'b still waits for its recount');
        self::assertSame(['b' => 'uncancelled_after_verify'], self::results($this->uncancel($site, '1', ['b'])));
        $this->assertBal(9, 2, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'uncancel_after_verify'"));

        // a write-off at VERIFY counts the same; a unit parked AFTER it is untouched and comes back
        $this->commit($site, '2', [self::line('V1', 'c', 'd')]);
        $this->cancel($site, '2', ['c'], false);
        $this->book('write_off', $sku, 1, 'VERIFY');
        $this->cancel($site, '2', ['d'], false);
        $r = $this->uncancel($site, '2', ['c', 'd']);
        self::assertSame(['c' => 'uncancelled_after_verify', 'd' => 'uncancelled_from_verify'], self::results($r));
        self::assertSame('verify_moved', json_decode((string) self::$db->value(
            "SELECT detail FROM count_review WHERE source = 'uncancel_after_verify' AND ref = ?", ["{$site->channelId}:2:c"]), true)['reason']);
        // other parked units coming and going do not count as touching it
        $this->commit($site, '3', [self::line('V1', 'e', 'f')]);
        $this->cancel($site, '3', ['e'], false);
        $this->cancel($site, '3', ['f'], false);
        self::assertSame(['f' => 'uncancelled_from_verify'], self::results($this->uncancel($site, '3', ['f'])));
        self::assertSame(['e' => 'uncancelled_from_verify'], self::results($this->uncancel($site, '3', ['e'])));
    }

    public function testVerifyHoldingFewerUnitsThanTheUnitSendsItToReview(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        // VERIFY was already short before the unit arrived (a write-off of goods CW never had there)
        $this->book('write_off', $sku, 1, 'VERIFY');
        $this->assertBal(-1, 0, 0, $sku, 'VERIFY');
        $this->commit($site, '1', [self::line('V1', 'a')]);
        $this->cancel($site, '1', ['a'], false);
        $this->assertBal(0, 0, 0, $sku, 'VERIFY');
        $r = $this->uncancel($site, '1', ['a']);
        self::assertSame(['a' => 'uncancelled_after_verify'], self::results($r));
        $this->assertBal(9, 1, 0, $sku);
        $this->assertBal(0, 0, 0, $sku, 'VERIFY');
        self::assertSame('verify_short', json_decode((string) self::$db->value("SELECT detail FROM count_review WHERE source = 'uncancel_after_verify'"), true)['reason']);
        self::assertSame('open', $this->recount($site, '1', 'a')['status']);
        // a count booked BEFORE the units arrived does not count as dealing with them
        $this->commit($site, '2', [self::line('V1', 'e')]);
        $this->book('count', $sku, 0, 'VERIFY', '2026-09-26T17:30:00Z');
        $this->cancel($site, '2', ['e'], false);
        self::assertSame(['e' => 'uncancelled_from_verify'], self::results($this->uncancel($site, '2', ['e'])));
        $this->assertBal(0, 0, 0, $sku, 'VERIFY');
    }

    public function testAMixedSetGetsOneResultPerUnit(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'VU', null); // unlinked
        $this->commit($site, '1', [self::line('V1', 'a', 'b', 'c', 'd', 'e'), self::line('VU', 'f')]);
        $this->cancel($site, '1', ['a'], true);
        $this->cancel($site, '1', ['c'], false);
        self::$db->exec("UPDATE count_review SET status = 'resolved', resolution = 'written_off', resolved_at = NOW(6) WHERE id = ?",
            [$this->recount($site, '1', 'c')['id']]);
        $this->book('write_off', $sku, 1, 'VERIFY');
        $this->cancel($site, '1', ['b'], false); // parked after the write-off: untouched
        $this->ship($site, '1', ['e']);
        $this->cancel($site, '1', ['f'], true);
        $this->assertBal(7, 1, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
        self::assertSame(6, $this->view($site, 'V1')['available']);

        $r = $this->uncancel($site, '1', ['zz', 'f', 'e', 'd', 'c', 'b', 'a'], 'mixed');
        self::assertSame([
            ['unit_id' => 'a', 'result' => 'uncancelled'],
            ['unit_id' => 'b', 'result' => 'uncancelled_from_verify'],
            ['unit_id' => 'c', 'result' => 'uncancelled_after_verify'],
            ['unit_id' => 'd', 'result' => 'not_cancelled'],
            ['unit_id' => 'e', 'result' => 'shipped'],
            ['unit_id' => 'f', 'result' => 'uncancelled'],
            ['unit_id' => 'zz', 'result' => 'unknown_unit'],
        ], $r->body['units']);
        $this->assertBal(8, 4, 0, $sku);
        $this->assertBal(0, 0, 0, $sku, 'VERIFY');
        self::assertSame(4, $this->view($site, 'V1')['available'], 'a and c lower availability; b came back with its unit');
        self::assertSame(['allocated', 'allocated', 'allocated', 'allocated', 'shipped', 'allocated'],
            array_map(fn (string $u): ?string => $this->unitState($site, $u), ['a', 'b', 'c', 'd', 'e', 'f']));
        self::assertNull(self::$db->value("SELECT sku_id FROM reservation_unit WHERE unit_id = 'f'"), 'an unlinked unit moves no bucket');
        self::assertSame(['uncancel allocated' => 2, 'transfer_out/uncancel_from_verify on_hand' => -1, 'transfer_in/uncancel_from_verify on_hand' => 1,
            'uncancel/after_verify allocated' => 1], $this->ledgerOf('mixed'));

        // a partial replay of the same units under the same key is the same request in another order
        $again = $this->res->uncancel($site, '1', ['a', 'b', 'c', 'd', 'e', 'f', 'zz'], 'mixed');
        self::assertTrue($again->replayed);
        self::assertEquals($r->body, $again->body);
        $other = $this->res->uncancel($site, '1', ['a'], 'mixed');
        self::assertSame([422, 'idempotency_key_reused'], [$other->status, $other->body['error']]);
    }

    public function testAStrictItemTakenBelowZeroIsFlaggedLikeACommitWithoutAHold(): void
    {
        $site = $this->site();
        $strict = $this->item('strict', 2);
        $back = $this->item('backorder', 0);
        $stopped = $this->item('stopped', 5);
        $this->listing($site, 'VS', $strict);
        $this->listing($site, 'VB', $back);
        $this->listing($site, 'VX', $stopped);
        $this->commit($site, '1', [self::line('VS', 'a', 'b'), self::line('VB', 'g'), self::line('VX', 'x')]);
        $this->cancel($site, '1', ['a', 'b', 'g', 'x'], true);
        $this->commit($site, '2', [self::line('VS', 'c')]);
        $events = (int) self::$db->value('SELECT COUNT(*) FROM oversell_event');
        $this->assertBal(2, 1, 0, $strict);

        $r = $this->uncancel($site, '1', ['a', 'b', 'g', 'x'], 'short');
        self::assertSame(['a' => 'uncancelled', 'b' => 'uncancelled', 'g' => 'uncancelled', 'x' => 'uncancelled'], self::results($r));
        $this->assertBal(2, 3, 0, $strict);
        $this->assertBal(0, 1, 0, $back);
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$strict]);
        self::assertSame([['sku_code' => $code, 'kind' => 'uncancel_short', 'shortfall' => 1, 'available_after' => -1]], $r->body['oversell'],
            'shortfall = min(units, -available): the strict item only; backorder below zero is intended; the stopped item is not short');
        $ev = self::$db->all('SELECT sku_id, channel_id, reservation_id, order_ref, kind, shortfall, available_after FROM oversell_event ORDER BY id LIMIT 100 OFFSET ' . $events);
        self::assertSame([['sku_id' => $strict, 'channel_id' => $site->channelId, 'reservation_id' => (int) $this->reservation($site, '1')['id'],
            'order_ref' => '1', 'kind' => 'uncancel_short', 'shortfall' => 1, 'available_after' => -1]], $ev, 'one event, not a second movement_short (R6)');
        self::assertSame(-1, $this->view($site, 'VS')['available']);

        // a replay flags nothing more
        self::assertTrue($this->res->uncancel($site, '1', ['a', 'b', 'g', 'x'], 'short')->replayed);
        self::assertSame($events + 1, (int) self::$db->value('SELECT COUNT(*) FROM oversell_event'));
    }

    public function testAUnitCancelledWhileUnlinkedIsAdoptedWithTodaysLink(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $listing = $this->listing($site, 'V2', null);
        $this->listing($site, 'V3', null);
        $this->commit($site, '1', [self::line('V2', 'a'), self::line('V3', 'b')]);
        $this->cancel($site, '1', ['a', 'b'], true);
        $this->relink($listing, $sku, 'mapped', 2); // adoption leaves cancelled units alone
        self::assertNull(self::$db->value("SELECT sku_id FROM reservation_unit WHERE unit_id = 'a'"));
        $this->assertBal(10, 0, 0, $sku);

        $r = $this->uncancel($site, '1', ['a', 'b'], 'adopt');
        self::assertSame(['a' => 'uncancelled', 'b' => 'uncancelled'], self::results($r));
        self::assertSame(['sku_id' => $sku, 'units_per_item' => 2],
            self::$db->one("SELECT sku_id, units_per_item FROM reservation_unit WHERE unit_id = 'a'"));
        $this->assertBal(10, 2, 0, $sku);
        self::assertSame(['uncancel/adopted: listing ' . $listing . ' allocated' => 2], $this->ledgerOf('adopt'));
        self::assertNull(self::$db->value("SELECT sku_id FROM reservation_unit WHERE unit_id = 'b'"), 'still unlinked: recorded only');
        // it now ships like any linked unit
        $this->ship($site, '1', ['a', 'b']);
        $this->assertBal(8, 0, 0, $sku);
    }

    public function testUnknownAndUnpaidOrdersAreRefusedWithoutStoringTheKey(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $refused = function (string $ref, string $key, string $code, int $status) use ($site): void {
            try {
                $this->res->uncancel($site, $ref, ['a'], $key);
                self::fail("uncancel on {$ref} must be refused");
            } catch (CwException $e) {
                self::assertSame([$code, $status], [$e->errorCode, $e->httpStatus], $ref);
            }
        };
        $refused('nope', 'k-unknown', 'unknown_order', 404);
        $this->release($site, 'tomb'); // a tombstone
        $refused('tomb', 'k-tomb', 'not_committed', 409);
        $this->reserve($site, '7', [self::line('V1', 'a', 'b')]);
        $this->cancel($site, '7', ['a'], true); // cancelled while held
        $refused('7', 'k-7', 'not_committed', 409);
        $this->reserve($site, '8', [self::line('V1', 'c')]);
        $this->release($site, '8', 1);
        $refused('8', 'k-8', 'not_committed', 409);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE idem_key LIKE 'k-%'"));
        $this->assertBal(5, 0, 1, $sku);

        // paid without the cancelled unit: the same key now works, and allocates it without a hold
        $this->commit($site, '7', [self::line('V1', 'b')]);
        $this->assertBal(5, 1, 0, $sku);
        $r = $this->uncancel($site, '7', ['a'], 'k-7');
        self::assertFalse($r->replayed);
        self::assertSame(['a' => 'uncancelled'], self::results($r));
        $this->assertBal(5, 2, 0, $sku);
    }

    public function testUnitsThatAreNotCancelledChangeNothing(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '1', [self::line('V1', 'a', 'b', 'c', 'd')]);
        $this->commit($site, '1', [self::line('V1', 'a', 'b', 'c')]); // d was held but not paid for: released
        $this->ship($site, '1', ['b', 'c']);
        $this->res->returnUnits($site, '1', ['c'], $this->key('ret'));
        $before = [$this->bal($sku), $this->ledgerCount(), (int) self::$db->value('SELECT COUNT(*) FROM stock_change')];

        $r = $this->uncancel($site, '1', ['a', 'b', 'c', 'd', 'e']);
        self::assertSame(['a' => 'not_cancelled', 'b' => 'shipped', 'c' => 'returned', 'd' => 'released', 'e' => 'unknown_unit'], self::results($r));
        self::assertSame([], $r->body['oversell']);
        self::assertSame($before, [$this->bal($sku), $this->ledgerCount(), (int) self::$db->value('SELECT COUNT(*) FROM stock_change')]);
    }

    public function testEachCancelAndUncancelNeedsItsOwnKey(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a')]);
        $this->cancel($site, '1', ['a'], true, 'cnl-1');
        $this->uncancel($site, '1', ['a'], 'unc-1');
        $this->assertBal(5, 1, 0, $sku);
        // the site cancels again but re-uses the first cancel's key: a replay, nothing changes
        $r = $this->res->cancel($site, '1', ['a'], true, 'cnl-1');
        self::assertTrue($r->replayed);
        self::assertSame('allocated', $this->unitState($site, 'a'));
        // a key per cycle does it
        $this->cancel($site, '1', ['a'], true, 'cnl-2');
        self::assertSame('cancelled', $this->unitState($site, 'a'));
        self::assertTrue($this->res->uncancel($site, '1', ['a'], 'unc-1')->replayed);
        self::assertSame('cancelled', $this->unitState($site, 'a'), 'an old uncancel replayed changes nothing');
        self::assertSame(['a' => 'uncancelled'], self::results($this->uncancel($site, '1', ['a'], 'unc-2')));
        $this->assertBal(5, 1, 0, $sku);
    }

    public function testReplayedThreeTimesHasOneEffect(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 3);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b', 'c')]);
        $this->cancel($site, '1', ['a'], true);
        $this->cancel($site, '1', ['b', 'c'], false);
        $this->commit($site, '2', [self::line('V1', 'd')]); // takes the unit a's cancel freed
        $first = $this->res->uncancel($site, '1', ['a', 'b', 'c'], 'unc-3x');
        self::assertSame(200, $first->status);
        self::assertFalse($first->replayed);
        $state = $this->fingerprint();
        for ($i = 0; $i < 2; $i++) {
            $again = $this->res->uncancel($site, '1', ['c', 'b', 'a'], 'unc-3x');
            self::assertTrue($again->replayed);
            self::assertSame($first->status, $again->status);
            self::assertEquals($first->body, $again->body);
            self::assertSame($state, $this->fingerprint(), "replay {$i} changed something");
        }
        self::assertSame(['a' => 'uncancelled', 'b' => 'uncancelled_from_verify', 'c' => 'uncancelled_from_verify'], self::results($first));
        self::assertSame(1, count($first->body['oversell']));
        $this->assertBal(3, 4, 0, $sku);
    }

    /** @return array<string, int> */
    private function fingerprint(): array
    {
        $out = [];
        foreach (['stock_ledger', 'stock_change', 'reservation', 'reservation_unit', 'oversell_event', 'count_review', 'idempotency', 'audit_log'] as $t) {
            $out[$t] = (int) self::$db->value("SELECT COUNT(*) FROM {$t}");
        }
        $out['balances'] = crc32(json_encode(self::$db->all('SELECT * FROM stock_balance ORDER BY warehouse_id, sku_id')));
        $out['units'] = crc32(json_encode(self::$db->all('SELECT channel_id, unit_id, state, sku_id FROM reservation_unit ORDER BY channel_id, unit_id')));
        $out['reviews'] = crc32(json_encode(self::$db->all('SELECT id, status, dedupe_key FROM count_review ORDER BY id')));
        return $out;
    }

    public function testConcurrentReplaysHaveOneEffect(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b', 'c', 'd')]);
        $this->cancel($site, '1', ['a', 'b'], true);
        $this->cancel($site, '1', ['c', 'd'], false);
        $this->assertBal(8, 0, 0, $sku);
        $this->assertBal(2, 0, 0, $sku, 'VERIFY');

        $op = ['op' => 'uncancel', 'channel_id' => $site->channelId, 'channel_code' => 'vpg', 'order_ref' => '1',
            'unit_ids' => ['a', 'b', 'c', 'd'], 'key' => 'unc-race'];
        $out = WorkerPool::run(array_fill(0, 6, [$op]), 1.0);
        self::assertSame([200], array_values(array_unique(array_column($out['results'], 'status'))), json_encode($out['results']));
        self::assertCount(1, array_unique(array_column($out['results'], 'body_hash')), 'every copy gets the one stored answer');
        self::assertSame(1, count(array_filter($out['results'], static fn (array $r): bool => $r['replayed'] === false)));
        self::assertSame(0, $out['deadlocks']);
        $this->assertBal(10, 4, 0, $sku);
        $this->assertBal(0, 0, 0, $sku, 'VERIFY');
        self::assertSame(8, (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE idem_key = 'unc-race'"));
        self::assertSame(0, $this->openRecounts());
    }

    public function testConcurrentUncancelsUnderDifferentKeysTakeEachUnitBackOnce(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b', 'c')]);
        $this->cancel($site, '1', ['a'], true);
        $this->cancel($site, '1', ['b', 'c'], false);
        $jobs = [];
        for ($i = 0; $i < 6; $i++) {
            $jobs[] = [['op' => 'uncancel', 'channel_id' => $site->channelId, 'channel_code' => 'vpg', 'order_ref' => '1',
                'unit_ids' => ['a', 'b', 'c'], 'key' => "unc-{$i}"]];
        }
        $out = WorkerPool::run($jobs);
        self::assertSame([200], array_values(array_unique(array_column($out['results'], 'status'))), json_encode($out['results']));
        self::assertSame(0, $out['deadlocks']);
        $byUnit = [];
        foreach (self::$db->column("SELECT response_body FROM idempotency WHERE idem_key LIKE 'unc-%'") as $body) {
            foreach (json_decode((string) $body, true)['units'] as $u) {
                $byUnit[$u['unit_id']][] = $u['result'];
            }
        }
        ksort($byUnit);
        foreach (['a' => 'uncancelled', 'b' => 'uncancelled_from_verify', 'c' => 'uncancelled_from_verify'] as $unit => $result) {
            $counts = array_count_values($byUnit[$unit]);
            ksort($counts);
            self::assertSame(['not_cancelled' => 5, $result => 1], $counts, $unit);
        }
        $this->assertBal(10, 3, 0, $sku);
        $this->assertBal(0, 0, 0, $sku, 'VERIFY');
    }
}
