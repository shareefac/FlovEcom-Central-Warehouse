<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Relay;

use CW\Caller;
use CW\ChannelAdmin;
use CW\CwException;
use CW\Relay\RelayIntake;
use CW\SiteWriter\SiteModes;
use CW\Tests\Support\StockTestCase;

/**
 * The relay on CW's side (pack F; docs/decisions.md PF1-PF4): the three stock kinds of POST /v1/movements are booked once per
 * document + line whatever key they come under; ERPNext's corrections go to the count-review queue and its mode changes to the
 * policy-review queue, once each, and only the changes that change something; each kind needs its grant.
 */
final class RelayIntakeTest extends StockTestCase
{
    private RelayIntake $relay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->relay = new RelayIntake(self::$db);
    }

    public function testARelayedLineIsBookedOncePerDocumentAndLineWhateverTheKey(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in');
        $sku = $this->item('strict', 0);
        $this->listing($site, 'V1', $sku, 2);
        $req = ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-2026-00042', 'lines' => [['variant_id' => 'V1', 'qty' => 5, 'line_index' => 0]]];

        $first = $this->ok($this->moves->record($site, $req, 'relay-a'));
        self::assertSame('booked', $first->body['lines'][0]['result']);
        $this->assertBal(10, 0, 0, $sku);
        // The store's queue sends the document again under another key (a restore, a lost answer): nothing more is booked.
        $again = $this->ok($this->moves->record($site, $req, 'relay-b'));
        self::assertSame([['line_index' => 0, 'result' => 'already_recorded', 'first' => 'booked']], $again->body['lines']);
        $this->assertBal(10, 0, 0, $sku);
        // A document with one new line and the old one: only the new one is booked.
        $more = $this->ok($this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-2026-00042', 'lines' => [
            ['variant_id' => 'V1', 'qty' => 5, 'line_index' => 0], ['variant_id' => 'V1', 'qty' => 1, 'line_index' => 1]]], 'relay-c'));
        self::assertSame(['already_recorded', 'booked'], array_column($more->body['lines'], 'result'));
        $this->assertBal(12, 0, 0, $sku);
        // The same key replays the stored answer (Idempotency, unchanged).
        self::assertTrue($this->moves->record($site, $req, 'relay-a')->replayed);
        $rows = self::$db->all("SELECT kind, doc_ref, line_index, CAST(outcome AS CHAR) AS outcome, sku_id, units FROM relay_line ORDER BY line_index");
        self::assertSame([
            ['kind' => 'goods_in', 'doc_ref' => 'ACC-PINV-2026-00042', 'line_index' => 0, 'outcome' => 'booked', 'sku_id' => $sku, 'units' => 10],
            ['kind' => 'goods_in', 'doc_ref' => 'ACC-PINV-2026-00042', 'line_index' => 1, 'outcome' => 'booked', 'sku_id' => $sku, 'units' => 2],
        ], $rows);
        // Another kind with the same document name and line is another line (a sales invoice is not a purchase invoice).
        $this->grant($site, 'goods_in', 'erp_sale');
        $sale = $this->ok($this->moves->record($site, ['type' => 'erp_sale', 'doc_ref' => 'ACC-PINV-2026-00042', 'lines' => [['variant_id' => 'V1', 'qty' => 1, 'line_index' => 0]]], 'relay-d'));
        self::assertSame('booked', $sale->body['lines'][0]['result']);
        $this->assertBal(10, 0, 0, $sku);
    }

    public function testASuspendedLineSentAgainAfterItsVariantWasMatchedIsNotBookedTwice(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in');
        $sku = $this->item('strict', 0);
        $listing = $this->listing($site, 'NEW1', null);
        $req = ['type' => 'goods_in', 'doc_ref' => 'PINV-7', 'lines' => [['variant_id' => 'NEW1', 'qty' => 3, 'line_index' => 4, 'description' => 'New flavour']]];
        $r = $this->ok($this->moves->record($site, $req, 'k1'));
        self::assertSame(['line_index' => 4, 'result' => 'suspense', 'reason' => 'unlinked_listing'], $r->body['lines'][0]);
        $suspense = (int) self::$db->value('SELECT id FROM goods_in_suspense');
        self::assertSame($suspense, (int) self::$db->value('SELECT queue_id FROM relay_line'));
        // The variant is matched meanwhile; the store sends the document again: the line waits in suspense, it is not booked as well.
        $this->relink($listing, $sku);
        $again = $this->ok($this->moves->record($site, $req, 'k2'));
        self::assertSame(['line_index' => 4, 'result' => 'already_recorded', 'first' => 'suspense', 'reason' => 'unlinked_listing'], $again->body['lines'][0]);
        $this->assertBal(0, 0, 0, $sku);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM goods_in_suspense'));
    }

    public function testTheSameLineWithOtherContentIsAConflictAndBooksNothing(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in');
        $sku = $this->item('strict', 0);
        $this->listing($site, 'V1', $sku);
        $this->ok($this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'PINV-8', 'lines' => [['variant_id' => 'V1', 'qty' => 5]]], 'k1'));
        $r = $this->ok($this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'PINV-8', 'lines' => [['variant_id' => 'V1', 'qty' => 6]]], 'k2'));
        self::assertSame(['line_index' => 0, 'result' => 'conflict', 'first' => 'booked'], $r->body['lines'][0]);
        $this->assertBal(5, 0, 0, $sku);
    }

    public function testStaffMovementsAreNotKeyedPerLine(): void
    {
        $sku = $this->item('strict', 0);
        $req = ['type' => 'goods_in', 'doc_ref' => 'PINV-STAFF', 'lines' => [['sku_id' => $sku, 'qty' => 2]]];
        $this->ok($this->moves->record(self::staff(), $req, 's1'));
        $this->ok($this->moves->record(self::staff(), $req, 's2'));
        $this->assertBal(4, 0, 0, $sku);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM relay_line'), 'only a store\'s relay is keyed per document line');
    }

    public function testCorrectionsGoToTheCountReviewQueueOnce(): void
    {
        $site = $this->site();
        $this->grant($site, 'erp_reconciliation');
        $sku = $this->item('strict', 20);
        $this->listing($site, 'V1', $sku, 2);
        $req = ['doc_ref' => 'MAT-RECO-2026-00012', 'posted_at' => '2026-09-26T17:00:00Z', 'lines' => [
            ['variant_id' => 'V1', 'qty' => 7, 'line_index' => 0], ['variant_id' => 'NOPE', 'qty' => 3, 'line_index' => 1, 'description' => 'Unknown 10ml']]];
        $r = $this->ok($this->relay->reconciliations($site, $req, 'reco-1'));
        self::assertSame(['queued', 'suspense'], array_column($r->body['lines'], 'result'));
        self::assertSame(14, $r->body['lines'][0]['units'], 'the figure in the item\'s units (7 x 2)');
        $review = self::$db->one("SELECT warehouse_id, sku_id, source, proposed_qty, ref, CAST(status AS CHAR) AS status, CAST(detail AS CHAR) AS detail FROM count_review");
        self::assertSame([self::warehouseId('MAIN'), $sku, 'erp_reconciliation', 14, 'MAT-RECO-2026-00012', 'open'],
            [$review['warehouse_id'], $review['sku_id'], $review['source'], $review['proposed_qty'], $review['ref'], $review['status']]);
        $detail = json_decode((string) $review['detail'], true);
        self::assertSame(['2026-09-26 17:00:00.000000', 7, 2, 'V1'], [$detail['posted_at'], $detail['qty'], $detail['u'], $detail['variant_id']]);
        self::assertSame(['erp_reconciliation', 'stock_reconciliation', 3, 'unknown_listing', 'erp_reconciliation:MAT-RECO-2026-00012#1'],
            array_values(self::$db->one('SELECT movement_type, doc_type, qty, reason, dedupe_key FROM goods_in_suspense')));
        $this->assertBal(20, 0, 0, $sku);

        // Sent again under another key: one row each, the answer says so.
        $again = $this->ok($this->relay->reconciliations($site, $req, 'reco-2'));
        self::assertSame(['already_recorded', 'already_recorded'], array_column($again->body['lines'], 'result'));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM count_review'));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM goods_in_suspense'));
        self::assertTrue($this->relay->reconciliations($site, $req, 'reco-1')->replayed);
        $this->assertBal(20, 0, 0, $sku);
    }

    public function testModeChangesThatChangeNothingAreRecordedButNotQueued(): void
    {
        $site = $this->site();
        $this->grant($site, 'erp_mode');
        $legacy = $this->item('legacy', 0);
        $strict = $this->item('strict', 0);
        $this->listing($site, 'L1', $legacy);
        $this->listing($site, 'S1', $strict);
        $this->listing($site, 'U1', null);
        // CW has From-Warehouse for the legacy item on this store.
        (new SiteModes(self::$db))->set(Caller::system('relay_test'), $legacy, 'From-Warehouse', ['vpg'], '', 'set by the test');
        $r = $this->ok($this->relay->modes($site, ['doc_ref' => 'vpg-mode-1', 'lines' => [
            ['variant_id' => 'L1', 'mode' => 'From-Warehouse', 'line_index' => 0],                     // what CW has: nothing changes
            ['variant_id' => 'S1', 'mode' => 'From-Warehouse', 'line_index' => 1],                     // strict already
            ['variant_id' => 'L1', 'mode' => 'In-Stock', 'previous_mode' => 'In-Stock', 'line_index' => 2], // the store says it was so
            ['variant_id' => 'U1', 'mode' => 'In-Stock', 'line_index' => 3],                           // not matched: no item
            ['variant_id' => 'NOPE', 'mode' => 'In-Stock', 'line_index' => 4],                         // not known
            ['variant_id' => 'L1', 'mode' => 'Out-Of-Stock', 'low_stock' => 2, 'line_index' => 5],      // a change
            ['variant_id' => 'S1', 'mode' => 'In-Stock', 'line_index' => 6],                           // strict -> backorder
        ]], 'mode-1'));
        self::assertSame(['no_change', 'no_change', 'no_change', 'skipped', 'skipped', 'queued', 'queued'], array_column($r->body['lines'], 'result'));
        self::assertSame(['same_in_cw', 'same_rule', 'same_on_store', 'unlinked_listing', 'unknown_listing'],
            array_slice(array_column($r->body['lines'], 'reason'), 0, 5));
        $rows = self::$db->all("SELECT sku_id, site_mode, CAST(current_policy AS CHAR) AS cur, CAST(proposed_policy AS CHAR) AS proposed, CAST(status AS CHAR) AS status FROM policy_review ORDER BY id");
        self::assertSame([
            ['sku_id' => $legacy, 'site_mode' => 'Out-Of-Stock', 'cur' => 'legacy', 'proposed' => 'stopped', 'status' => 'open'],
            ['sku_id' => $strict, 'site_mode' => 'In-Stock', 'cur' => 'strict', 'proposed' => 'backorder', 'status' => 'open'],
        ], $rows);
        self::assertSame(7, (int) self::$db->value("SELECT COUNT(*) FROM relay_line WHERE kind = 'erp_mode'"), 'every line is recorded');

        // A newer change of the same variant replaces the open one (settled, never removed); the same message again adds nothing.
        $this->ok($this->relay->modes($site, ['doc_ref' => 'vpg-mode-2', 'lines' => [['variant_id' => 'L1', 'mode' => 'In-Stock']]], 'mode-2'));
        self::assertSame([['dismissed', 'superseded'], ['open', null], ['open', null]],
            array_map(static fn (array $x): array => [$x['s'], $x['r']], self::$db->all('SELECT CAST(status AS CHAR) AS s, resolution AS r FROM policy_review ORDER BY id')));
        $this->ok($this->relay->modes($site, ['doc_ref' => 'vpg-mode-2', 'lines' => [['variant_id' => 'L1', 'mode' => 'In-Stock']]], 'mode-3'));
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM policy_review'));
    }

    /** Review finding 2 (Codex, 14 Oct 2026): a document name of 191 bytes left no room for the line in the queue keys. */
    public function testALongDocumentNameKeepsEveryLineApart(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in', 'erp_reconciliation', 'erp_mode');
        $a = $this->item('strict', 0);
        $b = $this->item('strict', 0);
        $this->listing($site, 'VA', $a);
        $this->listing($site, 'VB', $b);
        $doc = str_repeat('D', 191);
        $r = $this->ok($this->relay->reconciliations($site, ['doc_ref' => $doc, 'lines' => [['variant_id' => 'VA', 'qty' => 1, 'line_index' => 0],
            ['variant_id' => 'VB', 'qty' => 2, 'line_index' => 1]]], 'long-1'));
        self::assertSame(['queued', 'queued'], array_column($r->body['lines'], 'result'));
        self::assertSame([$a, $b], array_map('intval', self::$db->column("SELECT sku_id FROM count_review WHERE source = 'erp_reconciliation' ORDER BY id")));
        $this->ok($this->relay->modes($site, ['doc_ref' => $doc, 'lines' => [['variant_id' => 'VA', 'mode' => 'In-Stock', 'line_index' => 0],
            ['variant_id' => 'VB', 'mode' => 'In-Stock', 'line_index' => 1]]], 'long-2'));
        self::assertSame([$a, $b], array_map('intval', self::$db->column("SELECT sku_id FROM policy_review ORDER BY id")));
        $this->ok($this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => $doc, 'lines' => [['variant_id' => 'N1', 'qty' => 1, 'line_index' => 0],
            ['variant_id' => 'N2', 'qty' => 1, 'line_index' => 1], ['variant_id' => 'N3', 'qty' => 1, 'line_index' => 2]]], 'long-3'));
        $this->ok($this->relay->reconciliations($site, ['doc_ref' => $doc, 'lines' => [['variant_id' => 'N1', 'qty' => 1, 'line_index' => 5],
            ['variant_id' => 'N2', 'qty' => 1, 'line_index' => 6]]], 'long-4'));
        self::assertSame(['N1', 'N2', 'N3', 'N1', 'N2'], self::$db->column('SELECT external_variant_id FROM goods_in_suspense ORDER BY id'));
        // Every line points at its own queue row (ids are per queue table: suspense lines of both kinds share one table).
        $queues = self::$db->column("SELECT CONCAT(IF(outcome = 'suspense', 'suspense', kind), ':', queue_id) FROM relay_line WHERE queue_id IS NOT NULL ORDER BY id");
        self::assertCount(9, $queues);
        self::assertSame(count($queues), count(array_unique($queues)), 'every line has its own queue row');
    }

    /** Review finding 3: a newer change back to what CW has closes the older request instead of leaving it to be accepted. */
    public function testANewerChangeThatChangesNothingClosesTheOlderRequest(): void
    {
        $site = $this->site();
        $this->grant($site, 'erp_mode');
        $sku = $this->item('strict', 0);
        $this->listing($site, 'V1', $sku);
        $this->ok($this->relay->modes($site, ['doc_ref' => 'm-1', 'changed_at' => $this->ago(600), 'lines' => [['variant_id' => 'V1', 'mode' => 'In-Stock']]], 'm-1'));
        self::assertSame('open', (string) self::$db->value('SELECT CAST(status AS CHAR) FROM policy_review'));
        $r = $this->ok($this->relay->modes($site, ['doc_ref' => 'm-2', 'changed_at' => $this->ago(300), 'lines' => [['variant_id' => 'V1', 'mode' => 'From-Warehouse']]], 'm-2'));
        self::assertSame('no_change', $r->body['lines'][0]['result']);
        self::assertSame(['dismissed', 'superseded'], array_values(self::$db->one('SELECT CAST(status AS CHAR) AS s, resolution FROM policy_review')));
    }

    /** Review finding 4: the change time decides, not the arrival order. */
    public function testAnOlderChangeArrivingLateNeverReplacesANewerOne(): void
    {
        $site = $this->site();
        $this->grant($site, 'erp_mode');
        $sku = $this->item('strict', 0);
        $this->listing($site, 'V1', $sku);
        $this->ok($this->relay->modes($site, ['doc_ref' => 'new', 'changed_at' => $this->ago(300), 'lines' => [['variant_id' => 'V1', 'mode' => 'In-Stock']]], 'k-new'));
        $late = $this->ok($this->relay->modes($site, ['doc_ref' => 'old', 'changed_at' => $this->ago(3600), 'lines' => [['variant_id' => 'V1', 'mode' => 'Out-Of-Stock']]], 'k-old'));
        self::assertSame(['no_change', 'older_than_known'], [$late->body['lines'][0]['result'], $late->body['lines'][0]['reason'] ?? null]);
        self::assertSame([['In-Stock', 'open']], array_map(static fn (array $r): array => [$r['site_mode'], $r['s']],
            self::$db->all('SELECT site_mode, CAST(status AS CHAR) AS s FROM policy_review ORDER BY id')));
    }

    /** Review finding 5: an item-only change of one store never closes another store's request. */
    public function testItemOnlyChangesOfTwoStoresStayApart(): void
    {
        $vpg = $this->site('vpg');
        $alt = $this->site('alt');
        $this->grant($vpg, 'erp_mode');
        $this->grant($alt, 'erp_mode');
        $sku = $this->item('strict', 0);
        self::$db->exec("INSERT INTO sku_erp_item (item_code, sku_id) VALUES ('ERP-ITEM-1', ?)", [$sku]);
        $this->ok($this->relay->modes($vpg, ['doc_ref' => 'v-1', 'lines' => [['erp_item_code' => 'ERP-ITEM-1', 'mode' => 'In-Stock']]], 'v-1'));
        $this->ok($this->relay->modes($alt, ['doc_ref' => 'a-1', 'lines' => [['erp_item_code' => 'ERP-ITEM-1', 'mode' => 'Out-Of-Stock']]], 'a-1'));
        self::assertSame(['open', 'open'], self::$db->column('SELECT CAST(status AS CHAR) FROM policy_review ORDER BY id'));
    }

    /** An ISO time $seconds ago (CW's real clock: the relay checks times against it). */
    private function ago(int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() - $seconds);
    }

    public function testEachKindNeedsItsGrantAndTheToolGivesAndEndsThem(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in');
        foreach ([
            fn () => $this->relay->reconciliations($site, ['doc_ref' => 'R-1', 'lines' => [['variant_id' => 'V1', 'qty' => 1]]], 'g1'),
            fn () => $this->relay->modes($site, ['doc_ref' => 'M-1', 'lines' => [['variant_id' => 'V1', 'mode' => 'In-Stock']]], 'g2'),
        ] as $call) {
            try {
                $call();
                self::fail('refused without its grant');
            } catch (CwException $e) {
                self::assertSame([403, 'relay_not_allowed'], [$e->httpStatus, $e->errorCode]);
            }
        }
        try {
            $this->relay->reconciliations(self::staff(), ['doc_ref' => 'R-1', 'lines' => [['variant_id' => 'V1', 'qty' => 1]]], 'g3');
            self::fail('a person does not relay');
        } catch (CwException $e) {
            self::assertSame('channel_required', $e->errorCode);
        }
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE path LIKE '/v1/relay/%'"), 'a refusal is not stored');

        // bin/channel_set.php --relay=on|off (ChannelAdmin::configure): a dry run writes nothing; --apply gives all five, audited.
        $admin = new ChannelAdmin(self::$db);
        $dry = $admin->configure('vpg', null, null, 'Tester', false, null, ChannelAdmin::RELAY_GRANTS);
        self::assertSame(['relay'], $dry['changed']);
        self::assertSame(['goods_in'], json_decode((string) self::$db->value('SELECT movement_types FROM channel WHERE code = ?', ['vpg']), true));
        $on = $admin->configure('vpg', null, null, 'Tester', true, null, ChannelAdmin::RELAY_GRANTS);
        self::assertTrue($on['applied']);
        $granted = json_decode((string) self::$db->value('SELECT movement_types FROM channel WHERE code = ?', ['vpg']), true);
        sort($granted);
        $all = ChannelAdmin::RELAY_GRANTS;
        sort($all);
        self::assertSame($all, $granted);
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'channel.relay' ORDER BY id DESC LIMIT 1"), true);
        self::assertSame(['goods_in'], $audit['from']);
        self::assertSame('Tester', $audit['by']);
        $this->ok($this->relay->modes($site, ['doc_ref' => 'M-2', 'lines' => [['variant_id' => 'V1', 'mode' => 'In-Stock']]], 'g4'));
        $off = $admin->configure('vpg', null, null, 'Tester', true, null, []);
        self::assertSame([], $off['after']['relay']);
        self::assertStringContainsString('relay ended', implode(' ', $off['warnings']));
        try {
            $this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'P-9', 'lines' => [['variant_id' => 'V1', 'qty' => 1]]], 'g5');
            self::fail('the relay is ended');
        } catch (CwException $e) {
            self::assertSame('movement_not_allowed', $e->errorCode);
        }
        try {
            $admin->configure('vpg', null, null, 'Tester', true, null, ['count']);
            self::fail('counts are never a store\'s');
        } catch (CwException $e) {
            self::assertSame('bad_movement_type', $e->errorCode);
        }
    }

    public function testBadMessagesAreRefusedBeforeAnythingIsStored(): void
    {
        $site = $this->site();
        $this->grant($site, 'erp_reconciliation', 'erp_mode');
        $cases = [
            [fn () => $this->relay->reconciliations($site, ['lines' => [['variant_id' => 'V1', 'qty' => 1]]], 'b1'), 'doc_ref_required'],
            [fn () => $this->relay->reconciliations($site, ['doc_ref' => 'R', 'lines' => [['variant_id' => 'V1', 'qty' => -1]]], 'b2'), 'bad_lines'],
            [fn () => $this->relay->reconciliations($site, ['doc_ref' => 'R', 'lines' => [['qty' => 1]]], 'b3'), 'bad_lines'],
            [fn () => $this->relay->reconciliations($site, ['doc_ref' => 'R', 'lines' => [['variant_id' => 'A', 'qty' => 1, 'line_index' => 1], ['variant_id' => 'B', 'qty' => 1, 'line_index' => 1]]], 'b4'), 'bad_lines'],
            [fn () => $this->relay->modes($site, ['doc_ref' => 'M', 'lines' => [['variant_id' => 'V1', 'mode' => 'Sold']]], 'b5'), 'bad_lines'],
            [fn () => $this->relay->modes($site, ['doc_ref' => 'M', 'lines' => [['variant_id' => 'V1', 'mode' => 'In-Stock', 'low_stock' => -2]]], 'b6'), 'bad_lines'],
        ];
        foreach ($cases as [$call, $code]) {
            try {
                $call();
                self::fail("refused: {$code}");
            } catch (CwException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM relay_line'));
    }
}
