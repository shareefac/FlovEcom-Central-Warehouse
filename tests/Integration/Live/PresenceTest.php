<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Live;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Documents\Document;
use CW\Live\Presence;
use CW\Ops\Housekeeping;
use CW\Settings;
use CW\Tests\Integration\PurchaseOrders\PurchaseOrderTestCase;

/**
 * Who is here on a draft order (the live drafts plan, step 3; docs/decisions.md LV2-LV7, LV10): one poll says who else has the draft
 * open, where, in which colour and whether they may change it; my own tabs are nobody; a person's row is written only when their place
 * or state changed or it is ten seconds old; a closed tab and a signed-out session leave at once; an active person who stopped polling
 * is gone after live.gone_seconds and an away one after ten minutes; a person switched off is never shown; a tab is never taken over
 * by another session; a document that is not a draft order says what it became and writes nothing; with live.mode off nothing is
 * read or written. The service on the database, through CW\Live\Presence (the screens test drives the route through the kernel).
 */
final class PresenceTest extends PurchaseOrderTestCase
{
    private const TAB_A = 'aaaaaaaaaaaaaaa1';
    private const TAB_A2 = 'aaaaaaaaaaaaaaa2';
    private const TAB_B = 'bbbbbbbbbbbbbbb1';
    private const TAB_V = 'ccccccccccccccc1';

