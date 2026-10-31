<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Queues;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Movements;
use CW\Queues\Corrections;
use CW\Relay\RelayIntake;
use CW\Tests\Support\StockTestCase;

/**
 * "Confirm all ready" for ERPNext corrections (the owner, 31 Oct 2026; docs/decisions.md CA1-CA12), on real corrections relayed by a store:
 * readiness() gives each rule of Needs a look its reason, in its order (a-f), and nothing else (no difference is ready); the setting moves the
 * big-change limit; a press books exactly the ready ones, each as one-by-one would (a twin confirmed one by one: the same ledger line, the
 * same audit rows, the same key); a correction that changed between the check page and the press is left with "changed", one that arrived
 * after the page is not touched, one bad id never stops the rest, the cap leaves the rest "not reached", a second press books nothing twice
 * (each id's key replays), and the press is ONE audit row. The stock invariants hold after every test (StockTestCase).
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
        // The audit rows a confirmation writes (what, about what, by whom), and the confirmation's own detail but its document and time.
        $audit = static fn (int $after): array => array_map(static function (array $r): array {
            $d = json_decode((string) $r['detail'], true);
            unset($d['ref'], $d['at']);
            ksort($d);
            return [$r['action'], $r['entity_type'], $r['actor'], $r['action'] === 'count_review.confirm' ? $d : null];
        }, self::$db->all("SELECT action, entity_type, actor, CAST(detail AS CHAR) AS detail FROM audit_log WHERE id > ? AND action <> ? ORDER BY id",
            [$after, Corrections::RUN_ACTION]));
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
        $detail = json_decode((string) self::$db->value('SELECT CAST(detail AS CHAR) FROM audit_log WHERE id = ?', [$again['run']]), true);
        self::assertSame([[$p1, $p2], 0, 0, 0], [$detail['replayed'], $detail['booked'], $detail['units_added'], $detail['units_taken']], 'the run says nothing was booked');
        // Back, a new note, pressed again: refused per id (the key holds the first answer), still nothing booked.
        $third = $queue->confirmAll($this->person('stock_controller'), [$p1, $p2], 'another note this time');
        self::assertSame([], $third['confirmed']);
        self::assertSame(['idempotency_key_reused', 'idempotency_key_reused'], array_map(static fn (array $l): string => $l['why']['error'], $third['left']));
        self::assertSame($ledger, $this->ledgerCount());
    }

    /** The cap: after $maxIds ids (or the time) the rest are "not reached", untouched; the press is ONE audit row with what it did. */
    public function testThePressStopsAtItsCapAndIsOneAuditRow(): void
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
        self::assertSame($runs + 1, (int) self::$db->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [Corrections::RUN_ACTION]), 'ONE row for the press');
        $row = self::$db->one('SELECT actor, staff_user_id, entity_type, entity_id, CAST(detail AS CHAR) AS detail FROM audit_log WHERE id = ?', [$run['run']]);
        self::assertSame([$controller->actor, $controller->staffUserId, 'count_review', null], [$row['actor'], (int) $row['staff_user_id'], $row['entity_type'], $row['entity_id']]);
        $detail = json_decode((string) $row['detail'], true);
        ksort($detail);
        self::assertSame(['asked' => 3, 'booked' => 2, 'confirmed' => [$c1, $c3], 'left' => [['id' => $c2, 'why' => ['code' => 'not_reached']]], 'note' => 'two at a time',
            'replayed' => [], 'units_added' => 3, 'units_taken' => 3], $detail);
        // Out of time before the first: nothing is confirmed, every id is "not reached".
        $late = $queue->confirmAll($controller, [$c2], 'out of time', Corrections::BULK_MAX, 0.0);
        self::assertSame([[], 'not_reached'], [$late['confirmed'], $late['left'][0]['why']['code']]);
        $this->assertBal(4, 0, 0, $s1);
        $this->assertBal(5, 0, 0, $s3);
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
}
