<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Queues;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Queues\Corrections;
use CW\Queues\ModeChanges;
use CW\Queues\Oversells;
use CW\Queues\Suspense;
use CW\Relay\RelayIntake;
use CW\SiteWriter\SiteModes;
use CW\Tests\Support\StockTestCase;

/**
 * The four queues of Approvals › From the stores (pack F; docs/decisions.md PF5-PF9): each answer settles its row once (a second
 * answer is refused with 409, a second click of the same booking replays its first answer), is audited, needs a note and the
 * queue's job, and never removes a row.
 */
final class StoreQueuesTest extends StockTestCase
{
    private Caller $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->site();
        $this->grant($this->site, 'goods_in', 'supplier_return', 'erp_sale', 'erp_reconciliation', 'erp_mode');
    }

    public function testPickingTheItemBooksASuspendedLineOnceAndSettlesIt(): void
    {
        $sku = $this->item('strict', 0);
        $other = $this->item('strict', 0);
        $this->ok($this->moves->record($this->site, ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-77', 'lines' => [
            ['variant_id' => 'NOPE', 'qty' => 3, 'line_index' => 2, 'description' => 'Mystery 10ml']]], 'relay-1'));
        $id = (int) self::$db->value('SELECT id FROM goods_in_suspense');
        $desk = $this->person('purchasing_desk');
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        $queue = new Suspense(self::$db);
        self::assertSame(1, $queue->openCount());

        $r = $queue->pick($desk, $id, strtolower($code), 2, 'six bottles, two to a box');
        self::assertSame(['booked', $code, 6, false], [$r['result'], $r['sku_code'], $r['units'], $r['replayed']]);
        $this->assertBal(6, 0, 0, $sku);
        $row = self::$db->one('SELECT CAST(status AS CHAR) AS status, resolved_sku_id, resolved_by, note FROM goods_in_suspense WHERE id = ?', [$id]);
        self::assertSame(['resolved', $sku, $desk->staffUserId, 'six bottles, two to a box'], array_values($row));
        $ledger = self::$db->one("SELECT movement_type, doc_ref, actor, qty_delta FROM stock_ledger WHERE bucket = 'on_hand' AND sku_id = ? ORDER BY id DESC LIMIT 1", [$sku]);
        self::assertSame(['goods_in', 'ACC-PINV-77', $desk->actor, 6], array_values($ledger), 'the ERPNext document\'s name on the ledger, the person as actor');
        self::assertSame(0, $queue->openCount());

        // A second click of the same booking shows the first answer; nothing more is booked.
        $again = $queue->pick($desk, $id, $code, 2, 'six bottles, two to a box');
        self::assertTrue($again['replayed']);
        $this->assertBal(6, 0, 0, $sku);
        // Any other answer is refused: the row is settled.
        self::refused(fn () => $queue->dismiss($desk, $id, 'not ours after all'), 409, 'queue_settled');
        $otherCode = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$other]);
        self::refused(fn () => $queue->pick($desk, $id, $otherCode, 1, 'the other one'), 422, 'idempotency_key_reused');
        $this->assertBal(0, 0, 0, $other);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'goods_in_suspense.book' AND entity_id = ?", [(string) $id]));
    }

    public function testASuspendedLineIsRefusedOrDismissedWithoutBooking(): void
    {
        $sku = $this->item('strict', 0);
        $this->ok($this->moves->record($this->site, ['type' => 'erp_sale', 'doc_ref' => 'ACC-SINV-5', 'lines' => [['variant_id' => 'NOPE', 'qty' => 1]]], 'relay-2'));
        $id = (int) self::$db->value('SELECT id FROM goods_in_suspense');
        $desk = $this->person('purchasing_desk');
        $queue = new Suspense(self::$db);
        self::refused(fn () => $queue->pick($desk, $id, 'CW-999999', 1, 'wrong code'), 422, 'unknown_item');
        self::refused(fn () => $queue->pick($desk, $id, 'CW-000001', 0, 'no units'), 400, 'bad_units');
        self::refused(fn () => $queue->pick($desk, $id, 'CW-000001', 1, 'x'), 400, 'note_required');
        self::refused(fn () => $queue->pick($this->person('buyer'), $id, 'CW-000001', 1, 'not my job'), 403, 'role_not_allowed');
        self::refused(fn () => $queue->dismiss($this->person('admin'), $id, 'admin never answers'), 403, 'role_not_allowed');
        $queue->dismiss($desk, $id, 'sold from the shop floor, booked by hand');
        self::assertSame('dismissed', (string) self::$db->value('SELECT CAST(status AS CHAR) FROM goods_in_suspense WHERE id = ?', [$id]));
        self::refused(fn () => $queue->dismiss($desk, $id, 'again'), 409, 'queue_settled');
        $this->assertBal(0, 0, 0, $sku);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM goods_in_suspense'), 'never removed');
    }

    public function testARelayedCorrectionWithoutAnItemOpensACorrectionWhenItsItemIsPicked(): void
    {
        $sku = $this->item('strict', 9);
        $this->ok((new RelayIntake(self::$db))->reconciliations($this->site, ['doc_ref' => 'MAT-RECO-3', 'posted_at' => '2026-09-26T17:30:00Z',
            'lines' => [['variant_id' => 'NOPE', 'qty' => 4]]], 'reco-3'));
        $id = (int) self::$db->value('SELECT id FROM goods_in_suspense');
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        $r = (new Suspense(self::$db))->pick($this->person('stock_controller'), $id, $code, 1, 'that is the blue one');
        self::assertSame('queued', $r['result']);
        $review = self::$db->one("SELECT sku_id, proposed_qty, ref, CAST(detail AS CHAR) AS detail FROM count_review WHERE source = 'erp_reconciliation'");
        self::assertSame([$sku, 4, 'MAT-RECO-3'], [$review['sku_id'], $review['proposed_qty'], $review['ref']]);
        self::assertSame('2026-09-26 17:30:00.000000', json_decode((string) $review['detail'], true)['posted_at']);
        $this->assertBal(9, 0, 0, $sku);
        self::assertSame(1, (new Corrections(self::$db))->openCount());
    }

    public function testConfirmingACorrectionBooksTheDifferenceAtItsMomentOnce(): void
    {
        $sku = $this->item('strict', 10);
        $this->listing($this->site, 'V1', $sku);
        // Two paid orders not sent yet when ERPNext set the figure: the shop showed 8 for sale; ERPNext says 5 for sale.
        $this->ok($this->commit($this->site, '100', [self::line('V1', 'u1', 'u2')], 'unreserved'));
        $at = Clock::db(Clock::now()->modify('+1 second'));
        self::$db->exec('DO SLEEP(1.2)');
        $this->ok((new RelayIntake(self::$db))->reconciliations($this->site, ['doc_ref' => 'MAT-RECO-4', 'posted_at' => str_replace(' ', 'T', substr($at, 0, 19)) . 'Z',
            'lines' => [['variant_id' => 'V1', 'qty' => 5]]], 'reco-4'));
        // After the correction: a delivery of 4 (kept on top) and one of the paid orders is sent.
        $this->book('goods_in', $sku, 4);
        $this->ok($this->ship($this->site, '100', ['u1']));
        $id = (int) self::$db->value("SELECT id FROM count_review WHERE source = 'erp_reconciliation'");
        $queue = new Corrections(self::$db);
        $e = $queue->list()[0]['effect'];
        self::assertSame(['shelf' => 10, 'allocated' => 2, 'target' => 7, 'delta' => -3, 'for_sale' => true, 'carried' => 0, 'newer' => false], $e,
            'the shelf then was 10 with 2 paid; ERPNext\'s 5 for sale means 7 on the shelf');
        $controller = $this->person('stock_controller');
        $r = $queue->confirm($controller, $id, 'checked on the shelf');
        self::assertSame(['booked', -3, false], [$r['result'], $r['delta'], $r['replayed']]);
        // Now: 10 + 4 delivered - 1 sent - 3 corrected = 10 on the shelf, 1 still paid.
        $this->assertBal(10, 1, 0, $sku);
        $row = self::$db->one('SELECT CAST(status AS CHAR) AS status, resolution, resolved_by FROM count_review WHERE id = ?', [$id]);
        self::assertSame(['resolved', 'booked', $controller->staffUserId], array_values($row));
        self::assertNull(self::$db->value('SELECT counted_at FROM stock_balance WHERE sku_id = ?', [$sku]), 'not a count: the count date is left alone');
        self::assertSame(['adjustment', 'MAT-RECO-4'], array_values(self::$db->one("SELECT movement_type, doc_ref FROM stock_ledger WHERE sku_id = ? AND movement_type = 'adjustment'", [$sku])));
        self::assertTrue($queue->confirm($controller, $id, 'checked on the shelf')['replayed'], 'a second click shows the first answer');
        $this->assertBal(10, 1, 0, $sku);
        self::refused(fn () => $queue->dismiss($controller, $id, 'changed my mind'), 409, 'queue_settled');
        self::refused(fn () => $queue->confirm($this->person('purchasing_desk'), $id, 'not my job'), 403, 'role_not_allowed');
    }

    /**
     * Review finding 1 (Codex, 14 Oct 2026): two corrections of one item, both waiting, confirmed in their order, end on the newer
     * figure (20 -> 10 -> 8 is 8, not -2); confirmed the other way round, the older one is refused (the newer already set the figure).
     */
    public function testDelayedCorrectionsOfOneItemDoNotCompound(): void
    {
        $iso = static fn (): string => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        foreach (['in order', 'newer first'] as $case) {
            $sku = $this->item('strict', 20);
            $variant = 'V-' . str_replace(' ', '', $case);
            $this->listing($this->site, $variant, $sku);
            self::$db->exec('DO SLEEP(1.1)');
            $relay = new RelayIntake(self::$db);
            $this->ok($relay->reconciliations($this->site, ['doc_ref' => "R10-{$variant}", 'posted_at' => $iso(), 'lines' => [['variant_id' => $variant, 'qty' => 10]]], $this->key('r')));
            self::$db->exec('DO SLEEP(1.1)');
            $this->ok($relay->reconciliations($this->site, ['doc_ref' => "R8-{$variant}", 'posted_at' => $iso(), 'lines' => [['variant_id' => $variant, 'qty' => 8]]], $this->key('r')));
            self::$db->exec('DO SLEEP(1.1)');
            [$ten, $eight] = array_map('intval', self::$db->column("SELECT id FROM count_review WHERE source = 'erp_reconciliation' AND sku_id = ? ORDER BY id", [$sku]));
            $queue = new Corrections(self::$db);
            $controller = $this->person('stock_controller');
            if ($case === 'in order') {
                $queue->confirm($controller, $ten, 'first one');
                $queue->confirm($controller, $eight, 'second one');
            } else {
                $queue->confirm($controller, $eight, 'the newer one');
                self::refused(fn () => $queue->confirm($controller, $ten, 'the older one'), 409, 'newer_correction');
            }
            $this->assertBal(8, 0, 0, $sku);
        }
    }

    public function testACorrectionCannotOverwriteALaterPhysicalCount(): void
    {
        foreach ([8, 20] as $counted) {
            $sku = $this->item('strict', 20);
            $variant = 'V' . $counted;
            $this->listing($this->site, $variant, $sku);
            self::$db->exec('UPDATE stock_ledger SET created_at = ? WHERE sku_id = ?', [Clock::db(Clock::now()->modify('-2 hours')), $sku]);
            $at = Clock::now()->modify('-1 hour');
            $this->ok((new RelayIntake(self::$db))->reconciliations($this->site, ['doc_ref' => 'OLD-' . $variant,
                'posted_at' => $at->format('Y-m-d\TH:i:s.u\Z'), 'lines' => [['variant_id' => $variant, 'qty' => 10]]], $this->key('correction')));
            $id = (int) self::$db->value("SELECT id FROM count_review WHERE sku_id = ? AND source = 'erp_reconciliation'", [$sku]);
            $this->ok((new \CW\Movements(self::$db))->record(self::staff(), ['type' => 'count',
                'counted_at' => Clock::now()->modify('-30 minutes')->format('Y-m-d\TH:i:s.u\Z'),
                'lines' => [['sku_id' => $sku, 'qty' => $counted]]], $this->key('count')));
            $before = $this->ledgerCount();
            self::refused(fn () => (new Corrections(self::$db))->confirm($this->person('stock_controller'), $id, 'checked the older correction'),
                409, 'newer_count');
            $this->assertBal($counted, 0, 0, $sku);
            self::assertSame($before, $this->ledgerCount(), 'even a zero-delta count supersedes the old correction');
            self::assertSame('open', self::$db->value('SELECT CAST(status AS CHAR) FROM count_review WHERE id = ?', [$id]));
        }
    }

    public function testACorrectionWithNoDifferenceBooksNothingAndOneCanBeDismissed(): void
    {
        $sku = $this->item('strict', 6);
        $this->listing($this->site, 'V1', $sku);
        $this->ok((new RelayIntake(self::$db))->reconciliations($this->site, ['doc_ref' => 'MAT-RECO-5', 'lines' => [['variant_id' => 'V1', 'qty' => 6, 'line_index' => 0],
            ['variant_id' => 'V1', 'qty' => 2, 'line_index' => 1]]], 'reco-5'));
        [$same, $lower] = array_map('intval', self::$db->column("SELECT id FROM count_review WHERE source = 'erp_reconciliation' ORDER BY id"));
        $controller = $this->person('stock_controller');
        $queue = new Corrections(self::$db);
        self::assertSame(['no_difference', 0], array_slice(array_values($queue->confirm($controller, $same, 'figure agrees')), 0, 2));
        $queue->dismiss($controller, $lower, 'a typing mistake in ERPNext');
        $this->assertBal(6, 0, 0, $sku);
        self::assertSame([['resolved', 'no_difference'], ['dismissed', 'dismissed']],
            array_map(static fn (array $x): array => [$x['s'], $x['r']], self::$db->all('SELECT CAST(status AS CHAR) AS s, resolution AS r FROM count_review ORDER BY id')));
    }

    public function testAcceptingAModeChangeSetsTheStoresModeOrTheProtectedItemsRule(): void
    {
        $legacy = $this->item('legacy', 0);
        $strict = $this->item('strict', 0);
        $this->listing($this->site, 'L1', $legacy);
        $this->listing($this->site, 'S1', $strict);
        $this->ok((new RelayIntake(self::$db))->modes($this->site, ['doc_ref' => 'vpg-mode-5', 'lines' => [
            ['variant_id' => 'L1', 'mode' => 'From-Warehouse', 'low_stock' => 3, 'line_index' => 0],
            ['variant_id' => 'S1', 'mode' => 'Out-Of-Stock', 'line_index' => 1]]], 'mode-5'));
        [$l, $s] = array_map('intval', self::$db->column('SELECT id FROM policy_review ORDER BY id'));
        $queue = new ModeChanges(self::$db);
        $rows = $queue->list();
        self::assertSame(['mode', 'policy'], array_column($rows, 'applies'));
        $desk = $this->person('purchasing_desk');

        self::assertSame(['applied' => 'mode', 'changed' => true], $queue->accept($desk, $l, 'the supplier is late'));
        $mode = (new SiteModes(self::$db))->current([$legacy], [$this->site->channelId]);
        self::assertSame(['From-Warehouse', 3], [$mode[SiteModes::key($legacy, (int) $this->site->channelId)]['mode'],
            $mode[SiteModes::key($legacy, (int) $this->site->channelId)]['threshold']]);
        self::assertSame('legacy', (string) self::$db->value('SELECT sell_policy FROM sku WHERE id = ?', [$legacy]), 'only the count gate protects an item');

        self::assertSame(['applied' => 'policy', 'changed' => true], $queue->accept($desk, $s, 'discontinued by the brand'));
        self::assertSame('stopped', (string) self::$db->value('SELECT sell_policy FROM sku WHERE id = ?', [$strict]));
        self::refused(fn () => $queue->accept($desk, $s, 'again'), 409, 'queue_settled');
        self::refused(fn () => $queue->dismiss($desk, $l, 'again'), 409, 'queue_settled');
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'policy_review.accept'"));
        self::assertSame(0, $queue->openCount());
    }

    public function testAnOversoldOrderIsHandledOnceAndItsNotesAreKept(): void
    {
        $sku = $this->item('strict', 1);
        $this->listing($this->site, 'V1', $sku);
        $this->ok($this->commit($this->site, '200', [self::line('V1', 'a', 'b', 'c')], 'unreserved'));
        $id = (int) self::$db->value('SELECT id FROM oversell_event');
        $queue = new Oversells(self::$db);
        self::assertSame(1, $queue->openCount());
        $desk = $this->person('purchasing_desk');
        $queue->addNote($desk, $id, 'customer phoned, happy to wait');
        $queue->handle($desk, $id, 'backordered', 'sent on Friday with the next delivery');
        self::refused(fn () => $queue->handle($desk, $id, 'refunded', 'again'), 409, 'queue_settled');
        self::refused(fn () => $queue->handle($desk, $id, 'lost', 'nope'), 400, 'bad_field');
        $queue->addNote($desk, $id, 'parcel tracking sent');
        $row = $queue->list('resolved')[0];
        self::assertSame(['resolved', 'backordered', 'sent on Friday with the next delivery'], [$row['status'], $row['resolution'], $row['note']]);
        self::assertSame(['customer phoned, happy to wait', 'parcel tracking sent'], array_column($row['notes'], 'note'));
        self::refused(fn () => $queue->addNote($this->person('reviewer'), $id, 'a reviewer only looks'), 403, 'role_not_allowed');
    }

    /** A staff member holding $role (the services re-read the roles inside their transaction). */
    private function person(string $role): Caller
    {
        $n = bin2hex(random_bytes(3));
        $uid = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES (?, ?, ?, 'x')",
            ["q-{$role}-{$n}", "Q {$role}", "q-{$role}-{$n}@test.invalid"]);
        self::$db->exec('INSERT INTO staff_role (staff_user_id, role) VALUES (?, ?)', [$uid, $role]);
        return Caller::staff($uid);
    }

    private static function refused(callable $fn, int $status, string $code): void
    {
        try {
            $fn();
        } catch (CwException $e) {
            self::assertSame([$status, $code], [$e->httpStatus, $e->errorCode], $e->getMessage());
            return;
        }
        self::fail("expected {$status} {$code}");
    }
}
