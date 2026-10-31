<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Queues;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Movements;
use CW\Queues\Corrections;
use CW\Relay\RelayIntake;
use CW\Tests\Support\StatementHook;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * "Confirm all ready" for ERPNext corrections (the owner, 31 Oct 2026; docs/decisions.md CA1-CA12), on real corrections relayed by a store:
 * readiness() gives each rule of Needs a look its reason, in its order (a-f), and nothing else (no difference is ready); the setting moves the
 * big-change limit; a press books exactly the ready ones, each as one-by-one would (a twin confirmed one by one: the same ledger line, the
 * same audit rows, the same key); a correction that changed between the check page and the press is left with "changed", one that arrived
 * after the page is not touched, one bad id never stops the rest, the cap leaves the rest "not reached", a second press books nothing twice
 * (each id's key replays), and the press is saved as its run before its first id and its end after its last. The fix round (CA13-CA21):
 * a second connection that tightens the limit or links another website product to the item waits for the booking, or the booking waits
 * for it and leaves the correction; the press stops starting ids at its deadline and never waits long on one; a database failure or an
 * exhausted deadlock inside one id rolls it back whole and the next one books; the list reads a fixed number of queries for 253
 * corrections and its figures are effect()'s. The stock invariants hold after every test (StockTestCase).
 */
final class ConfirmAllTest extends StockTestCase
{
    private Caller $site;
    private ?string $limit = null;
    private string $setUpAt = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->site();
        $this->grant($this->site, 'erp_reconciliation');
        $this->limit = (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?", [Corrections::BIG_SETTING]);
        // Every item's stock is booked "three hours ago" (setUpAt), every correction after it, every later movement now.
        $this->setUpAt = Clock::db(Clock::now()->modify('-3 hours'));
    }

    protected function tearDown(): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$this->limit === '' ? '100' : $this->limit, Corrections::BIG_SETTING]);
        parent::tearDown();
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------------------------

    /** An item with $onHand at MAIN, sold by the store as $variants (each one website product), its stock booked three hours ago. */
    private function stocked(int $onHand, string ...$variants): int
    {
        $sku = $this->item('strict', $onHand);
        foreach ($variants as $v) {
            $this->listing($this->site, $v, $sku);
        }
        self::$db->exec('UPDATE stock_ledger SET created_at = ? WHERE sku_id = ?', [$this->setUpAt, $sku]);
        return $sku;
    }

    /** ERPNext sets $variant to $qty, $minutesAgo minutes ago (relayed now): the correction's id. */
    private function correction(string $variant, int $qty, int $minutesAgo = 120): int
    {
        $posted = Clock::now()->modify("-{$minutesAgo} minutes")->format('Y-m-d\TH:i:s.u\Z');
        $doc = 'MAT-RECO-' . $variant . '-' . bin2hex(random_bytes(2));
        $this->ok((new RelayIntake(self::$db))->reconciliations($this->site, ['doc_ref' => $doc, 'posted_at' => $posted, 'lines' => [['variant_id' => $variant, 'qty' => $qty]]],
            $this->key('reco')));
        return (int) self::$db->value('SELECT id FROM count_review WHERE ref = ?', [$doc]);
    }

    /** A movement booked now by staff (real clock), with its own doc_ref. */
    private function move(string $type, int $sku, int $qty, ?string $docRef = null): void
    {
        $this->ok((new Movements(self::$db))->record(self::staff(), ['type' => $type, 'warehouse' => 'MAIN', 'doc_ref' => $docRef ?? 'DOC-' . bin2hex(random_bytes(3)),
            'lines' => [['sku_id' => $sku, 'qty' => $qty]]], $this->key('move')));
    }

    /** A staff member holding $role. */
    private function person(string $role): Caller
    {
        $n = bin2hex(random_bytes(3));
        $uid = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES (?, ?, ?, 'x')",
            ["ca-{$role}-{$n}", "CA {$role}", "ca-{$role}-{$n}@test.invalid"]);
        self::$db->exec('INSERT INTO staff_role (staff_user_id, role) VALUES (?, ?)', [$uid, $role]);
        return Caller::staff($uid);
    }

    private function setLimit(int $units): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [(string) $units, Corrections::BIG_SETTING]);
    }

    /** readiness() as id => the reason's code ('' when ready). @return array<int, string> */
    private static function reasons(): array
    {
        $out = [];
        foreach ((new Corrections(self::$db))->readiness() as $r) {
            $out[(int) $r['id']] = $r['why'] === null ? '' : (string) $r['why']['code'];
        }
        return $out;
    }

    private static function state(int $id): string
    {
        return (string) self::$db->value('SELECT CAST(status AS CHAR) FROM count_review WHERE id = ?', [$id]);
    }

    // ---- the rules -----------------------------------------------------------------------------------------------------------------

    public function testEachRuleOfNeedsALookHasItsReasonAndNothingElseIsLeft(): void
    {
        $controller = $this->person('stock_controller');
        // Ready: a rise, a fall, no difference, a change of exactly the limit (100: "over 100" is not 100).
        $this->stocked(10, 'UP');
        $this->stocked(10, 'DOWN');
        $this->stocked(6, 'SAME');
        $this->stocked(0, 'EDGE');
        // a. counted on the shelf after ERPNext set it
        $counted = $this->stocked(10, 'COUNTED');
        // b. a newer correction of the item confirmed already
        $this->stocked(20, 'NEWER');
        // c. a big change (+150 over 100)
        $this->stocked(0, 'BIG');
        // d. below zero: the shelf moves down after the correction (10 -> 1), the correction takes 2 off
        $low = $this->stocked(10, 'LOW');
        // e. sold as two website products of the store
        $this->stocked(5, 'TWO-A', 'TWO-B');
        // f. a one-time stock fix booked for the item after the correction
        $fixed = $this->stocked(5, 'FIXED');

        $up = $this->correction('UP', 13);
        $down = $this->correction('DOWN', 7);
        $same = $this->correction('SAME', 6);
        $edge = $this->correction('EDGE', 100);
        $a = $this->correction('COUNTED', 15);
        $older = $this->correction('NEWER', 10, 150);
        $newer = $this->correction('NEWER', 8, 90);
        $c = $this->correction('BIG', 150);
        $d = $this->correction('LOW', 8);
        $e = $this->correction('TWO-A', 7);
        $f = $this->correction('FIXED', 7);

        $this->ok((new Movements(self::$db))->record(self::staff(), ['type' => 'count', 'counted_at' => Clock::now()->modify('-30 minutes')->format('Y-m-d\TH:i:s.u\Z'),
            'lines' => [['sku_id' => $counted, 'qty' => 10]]], $this->key('count')));
        (new Corrections(self::$db))->confirm($controller, $newer, 'the newer one, by hand');
        $this->move('adjustment', $low, -9);
        $this->move('adjustment', $fixed, 3, 'listening-fix:vapeandgo:2026-10-31');

        self::assertSame([$up => '', $down => '', $same => '', $edge => '', $a => 'counted', $older => 'newer', $c => 'big', $d => 'below_zero', $e => 'variants',
            $f => 'fixed'], self::reasons());
        $rows = array_column((new Corrections(self::$db))->readiness(), null, 'id');
        self::assertSame(['code' => 'big', 'delta' => 150, 'limit' => 100], $rows[$c]['why'], 'the units and the limit, for its words');
        self::assertSame(['code' => 'below_zero', 'after' => -1], $rows[$d]['why']);
        self::assertSame(['code' => 'variants', 'links' => 2], $rows[$e]['why']);
        self::assertSame('fixed', $rows[$f]['why']['code']);
        self::assertSame((string) self::$db->value("SELECT MAX(created_at) FROM stock_ledger WHERE sku_id = ? AND doc_ref LIKE 'listening-fix:%'", [$fixed]),
            $rows[$f]['why']['fix_at'], 'when the fix was booked: the day its words name');
        self::assertSame([3, -3, 0, 100], [$rows[$up]['effect']['delta'], $rows[$down]['effect']['delta'], $rows[$same]['effect']['delta'], $rows[$edge]['effect']['delta']]);

        // The two rules confirm() refuses on its own are the ones a press can never book either; nothing else is held back.
        $before = $this->ledgerCount();
        $run = (new Corrections(self::$db))->confirmAll($controller, [$up, $down, $same, $edge, $a, $older, $c, $d, $e, $f], 'Confirm all, checked the list');
        self::assertSame([$up, $down, $same, $edge], $run['confirmed']);
        self::assertSame($before + 3, $this->ledgerCount(), 'three differences booked (one line each); no difference books nothing');
        self::assertSame(['resolved', 'resolved', 'resolved', 'resolved'], array_map(self::state(...), [$up, $down, $same, $edge]));
        self::assertSame('no_difference', (string) self::$db->value('SELECT resolution FROM count_review WHERE id = ?', [$same]), 'closed as one by one closes it');
        foreach ([$a, $older, $c, $d, $e, $f] as $left) {
            self::assertSame('open', self::state($left), "{$left} is left for a person");
        }
        self::assertSame([[$a, 'changed', 'newer_count'], [$older, 'changed', 'newer_correction'], [$c, 'changed', 'not_ready'], [$d, 'changed', 'not_ready'],
            [$e, 'changed', 'not_ready'], [$f, 'changed', 'not_ready']],
            array_map(static fn (array $l): array => [$l['id'], $l['why']['code'], $l['why']['error'] ?? ''], $run['left']),
            'ids that are not ready when pressed (a hand-made form) are refused under their locks, each with the refusal');
        self::assertSame(['big', 'below_zero', 'variants', 'fixed'], array_map(static fn (array $l): string => $l['why']['now']['code'], array_slice($run['left'], 2)));
        self::assertSame(1, $this->bal($low)['on_hand'], 'nothing booked on the item that would go below zero');
    }

    /** The rules in their order on ONE correction (c, e, f together; then a): the first that applies is its reason; the setting moves c. */
    public function testTheFirstRuleThatAppliesIsTheReasonAndTheSettingMovesTheBigLimit(): void
    {
        $sku = $this->stocked(0, 'ALL-A', 'ALL-B');
        $other = $this->stocked(0, 'SMALL');
        $id = $this->correction('ALL-A', 150);
        $small = $this->correction('SMALL', 40);
        $this->move('adjustment', $sku, 2, 'listening-fix:vapeandgo:2026-11-01');
        self::assertSame([$id => 'big', $small => ''], self::reasons(), 'big before variants and fixed');
        $this->setLimit(200);
        self::assertSame([$id => 'variants', $small => ''], self::reasons(), 'the setting: 150 is not over 200; then two website products');
        $this->setLimit(0);
        self::assertSame([$id => 'big', $small => 'big'], self::reasons(), '0: every difference is big');
        $this->setLimit(200);
        self::$db->exec("UPDATE channel_listing SET sku_id = NULL, status = 'unmapped' WHERE channel_id = ? AND external_variant_id = 'ALL-B'", [$this->site->channelId]);
        self::assertSame([$id => 'fixed', $small => ''], self::reasons(), 'one website product now: the stock fix is next');
        $this->ok((new Movements(self::$db))->record(self::staff(), ['type' => 'count', 'counted_at' => Clock::now()->format('Y-m-d\TH:i:s.u\Z'),
            'lines' => [['sku_id' => $sku, 'qty' => 2]]], $this->key('count')));
        self::assertSame([$id => 'counted', $small => ''], self::reasons(), 'a count after it comes first of all');
        // The setting is read at booking time too: at 30 the small one (+40) is left as it is pressed.
        $this->setLimit(30);
        $run = (new Corrections(self::$db))->confirmAll($this->person('stock_controller'), [$small], 'Confirm all after the limit changed');
        self::assertSame([], $run['confirmed']);
        self::assertSame(['code' => 'changed', 'error' => 'not_ready', 'now' => ['code' => 'big', 'delta' => 40, 'limit' => 30]], $run['left'][0]['why']);
        $this->assertBal(0, 0, 0, $other);
        // The setting not set: the default, 100 (the code reads it with a default, so it may run before 0054).
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', ['""', Corrections::BIG_SETTING]);
        self::assertSame(Corrections::BIG_DEFAULT, Corrections::bigLimit(new \CW\Settings(self::$db)));
        self::assertSame(100, Corrections::BIG_DEFAULT);
    }

    // ---- the press -----------------------------------------------------------------------------------------------------------------

    /** A press books the ready ones as one by one would: a twin confirmed by hand books the same line and writes the same audit rows. */
    public function testAPressBooksTheReadyOnesExactlyAsOneByOneWould(): void
    {
        $controller = $this->person('stock_controller');
        $twinA = $this->stocked(10, 'TWIN-A');
        $twinB = $this->stocked(10, 'TWIN-B');
        $this->stocked(0, 'HUGE');
        $a = $this->correction('TWIN-A', 13);
        $b = $this->correction('TWIN-B', 13);
        $huge = $this->correction('HUGE', 500);
        $queue = new Corrections(self::$db);
        // The audit rows a confirmation writes (what, about what, by whom), and the confirmation's own detail but its document and time; the
        // press's own two rows (its run and its end) are not a confirmation's.
        $audit = static fn (int $after): array => array_map(static function (array $r): array {
            $d = json_decode((string) $r['detail'], true);
            unset($d['ref'], $d['at']);
            ksort($d);
            return [$r['action'], $r['entity_type'], $r['actor'], $r['action'] === 'count_review.confirm' ? $d : null];
        }, self::$db->all("SELECT action, entity_type, actor, CAST(detail AS CHAR) AS detail FROM audit_log WHERE id > ? AND action NOT IN (?, ?) ORDER BY id",
            [$after, Corrections::RUN_ACTION, Corrections::RUN_END_ACTION]));
        $line = static fn (int $sku): array => (array) self::$db->one("SELECT movement_type, bucket, qty_delta, actor, REPLACE(note, ?, '#') AS note FROM stock_ledger "
            . "WHERE sku_id = ? AND movement_type = 'adjustment'", ['#' . self::$db->value('SELECT id FROM count_review WHERE sku_id = ?', [$sku]), $sku]);

        $mark = (int) self::$db->value('SELECT COALESCE(MAX(id), 0) FROM audit_log');
        $queue->confirm($controller, $a, 'shelf checked by the owner');
        $byHand = $audit($mark);
        $mark = (int) self::$db->value('SELECT COALESCE(MAX(id), 0) FROM audit_log');
        $run = $queue->confirmAll($controller, [$b], 'shelf checked by the owner');
        $pressed = $audit($mark);

        self::assertSame([$b], $run['confirmed']);
        self::assertNotSame([], $byHand);
        self::assertSame($byHand, $pressed, 'the same audit rows, by the same person, with the same detail');
        self::assertSame($line($twinA), $line($twinB), 'the same ledger line: an adjustment of +3 on hand by the person');
        self::assertSame(3, (int) $line($twinB)['qty_delta']);
        self::assertStringStartsWith('MAT-RECO-TWIN-B-', (string) self::$db->value("SELECT doc_ref FROM stock_ledger WHERE sku_id = ? AND movement_type = 'adjustment'",
            [$twinB]), 'the ERPNext document on the ledger');
        $this->assertBal(13, 0, 0, $twinA);
        $this->assertBal(13, 0, 0, $twinB);
        self::assertSame([['resolved', 'booked', 'shelf checked by the owner'], ['resolved', 'booked', 'shelf checked by the owner']],
            array_map(static fn (int $id): array => array_values((array) self::$db->one('SELECT CAST(status AS CHAR), resolution, note FROM count_review WHERE id = ?', [$id])), [$a, $b]));
        // The key is one-by-one's: a click on the record's Confirm after the press shows the press's answer and books nothing.
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE idem_key = ? AND response_status = 200", [Corrections::KEY . $b]));
        self::assertTrue($queue->confirm($controller, $b, 'shelf checked by the owner')['replayed']);
        $this->assertBal(13, 0, 0, $twinB);
        self::assertSame('open', self::state($huge), 'the big one is not in the press: left, and listed');
        self::assertSame([['id' => $huge, 'why' => ['code' => 'big', 'delta' => 500, 'limit' => 100]]], $run['left']);
    }

    /** Between the check page and the press: one changes (left: "changed"), one is answered by someone, a bad id; one arrives after it. */
    public function testWhatChangedAfterThePageIsLeftAndOneBadIdNeverStopsTheRest(): void
    {
        $controller = $this->person('stock_controller');
        $skuX = $this->stocked(4, 'X');
        $skuY = $this->stocked(4, 'Y');
        $skuZ = $this->stocked(4, 'Z');
        $skuW = $this->stocked(4, 'W');
        $x = $this->correction('X', 6);
        $y = $this->correction('Y', 6);
        $z = $this->correction('Z', 6);
        // The check page: these three are ready, and it carries their ids.
        self::assertSame([$x => '', $y => '', $z => ''], self::reasons());
        // Meanwhile: X gets a second website product (now "Sold as 2 variants"); Y is closed by someone; W's correction arrives.
        $this->listing($this->site, 'X-2', $skuX);
        (new Corrections(self::$db))->dismiss($this->person('stock_controller'), $y, 'closed by hand meanwhile');
        $w = $this->correction('W', 6, 60);
        $before = $this->ledgerCount();
        $run = (new Corrections(self::$db))->confirmAll($controller, [$x, 999_999, $y, $z], 'the check page of ten past');
        self::assertSame([$z], $run['confirmed'], 'the rest went on after each refusal');
        self::assertSame($before + 1, $this->ledgerCount());
        self::assertSame([[$x, 'not_ready'], [999_999, 'unknown_queue_row'], [$y, 'queue_settled']],
            array_map(static fn (array $l): array => [$l['id'], $l['why']['error']], array_filter($run['left'], static fn (array $l): bool => $l['why']['code'] === 'changed')));
        self::assertSame(['variants', 'links' => 2], [$run['left'][0]['why']['now']['code'], 'links' => $run['left'][0]['why']['now']['links']]);
        $this->assertBal(4, 0, 0, $skuX);
        $this->assertBal(4, 0, 0, $skuY);
        $this->assertBal(6, 0, 0, $skuZ);
        self::assertSame(['open', 'dismissed', 'open'], [self::state($x), self::state($y), self::state($w)], 'W arrived after the page: not touched');
        $this->assertBal(4, 0, 0, $skuW);
        self::assertNotContains($w, array_column($run['left'], 'id'), 'W is ready: not "left for you", still waiting for the next press');
    }

    /** A second press of the same page (double click, back and again) books nothing twice: each id's key replays its first answer. */
    public function testASecondPressBooksNothingTwice(): void
    {
        $controller = $this->person('stock_controller');
        $s1 = $this->stocked(2, 'P1');
        $s2 = $this->stocked(9, 'P2');
        $p1 = $this->correction('P1', 5);
        $p2 = $this->correction('P2', 4);
        $queue = new Corrections(self::$db);
        $first = $queue->confirmAll($controller, [$p1, $p2], 'pressed once');
        self::assertSame([$p1, $p2], $first['confirmed']);
        $ledger = $this->ledgerCount();
        $again = $queue->confirmAll($controller, [$p1, $p2], 'pressed once');
        self::assertSame([$p1, $p2], $again['confirmed'], 'the same answers, replayed');
        self::assertSame($ledger, $this->ledgerCount(), 'nothing booked twice');
        $this->assertBal(5, 0, 0, $s1);
        $this->assertBal(4, 0, 0, $s2);
        $detail = $queue->run($again['run']);
        self::assertSame([[$p1, $p2], 0, 0, 0], [$detail['replayed'], $detail['booked'], $detail['units_added'], $detail['units_taken']], 'the run says nothing was booked');
        // Back, a new note, pressed again: refused per id (the key holds the first answer), still nothing booked.
        $third = $queue->confirmAll($this->person('stock_controller'), [$p1, $p2], 'another note this time');
        self::assertSame([], $third['confirmed']);
        self::assertSame(['idempotency_key_reused', 'idempotency_key_reused'], array_map(static fn (array $l): string => $l['why']['error'], $third['left']));
        self::assertSame($ledger, $this->ledgerCount());
    }

    /**
     * The cap: after $maxIds ids (or the time) the rest are "not reached", untouched. The press is saved as its run row FIRST (state running,
     * the note and the ids it was given: the done page's id), then its end row (what it did), both by the person.
     */
    public function testThePressStopsAtItsCapAndIsSavedAsItsRun(): void
    {
        $controller = $this->person('stock_controller');
        $s1 = $this->stocked(1, 'C1');
        $s2 = $this->stocked(1, 'C2');
        $s3 = $this->stocked(8, 'C3');
        $c1 = $this->correction('C1', 4);
        $c2 = $this->correction('C2', 4);
        $c3 = $this->correction('C3', 5);
        $queue = new Corrections(self::$db);
        $runs = (int) self::$db->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [Corrections::RUN_ACTION]);
        $run = $queue->confirmAll($controller, [$c1, $c3, $c2], 'two at a time', 2);
        self::assertSame([$c1, $c3], $run['confirmed']);
        self::assertSame([['id' => $c2, 'why' => ['code' => 'not_reached']]], $run['left']);
        self::assertSame('open', self::state($c2));
        $this->assertBal(1, 0, 0, $s2);
        self::assertSame($runs + 1, (int) self::$db->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [Corrections::RUN_ACTION]), 'ONE run row for the press');
        $row = self::$db->one('SELECT actor, staff_user_id, entity_type, entity_id, CAST(detail AS CHAR) AS detail FROM audit_log WHERE id = ?', [$run['run']]);
        self::assertSame([$controller->actor, $controller->staffUserId, 'count_review', null], [$row['actor'], (int) $row['staff_user_id'], $row['entity_type'], $row['entity_id']]);
        $start = json_decode((string) $row['detail'], true);
        ksort($start);
        self::assertSame(['asked' => 3, 'ids' => [$c1, $c3, $c2], 'note' => 'two at a time', 'state' => 'running'], $start, 'saved before the first id');
        self::assertLessThan((int) self::$db->value("SELECT MIN(id) FROM audit_log WHERE action = 'count_review.confirm'"), $run['run'], 'before the first confirmation');
        $end = self::$db->one('SELECT actor, action, CAST(detail AS CHAR) AS detail FROM audit_log WHERE entity_type = ? AND entity_id = ?', ['count_review', 'confirm_all:' . $run['run']]);
        self::assertSame([$controller->actor, Corrections::RUN_END_ACTION], [$end['actor'] ?? null, $end['action'] ?? null], 'and its end, after the last');
        $detail = $queue->run($run['run']);
        self::assertSame(['finished', 'two at a time', 3, [$c1, $c3], [], 2, 3, 3, [['id' => $c2, 'why' => ['code' => 'not_reached']]]],
            [$detail['state'], $detail['note'], $detail['asked'], $detail['confirmed'], $detail['replayed'], $detail['booked'], $detail['units_added'], $detail['units_taken'],
                $detail['left']]);
        self::assertSame($controller->staffUserId, $detail['staff_user_id']);
        // Out of time before the first: nothing is confirmed, every id is "not reached".
        $late = $queue->confirmAll($controller, [$c2], 'out of time', Corrections::BULK_MAX, 0.0);
        self::assertSame([[], 'not_reached'], [$late['confirmed'], $late['left'][0]['why']['code']]);
        $this->assertBal(4, 0, 0, $s1);
        $this->assertBal(5, 0, 0, $s3);
        self::assertNull($queue->run(999_999_999));
    }

    /** Who presses: only who answers ERPNext corrections, with a note of 3 to 500 characters (QueueRules::note); nothing booked otherwise. */
    public function testOnlyThePersonWithTheJobPressesAndTheNoteIsOneByOnes(): void
    {
        $sku = $this->stocked(1, 'N1');
        $id = $this->correction('N1', 3);
        $queue = new Corrections(self::$db);
        foreach ([[$this->person('purchasing_desk'), 'not my job', 403, 'role_not_allowed'], [$this->person('admin'), 'admin never answers', 403, 'role_not_allowed'],
            [$this->person('stock_controller'), 'x', 400, 'note_required']] as [$who, $note, $status, $code]) {
            try {
                $queue->confirmAll($who, [$id], $note);
                self::fail("expected {$status} {$code}");
            } catch (CwException $e) {
                self::assertSame([$status, $code], [$e->httpStatus, $e->errorCode]);
            }
        }
        self::assertSame('open', self::state($id));
        $this->assertBal(1, 0, 0, $sku);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [Corrections::RUN_ACTION]), 'a refused press is no run');
    }

    // ---- the fix round (CA13-CA21) ---------------------------------------------------------------------------------------------------

    /** Runs $fn while every statement self::$db executes is followed by $after (never inside itself). */
    private static function hooked(\Closure $after, \Closure $fn): mixed
    {
        $busy = false;
        $guard = static function (string $sql) use ($after, &$busy): void {
            if ($busy) {
                return;
            }
            $busy = true;
            try {
                $after($sql);
            } finally {
                $busy = false;
            }
        };
        self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [StatementHook::class, [$guard]]);
        try {
            return $fn();
        } finally {
            self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
    }

    /** A second connection that gives up a lock wait after $seconds. */
    private static function other(int $seconds = 1): Db
    {
        $o = TestDb::connect();
        $o->exec('SET SESSION innodb_lock_wait_timeout = ' . $seconds);
        return $o;
    }

    private static function lockWait(Db $db): int
    {
        return (int) $db->value('SELECT @@SESSION.innodb_lock_wait_timeout');
    }

    /** CA14 (Codex HIGH 3): the big-change limit is read under a lock: tightening it waits until the booking that read it is committed. */
    public function testTheBigLimitIsHeldWhileACorrectionIsBooked(): void
    {
        $this->stocked(10, 'L80');
        $id = $this->correction('L80', 90);
        $other = self::other();
        $waited = null;
        $run = self::hooked(static function (string $sql) use ($other, &$waited): void {
            if ($waited !== null || !str_starts_with(ltrim($sql), 'UPDATE count_review SET detail = JSON_SET')) {
                return;
            }
            try {
                $other->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', ['50', Corrections::BIG_SETTING]);
                $waited = false;
            } catch (\PDOException $e) {
                $waited = Db::driverCode($e) === 1205;
            }
        }, fn (): array => (new Corrections(self::$db))->confirmAll($this->person('stock_controller'), [$id], 'booked under the limit it read'));
        self::assertSame([$id], $run['confirmed'], '+80 under 100');
        self::assertTrue($waited, 'a change of the limit waits for the booking that read it (the setting row, FOR SHARE)');
        // Committed: the change goes through, and the booking-time check of the next press reads it.
        $other->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', ['50', Corrections::BIG_SETTING]);
        $this->stocked(10, 'L60');
        $next = $this->correction('L60', 70);
        $left = (new Corrections(self::$db))->confirmAll($this->person('stock_controller'), [$next], 'after the limit went down');
        self::assertSame(['code' => 'big', 'delta' => 60, 'limit' => 50], $left['left'][0]['why']['now'] ?? null);
    }

    /**
     * CA13 (Codex HIGH 2): rule e and the link writers. Every link change reads the item's row FOR SHARE (DecisionService::lockSkus); the
     * gate holds the item's row FOR UPDATE, after the balance (the stock core's order), while it counts the links and books. So a link
     * made meanwhile waits for the booking, or the booking waits for the link and then counts it.
     */
    public function testALinkMadeMeanwhileAndTheBookingWaitForEachOther(): void
    {
        $writer = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Mapping/DecisionService.php');
        self::assertStringContainsString("'SELECT id, code, sell_policy, merged_into_sku_id FROM sku WHERE id = ? ' . (\$sid === \$exclusive ? 'FOR UPDATE' : 'FOR SHARE')",
            $writer, 'the link writer reads the item FOR SHARE: the guard this rule relies on');
        // 1. The booking first: a link of a second website product to the item waits until it is committed.
        $sku = $this->stocked(4, 'E1');
        $second = $this->listing($this->site, 'E1-B', null);
        $id = $this->correction('E1', 6);
        $other = self::other();
        $waited = null;
        $run = self::hooked(static function (string $sql) use ($other, $sku, &$waited): void {
            if ($waited !== null || !str_starts_with(ltrim($sql), 'UPDATE count_review SET detail = JSON_SET')) {
                return;
            }
            try {
                $other->one('SELECT id, code, sell_policy, merged_into_sku_id FROM sku WHERE id = ? FOR SHARE', [$sku]);
                $waited = false;
            } catch (\PDOException $e) {
                $waited = Db::driverCode($e) === 1205;
            }
        }, fn (): array => (new Corrections(self::$db))->confirmAll($this->person('stock_controller'), [$id], 'one website product when booked'));
        self::assertSame([$id], $run['confirmed']);
        self::assertTrue($waited, 'the link writer waits for the booking');
        // 2. The link first (not committed yet): the booking waits for it, gives up after the press's short wait and books nothing.
        $sku2 = $this->stocked(4, 'E2');
        $second2 = $this->listing($this->site, 'E2-B', null);
        $id2 = $this->correction('E2', 6);
        $before = $this->ledgerCount();
        $other->pdo()->beginTransaction();
        $other->one('SELECT id, code, sell_policy, merged_into_sku_id FROM sku WHERE id = ? FOR SHARE', [$sku2]);
        $other->exec("UPDATE channel_listing SET sku_id = ?, status = 'mapped', map_version = map_version + 1 WHERE id = ?", [$sku2, $second2]);
        try {
            $press = (new Corrections(self::$db))->confirmAll($this->person('stock_controller'), [$id2], 'a link is being made', Corrections::BULK_MAX,
                Corrections::BULK_SECONDS, 1);
        } finally {
            $other->pdo()->commit();
        }
        self::assertSame([], $press['confirmed']);
        self::assertSame(['changed', 'failed'], [$press['left'][0]['why']['code'], $press['left'][0]['why']['error']]);
        self::assertSame($before, $this->ledgerCount(), 'nothing booked');
        self::assertSame('open', self::state($id2));
        self::assertSame([$id2 => 'variants'], array_intersect_key(self::reasons(), [$id2 => 1]), 'committed: two website products now');
        unset($second);
    }

    /** CA15 (Codex MEDIUM 4): the press stops starting ids at its deadline; it is saved first, so a press that stops shows what it booked. */
    public function testThePressStopsStartingIdsAtItsDeadlineAndAStoppedPressShowsWhatItBooked(): void
    {
        $controller = $this->person('stock_controller');
        $this->stocked(1, 'D1');
        $this->stocked(1, 'D2');
        $d1 = $this->correction('D1', 3);
        $d2 = $this->correction('D2', 3);
        // The clock: the start, the first id at 0 s, the second at 100 s: past the deadline.
        $times = [0.0, 0.0, 100.0];
        $clock = static function () use (&$times): float {
            return count($times) > 1 ? array_shift($times) : $times[0];
        };
        $before = self::lockWait(self::$db);
        $run = (new Corrections(self::$db, $clock))->confirmAll($controller, [$d1, $d2], 'until the deadline');
        self::assertSame([$d1], $run['confirmed']);
        self::assertSame([['id' => $d2, 'why' => ['code' => 'not_reached']]], $run['left']);
        self::assertSame($before, self::lockWait(self::$db), 'the session\'s lock wait is given back');
        // A press that stops in the middle (the worker killed after the first id): its run row was saved first; it shows what it booked.
        $this->stocked(1, 'D3');
        $this->stocked(1, 'D4');
        $d3 = $this->correction('D3', 3);
        $d4 = $this->correction('D4', 3);
        $calls = 0;
        $dying = static function () use (&$calls): float {
            if (++$calls === 3) {
                throw new \Error('the worker was stopped');
            }
            return 0.0;
        };
        try {
            (new Corrections(self::$db, $dying))->confirmAll($controller, [$d3, $d4], 'stopped half way');
            self::fail('the press should have stopped');
        } catch (\Error $e) {
            self::assertSame('the worker was stopped', $e->getMessage());
        }
        self::assertFalse(self::$db->inTransaction());
        self::assertSame($before, self::lockWait(self::$db));
        $stopped = (new Corrections(self::$db))->run((int) self::$db->value('SELECT MAX(id) FROM audit_log WHERE action = ?', [Corrections::RUN_ACTION]));
        self::assertSame(['stopped', 'stopped half way', [$d3], [['id' => $d4, 'why' => ['code' => 'not_reached']]]],
            [$stopped['state'], $stopped['note'], $stopped['confirmed'], $stopped['left']]);
        self::assertSame(['resolved', 'open'], [self::state($d3), self::state($d4)]);
    }

    /** CA15: one id waiting on a lock cannot eat the press: the press's session gives up a lock wait after its short wait, and goes on. */
    public function testOneWaitingIdDoesNotHoldThePress(): void
    {
        $controller = $this->person('stock_controller');
        $held = $this->stocked(2, 'W1');
        $free = $this->stocked(2, 'W2');
        $w1 = $this->correction('W1', 5);
        $w2 = $this->correction('W2', 5);
        $other = self::other(30);
        $other->pdo()->beginTransaction();
        $other->one('SELECT on_hand FROM stock_balance WHERE warehouse_id = ? AND sku_id = ? FOR UPDATE', [self::warehouseId('MAIN'), $held]);
        $before = self::lockWait(self::$db);
        $t = microtime(true);
        try {
            $run = (new Corrections(self::$db))->confirmAll($controller, [$w1, $w2], 'one of them is busy', Corrections::BULK_MAX, Corrections::BULK_SECONDS, 1);
        } finally {
            $other->pdo()->rollBack();
        }
        self::assertLessThan(10.0, microtime(true) - $t, 'a short wait, not the server\'s 50 s');
        self::assertSame([$w2], $run['confirmed']);
        self::assertSame([$w1, 'changed', 'failed'], [$run['left'][0]['id'], $run['left'][0]['why']['code'], $run['left'][0]['why']['error']]);
        self::assertSame($before, self::lockWait(self::$db));
        $this->assertBal(2, 0, 0, $held);
        $this->assertBal(5, 0, 0, $free);
    }

    /**
     * CA16 (Codex MEDIUM 9): a database failure inside one id (a statement that fails after its stock line was written; a deadlock every
     * try) rolls that id back whole (no ledger line, the row open, no stored answer for its key, no transaction left open) and the next id
     * books; the run says which one failed.
     */
    public function testADatabaseFailureInsideOneIdRollsItBackAndTheNextBooks(): void
    {
        $controller = $this->person('stock_controller');
        foreach (['statement' => ['HY000', 1105, 'injected: the statement failed'], 'deadlock' => ['40001', 1213, 'Deadlock found when trying to get lock']] as $case => $info) {
            $bad = $this->stocked(3, "F-{$case}");
            $good = $this->stocked(3, "G-{$case}");
            $f = $this->correction("F-{$case}", 7);
            $g = $this->correction("G-{$case}", 7);
            $badLines = (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE sku_id = ?', [$bad]);
            $tries = $case === 'deadlock' ? 1 + Db::DEADLOCK_RETRIES : 1;
            $thrown = 0;
            $retries = Db::deadlockRetries();
            $run = self::hooked(static function (string $sql) use ($info, $tries, &$thrown): void {
                if ($thrown >= $tries || !str_starts_with(ltrim($sql), 'INSERT INTO stock_ledger')) {
                    return;
                }
                $thrown++;
                $e = new \PDOException('SQLSTATE[' . $info[0] . ']: ' . $info[2]);
                $e->errorInfo = $info;
                throw $e;
            }, fn (): array => (new Corrections(self::$db))->confirmAll($controller, [$f, $g], "a {$case} in the first one"));
            self::assertSame($tries, $thrown, $case);
            self::assertSame([$g], $run['confirmed'], "{$case}: the next one booked");
            self::assertSame([$f, 'changed', 'failed'], [$run['left'][0]['id'], $run['left'][0]['why']['code'], $run['left'][0]['why']['error']], $case);
            self::assertSame('open', self::state($f), $case);
            self::assertSame($badLines, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE sku_id = ?', [$bad]), "{$case}: its stock line rolled back");
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM idempotency WHERE idem_key = ?', [Corrections::KEY . $f]), "{$case}: no stored answer");
            self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'count_review.confirm' AND entity_id = ?", [(string) $f]));
            self::assertFalse(self::$db->inTransaction(), $case);
            self::assertSame($case === 'deadlock' ? Db::DEADLOCK_RETRIES : 0, Db::deadlockRetries() - $retries, "{$case}: retried as every booking is, then left");
            $this->assertBal(3, 0, 0, $bad);
            $this->assertBal(7, 0, 0, $good);
            $saved = (new Corrections(self::$db))->run($run['run']);
            self::assertSame([[$g], $f, 'failed'], [$saved['confirmed'], $saved['left'][0]['id'], $saved['left'][0]['why']['error']]);
            // Nothing stuck: the failed one is confirmed one by one afterwards.
            (new Corrections(self::$db))->confirm($controller, $f, 'by hand after the failure');
            $this->assertBal(7, 0, 0, $bad);
        }
    }

    /**
     * CA17 (Codex MEDIUM 5): the list reads a fixed number of queries for 253 open corrections (not about four each), and its figures are
     * effect()'s, row by row (the booking-time figure stays effect()'s own, read fresh under the locks), carried corrections too.
     */
    public function testTheListReadsAFixedNumberOfQueriesAndItsFiguresAreEffects(): void
    {
        $n = 253;
        $skus = [];
        for ($i = 0; $i < $n; $i++) {
            $skus[] = self::makeSku('Q item ' . $i);
        }
        $values = implode(', ', array_fill(0, $n, "(?, ?, ?, 1, 'mapped')"));
        $params = [];
        foreach ($skus as $i => $sku) {
            array_push($params, $this->site->channelId, 'Q' . $i, $sku);
        }
        self::$db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) VALUES {$values}", $params);
        $this->ok((new Movements(self::$db))->record(self::staff(), ['type' => 'goods_in', 'warehouse' => 'MAIN', 'doc_ref' => 'Q-OPEN',
            'lines' => array_map(static fn (int $sku): array => ['sku_id' => $sku, 'qty' => 5], $skus)], $this->key('q-open')));
        self::$db->exec('UPDATE stock_ledger SET created_at = ?', [$this->setUpAt]);
        // A carried one: two corrections of one item, the older confirmed after the newer was made.
        $carriedSku = $this->stocked(20, 'CARRY');
        $older = $this->correction('CARRY', 10, 150);
        $newer = $this->correction('CARRY', 8, 90);
        (new Corrections(self::$db))->confirm($this->person('stock_controller'), $older, 'the older one first');
        $this->ok((new RelayIntake(self::$db))->reconciliations($this->site, ['doc_ref' => 'MAT-RECO-Q', 'posted_at' => Clock::now()->modify('-100 minutes')->format('Y-m-d\TH:i:s.u\Z'),
            'lines' => array_map(static fn (int $i): array => ['variant_id' => 'Q' . $i, 'qty' => $i % 9], range(0, $n - 1))], $this->key('reco-q')));
        $queue = new Corrections(self::$db);
        $queries = 0;
        $rows = self::hooked(static function () use (&$queries): void {
            $queries++;
        }, fn (): array => $queue->readiness());
        self::assertCount($n + 1, $rows);
        self::assertLessThanOrEqual(30, $queries, "{$queries} queries for " . ($n + 1) . ' open corrections');
        foreach ($rows as $r) {
            self::assertSame($queue->effect((int) $r['warehouse_id'], (int) $r['sku_id'], (int) $r['proposed_qty'], (string) $r['at'], null, (int) $r['id']), $r['effect'],
                "correction {$r['id']}");
        }
        $carried = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['id'] === $newer))[0]['effect'];
        self::assertSame([-10, 8, -2], [$carried['carried'], $carried['target'], $carried['delta']], 'the older one\'s booking counts in what the item held');
        unset($carriedSku);
    }
}