    /** @var array<string, string> setting key => its JSON as it was */
    private array $saved = [];
    private Presence $live;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(Presence::SETTINGS) as $key) {
            $this->saved[$key] = (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        }
        $this->setting('live.mode', '"presence"');
        $this->live = new Presence(self::$db);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $json) {
            $this->setting($key, $json);
        }
        parent::tearDown();
    }

    private function setting(string $key, string $json): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    /** A session id as Auth\Sessions makes them (the sha256 of a cookie token). */
    private static function session(): string
    {
        return hash('sha256', random_bytes(16));
    }

    /** A draft order of $buyer with two lines. */
    private function draftOrder(Caller $buyer): Document
    {
        $s = $this->activeSupplier($buyer, ['name' => 'Live ' . bin2hex(random_bytes(3))]);
        $lines = [];
        foreach (['Live pod', 'Live liquid'] as $name) {
            $si = $this->supplierItem($buyer, (int) $s['id'], self::makeSku($name . ' ' . bin2hex(random_bytes(2))), 10, '12.0000');
            $lines[] = ['supplier_item_id' => (int) $si['id'], 'packs' => 2];
        }
        return $this->draftPo($buyer, (int) $s['id'], $lines);
    }

    /**
     * One poll.
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function beat(Caller $who, string $session, int $id, string $tab, array $fields = [], bool $edits = true): array
    {
        return $this->live->beat('po', $id, (int) $who->staffUserId, $session, $edits, Presence::input(['tab' => $tab] + $fields));
    }

    private static function nameOf(Caller $who): string
    {
        return (string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$who->staffUserId]);
    }

    /** @return array<string, mixed>|null the row of a tab */
    private static function row(int $id, string $tab): ?array
    {
        return self::$db->one("SELECT * FROM draft_presence WHERE kind = 'po' AND record_id = ? AND tab = ?", [$id, $tab]);
    }

    private static function age(int $id, string $tab, int $seconds): void
    {
        self::$db->exec("UPDATE draft_presence SET seen_at = NOW(6) - INTERVAL ? SECOND WHERE kind = 'po' AND record_id = ? AND tab = ?", [$seconds, $id, $tab]);
    }

    public function testAPollSaysWhoElseIsHereWhereAndInWhichColour(): void
    {
        $a = $this->staffUser('buyer');
        $b = $this->staffUser('purchasing_manager');
        $v = $this->staffUser('reviewer');
        [$sa, $sb, $sv] = [self::session(), self::session(), self::session()];
        $d = $this->draftOrder($a);

        $alone = $this->beat($a, $sa, $d->id, self::TAB_A, ['focus' => '1:qty', 'state' => 'active']);
        self::assertSame(['v' => $d->version, 'status' => 'draft', 'mode' => 'presence', 'poll_ms' => 10000, 'here' => []], $alone, 'alone: the slow poll');
        $b1 = $this->beat($b, $sb, $d->id, self::TAB_B, ['focus' => 'h:note', 'typing' => '1']);
        $colours = Presence::colours([(int) $a->staffUserId, (int) $b->staffUserId]);
        self::assertSame(3000, $b1['poll_ms'], 'someone else is here: the quick poll');
        self::assertSame([['id' => $a->staffUserId, 'name' => self::nameOf($a), 'c' => $colours[$a->staffUserId], 'state' => 'active', 'edits' => true,
            'uid' => 1, 'field' => 'qty', 'typing' => false]], $b1['here']);
        $a1 = $this->beat($a, $sa, $d->id, self::TAB_A, ['focus' => '1:qty']);
        self::assertSame([['id' => $b->staffUserId, 'name' => self::nameOf($b), 'c' => $colours[$b->staffUserId], 'state' => 'active', 'edits' => true,
            'uid' => null, 'field' => 'h:note', 'typing' => true]], $a1['here']);

        // A person who can only look is shown so (edits false); every screen gives every person the same colour; nobody sees themselves.
        $v1 = $this->beat($v, $sv, $d->id, self::TAB_V, [], false);
        self::assertSame([$a->staffUserId, $b->staffUserId], array_column($v1['here'], 'id'), 'in id order');
        $a2 = $this->beat($a, $sa, $d->id, self::TAB_A);
        self::assertSame([$b->staffUserId, $v->staffUserId], array_column($a2['here'], 'id'));
        self::assertSame(false, $a2['here'][1]['edits']);
        $all = Presence::colours([(int) $a->staffUserId, (int) $b->staffUserId, (int) $v->staffUserId]);
        foreach ([$v1, $a2] as $answer) {
            foreach ($answer['here'] as $p) {
                self::assertSame($all[$p['id']], $p['c']);
                // Nothing personal beyond a display name: no e-mail, no session, no tab, no time.
                self::assertSame(['id', 'name', 'c', 'state', 'edits', 'uid', 'field', 'typing'], array_keys($p));
            }
        }
        $json = json_encode($a2, JSON_THROW_ON_ERROR);
        foreach ([self::TAB_B, self::TAB_V, substr($sb, 0, 16), '@test.invalid'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }

        // My other tabs are nobody: a second tab of A is not "here" for A, and B sees A once (the active tab's place).
        $this->beat($a, $sa, $d->id, self::TAB_A2, ['focus' => '2:price', 'state' => 'away']);
        $a3 = $this->beat($a, $sa, $d->id, self::TAB_A, ['focus' => '1:qty']);
        self::assertSame([$b->staffUserId, $v->staffUserId], array_column($a3['here'], 'id'));
        $b2 = $this->beat($b, $sb, $d->id, self::TAB_B);
        self::assertSame([[$a->staffUserId, 'active', 1, 'qty'], [$v->staffUserId, 'active', null, null]],
            array_map(static fn (array $p): array => [$p['id'], $p['state'], $p['uid'], $p['field']], $b2['here']), 'one entry a person: the active tab first');
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(DISTINCT staff_user_id) FROM draft_presence WHERE kind = 'po' AND record_id = ?", [$d->id]));
    }

    public function testTheRowIsWrittenOnlyWhenSomethingChangedOrItIsTenSecondsOld(): void
    {
        $a = $this->staffUser('buyer');
        $sa = self::session();
        $d = $this->draftOrder($a);
        $this->beat($a, $sa, $d->id, self::TAB_A, ['focus' => '1:qty']);
        $first = self::row($d->id, self::TAB_A);
        self::assertNotNull($first);
        self::assertSame(['po', $d->id, $a->staffUserId, substr($sa, 0, 16), 1, 'qty', 'active', 0, 1], [$first['kind'], (int) $first['record_id'],
            (int) $first['staff_user_id'], $first['session_handle'], (int) $first['focus_uid'], $first['focus_field'], $first['state'], (int) $first['typing'],
            (int) $first['edits']]);
        $this->beat($a, $sa, $d->id, self::TAB_A, ['focus' => '1:qty']);
        self::assertSame($first, self::row($d->id, self::TAB_A), 'the same place, a moment later: no write');
        self::age($d->id, self::TAB_A, 9);
        $aged = self::row($d->id, self::TAB_A);
        $this->beat($a, $sa, $d->id, self::TAB_A, ['focus' => '1:qty']);
        self::assertSame($aged, self::row($d->id, self::TAB_A), '9 seconds old: no write');
        self::age($d->id, self::TAB_A, 11);
        $old = self::row($d->id, self::TAB_A);
        $this->beat($a, $sa, $d->id, self::TAB_A, ['focus' => '1:qty']);
        self::assertGreaterThan($old['seen_at'], self::row($d->id, self::TAB_A)['seen_at'], '11 seconds old: written again');
        foreach ([['focus' => '2:qty'], ['focus' => '2:price'], ['focus' => 'h:note'], ['focus' => ''], ['state' => 'away'], ['typing' => '1']] as $change) {
            $before = self::row($d->id, self::TAB_A);
            self::age($d->id, self::TAB_A, 1);
            $this->beat($a, $sa, $d->id, self::TAB_A, $change);
            $after = self::row($d->id, self::TAB_A);
            self::assertNotSame($before['seen_at'], $after['seen_at'], json_encode($change));
        }
        // Who may change the draft is written with the row: a job taken away is seen at the next poll.
        $this->beat($a, $sa, $d->id, self::TAB_A, ['typing' => '1'], false);
        self::assertSame(0, (int) self::row($d->id, self::TAB_A)['edits']);
    }

    public function testAClosedTabAndASignedOutSessionLeaveAtOnce(): void
    {
        $a = $this->staffUser('buyer');
        $b = $this->staffUser('buyer');
        [$sa, $sb] = [self::session(), self::session()];
        $d = $this->draftOrder($a);
        $e = $this->draftOrder($b);
        $this->beat($a, $sa, $d->id, self::TAB_A);
        $this->beat($a, $sa, $d->id, self::TAB_A2);
        $this->beat($a, $sa, $e->id, self::TAB_A);
        $this->beat($b, $sb, $d->id, self::TAB_B);

        // The page left: the beacon's bye removes that tab's row, and only that one.
        $bye = $this->beat($a, $sa, $d->id, self::TAB_A2, ['bye' => '1']);
        self::assertSame(['v' => $d->version, 'status' => 'draft', 'mode' => 'presence', 'bye' => true], $bye);
        self::assertNull(self::row($d->id, self::TAB_A2));
        self::assertNotNull(self::row($d->id, self::TAB_A));
        // Another session's bye with my tab (a tab is never guessed, but still): my row stays.
        $this->beat($b, $sb, $d->id, self::TAB_A, ['bye' => '1']);
        self::assertNotNull(self::row($d->id, self::TAB_A));

        // Signing out removes every row of the session (the sign-out does it: Ui\Controller\AuthController::logout), and no other.
        self::assertSame(2, Presence::forget(self::$db, $sa), 'A\'s tab on each of the two drafts');
        self::assertSame([[$d->id, self::TAB_B]], array_map(static fn (array $r): array => [(int) $r['record_id'], (string) $r['tab']],
            self::$db->all('SELECT record_id, tab FROM draft_presence ORDER BY record_id, tab')));
        self::assertSame(0, Presence::forget(self::$db, $sa), 'twice: nothing more');
        self::assertSame(0, Presence::forget(self::$db, 'not a session'));
    }

    public function testAnActivePersonIsGoneAfterTheGoneTimeAndAnAwayOneAfterTenMinutes(): void
    {
        $a = $this->staffUser('buyer');
        $b = $this->staffUser('buyer');
        [$sa, $sb] = [self::session(), self::session()];
        $d = $this->draftOrder($a);
        $this->beat($b, $sb, $d->id, self::TAB_B, ['focus' => '2:qty']);
        $here = fn (): array => array_map(static fn (array $p): array => [$p['state'], $p['uid'], $p['field']],
            $this->beat($a, $sa, $d->id, self::TAB_A)['here']);
        self::age($d->id, self::TAB_B, 24);
        self::assertSame([['active', 2, 'qty']], $here());
        self::age($d->id, self::TAB_B, 26);
        self::assertSame([], $here(), 'stopped polling: gone after 25 seconds');
        self::assertSame(10000, $this->beat($a, $sa, $d->id, self::TAB_A)['poll_ms'], 'alone again: the slow poll');
        $this->setting('live.gone_seconds', '40');
        self::assertSame([['active', 2, 'qty']], $here(), 'the setting decides');

        // Away (no key or mouse for a while, or the tab hidden): shown "(away)" without a place for ten minutes.
        $this->beat($b, $sb, $d->id, self::TAB_B, ['focus' => '2:qty', 'state' => 'away', 'typing' => '1']);
        self::age($d->id, self::TAB_B, 599);
        self::assertSame([['away', null, null]], $here());
        self::assertFalse($this->beat($a, $sa, $d->id, self::TAB_A)['here'][0]['typing'], 'an away person is not typing');
        self::assertSame(3000, $this->beat($a, $sa, $d->id, self::TAB_A)['poll_ms'], 'someone here, away or not: the quick poll keeps my row fresh for them');
        self::age($d->id, self::TAB_B, 601);
        self::assertSame([], $here());

        // A gone time shorter than a person's next poll could take is never used: alone for a minute, an active person stays 75 seconds.
        $this->setting('live.gone_seconds', '25');
        $this->setting('live.alone_seconds', '60');
        $this->beat($b, $sb, $d->id, self::TAB_B, ['focus' => '1:qty']);
        self::age($d->id, self::TAB_B, 70);
        self::assertSame([['active', 1, 'qty']], $here());
        self::age($d->id, self::TAB_B, 76);
        self::assertSame([], $here());
    }

    public function testAPersonSwitchedOffIsNeverShownAndATabIsNeverTakenOver(): void
    {
        $a = $this->staffUser('buyer');
        $b = $this->staffUser('buyer');
        [$sa, $sb] = [self::session(), self::session()];
        $d = $this->draftOrder($a);
        $this->beat($b, $sb, $d->id, self::TAB_B, ['focus' => '1:qty']);
        self::assertCount(1, $this->beat($a, $sa, $d->id, self::TAB_A)['here']);
        self::$db->exec('UPDATE staff_user SET is_active = 0 WHERE id = ?', [$b->staffUserId]);
        self::assertSame([], $this->beat($a, $sa, $d->id, self::TAB_A)['here'], 'switched off: gone at once');
        self::$db->exec('UPDATE staff_user SET is_active = 1 WHERE id = ?', [$b->staffUserId]);

        // B's poll with A's tab (another session): A's row is left as it is, B's answer is still B's.
        $this->beat($a, $sa, $d->id, self::TAB_A, ['focus' => '2:price']);
        $mine = self::row($d->id, self::TAB_A);
        self::age($d->id, self::TAB_A, 1);
        $mine = self::row($d->id, self::TAB_A);
        $theirs = $this->beat($b, $sb, $d->id, self::TAB_A, ['focus' => '1:qty']);
        self::assertSame($mine, self::row($d->id, self::TAB_A));
        self::assertSame([$a->staffUserId], array_column($theirs['here'], 'id'));
        // A's own session takes its tab on as before.
        self::age($d->id, self::TAB_A, 11);
        $this->beat($a, $sa, $d->id, self::TAB_A, ['focus' => '2:qty']);
        self::assertSame([2, 'qty'], [(int) self::row($d->id, self::TAB_A)['focus_uid'], self::row($d->id, self::TAB_A)['focus_field']]);
    }

    public function testADocumentThatIsNotADraftOrderSaysWhatItBecameAndWritesNothing(): void
    {
        $a = $this->staffUser('buyer');
        $b = $this->staffUser('buyer');
        $sa = self::session();
        $d = $this->draftOrder($a);
        $this->beat($a, $sa, $d->id, self::TAB_A);
        $p = $this->pos->approve($a, $d->id, $this->docs->get($d->id)->version);
        self::assertSame('posted', $p->status);
        $before = self::$db->all('SELECT * FROM draft_presence ORDER BY tab');
        $answer = $this->beat($a, $sa, $d->id, self::TAB_A2, ['focus' => '1:qty']);
        self::assertSame(['v' => $p->version, 'status' => 'posted', 'mode' => 'presence', 'number' => $p->number, 'by' => self::nameOf($a),
            'to' => "/ui/purchasing/orders/{$d->id}"], $answer);
        self::assertSame($before, self::$db->all('SELECT * FROM draft_presence ORDER BY tab'), 'nothing written');
        $this->beat($a, $sa, $d->id, self::TAB_A, ['bye' => '1']);
        self::assertSame([], self::$db->all('SELECT * FROM draft_presence'), 'a bye still removes the tab');

        $c = $this->draftOrder($a);
        $c = $this->pos->cancel($a, $c->id, $c->version, 'not_needed', null);
        self::assertSame(['v' => $c->version, 'status' => 'cancelled', 'mode' => 'presence', 'number' => null, 'by' => self::nameOf($a),
            'to' => "/ui/purchasing/orders/{$c->id}"], $this->beat($a, $sa, $c->id, self::TAB_A));

        // Not a purchase order (another kind of document), or no document at all: not found, nothing written, nothing said about it.
        $other = self::$db->insert("INSERT INTO document (doc_type, status, created_actor) VALUES ('ADJ', 'draft', 'staff:1')");
        foreach ([$other, $other + 1000] as $id) {
            try {
                $this->beat($a, $sa, $id, self::TAB_A);
                self::fail("document {$id} answered");
            } catch (CwException $e) {
                self::assertSame(['unknown_document', 404], [$e->errorCode, $e->httpStatus]);
            }
        }
        self::assertSame([], self::$db->all('SELECT * FROM draft_presence'));
    }

    public function testWithLiveModeOffNothingIsReadOrWritten(): void
    {
        $a = $this->staffUser('buyer');
        $sa = self::session();
        $d = $this->draftOrder($a);
        $this->setting('live.mode', '"off"');
        self::assertSame(['mode' => 'off'], $this->beat($a, $sa, $d->id, self::TAB_A, ['focus' => '1:qty']));
        self::assertSame(['mode' => 'off'], $this->beat($a, $sa, $d->id + 1000, self::TAB_A), 'not even whether the document exists');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM draft_presence'));
        $this->setting('live.mode', '"on"');
        self::assertSame('on', $this->beat($a, $sa, $d->id, self::TAB_A)['mode'], 'on: who is here as with presence (saving each box comes later)');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM draft_presence'));
    }

    public function testThePollsReadsGoByPrimaryKeysAndCarryATimeLimit(): void
    {
        $a = $this->staffUser('buyer');
        $b = $this->staffUser('buyer');
        $d = $this->draftOrder($a);
        $p = $this->draftOrder($a);
        $p = $this->pos->approve($a, $p->id, $p->version);
        $this->beat($a, self::session(), $d->id, self::TAB_A);
        $this->beat($b, self::session(), $d->id, self::TAB_B);
        foreach (['a draft' => [Presence::DOCUMENT_SQL, [$d->id]], 'a final order and its poster' => [Presence::DOCUMENT_SQL, [$p->id]],
            'rows' => [Presence::ROWS_SQL, ['po', $d->id]], 'settings' => [Presence::SETTINGS_SQL, array_keys(Presence::SETTINGS)]] as $what => [$sql, $params]) {
            self::assertStringStartsWith('SELECT /*+ MAX_EXECUTION_TIME(' . Presence::QUERY_MS . ')', $sql, $what);
            $steps = self::$db->all('EXPLAIN ' . $sql, $params);
            self::assertNotSame([], $steps);
            foreach ($steps as $step) {
                if ($step['table'] === null) {
                    continue;
                }
                if ($step['key'] === null && preg_match('/Impossible ON condition|no matching row in const table/', (string) $step['Extra']) === 1) {
                    continue; // a draft has no poster: nothing is read for the name
                }
                self::assertSame('PRIMARY', $step['key'], "{$what}: {$step['table']} by its primary key (" . json_encode($step) . ')');
                self::assertContains($step['type'], ['system', 'const', 'eq_ref', 'ref', 'range'], "{$what}: {$step['table']}");
            }
        }
        self::assertSame(500, Presence::QUERY_MS);
    }

    public function testHousekeepingRemovesRowsOlderThanADay(): void
    {
        $a = $this->staffUser('buyer');
        $sa = self::session();
        $d = $this->draftOrder($a);
        $this->beat($a, $sa, $d->id, self::TAB_A);
        $this->beat($a, $sa, $d->id, self::TAB_A2);
        self::$db->exec("UPDATE draft_presence SET seen_at = NOW(6) - INTERVAL 25 HOUR WHERE tab = ?", [self::TAB_A2]);
        $dry = (new Housekeeping(self::$db))->run(new Settings(self::$db), true);
        self::assertSame(1, $dry['draft_presence']['removed']);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM draft_presence'), 'a dry run removes nothing');
        $r = (new Housekeeping(self::$db))->run(new Settings(self::$db));
        self::assertSame([1, true], [$r['draft_presence']['removed'], $r['draft_presence']['done']]);
        self::assertSame([self::TAB_A], self::$db->column('SELECT tab FROM draft_presence'));
        self::assertGreaterThanOrEqual(Clock::db(Clock::now()->modify('-' . Presence::KEEP_HOURS . ' hours -1 second')), $r['draft_presence']['cutoff']);
        self::assertSame(24, Presence::KEEP_HOURS);
    }
}
