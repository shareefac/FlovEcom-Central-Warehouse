<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Documents\Documents;
use CW\Documents\DocumentHandlers;
use CW\Live\Presence;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\Suppliers;
use CW\Db;
use CW\Tests\Support\HookedStatement;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\TestDb;
use CW\Ui\Kernel;

/**
 * The poll of a draft order (POST /ui/purchasing/orders/{id}/live; the live drafts plan, step 3; docs/decisions.md LV3, LV4, LV10) through the
 * real kernel as the app login: it needs a sign-in (401 {"signed_out": true}, never a redirect: a page's goodbye beacon follows redirects, LV26;
 * nothing written), the session's token and the same origin
 * like every POST, and the right to look at orders (purchasing.view: nothing of a draft reaches anyone else); it answers JSON with the hard
 * headers and may be sent any number of times. It is passive: it never moves the session's last activity unless it says the person did
 * something (active=1), so a page left open is still signed out after the idle limit while it polls. Signing out removes the session's rows.
 */
final class LivePresenceScreensTest extends KernelUiTestCase
{
    private const TAB = 'abcdef0123456789';
    private const TAB2 = '0123456789abcdef';

    /** @var array<string, string> setting key => its JSON as it was */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(Presence::SETTINGS) as $key) {
            $this->saved[$key] = (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        }
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"presence\"' AS JSON) WHERE setting_key = 'live.mode'");
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        parent::tearDown();
    }

    /** A draft order of $buyerId (an active supplier approved by a reviewer, no lines), through the services. */
    private function draftOrder(int $buyerId): int
    {
        $sup = new Suppliers(self::$db);
        $buyer = Caller::staff($buyerId);
        $s = $sup->create($buyer, ['name' => 'Live Supplies ' . bin2hex(random_bytes(2)), 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA',
            'email' => 'sales@live.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyerId, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        return $pos->createDraft($buyer, (int) $s['id'], [])->id;
    }

    private static function sessionId(KernelBrowser $web): string
    {
        return hash('sha256', $web->cookies['cw_session'] ?? self::fail('the browser holds no session'));
    }

    private static function lastSeen(KernelBrowser $web): string
    {
        return (string) self::$db->value('SELECT last_seen_at FROM staff_session WHERE id = ?', [self::sessionId($web)]);
    }

    /** The session as if its person last did something $seconds ago. */
    private static function idle(KernelBrowser $web, int $seconds): void
    {
        self::$db->exec('UPDATE staff_session SET last_seen_at = NOW(6) - INTERVAL ? SECOND WHERE id = ?', [$seconds, self::sessionId($web)]);
    }

    /** @param array<string, string> $fields @param array<string, string> $headers */
    private static function poll(KernelBrowser $web, int $id, string $csrf, array $fields = [], array $headers = []): \CW\Tests\Support\UiResponse
    {
        return $web->post("/ui/purchasing/orders/{$id}/live", $fields + ['csrf' => $csrf, 'tab' => self::TAB, 'v' => '1', 'focus' => '', 'state' => 'active',
            'typing' => '0', 'active' => '0'], $headers);
    }

    /** @return array<string, mixed> */
    private static function json(\CW\Tests\Support\UiResponse $r): array
    {
        self::assertSame(200, $r->status, $r->describe());
        self::assertSame('application/json; charset=utf-8', $r->header('content-type'));
        $j = json_decode($r->body, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($j);
        return $j;
    }

    private static function rows(): int
    {
        return (int) self::$db->value('SELECT COUNT(*) FROM draft_presence');
    }

    public function testThePollIsGuardedLikeEveryPostAndAnswersJson(): void
    {
        $buyer = $this->uiUser('buyer');
        $id = $this->draftOrder($buyer['id']);
        $other = $this->uiUser('buyer');
        $theirs = $this->signIn($other);
        $theirToken = $this->token($theirs);

        // No session: 401 and {"signed_out": true}, never a redirect (a goodbye beacon follows one into a page that marks a session seen: the
        // Codex review of c62e742, a2; LV26); nothing written.
        $nobody = $this->browser();
        $r = self::poll($nobody, $id, 'x');
        self::assertSame([401, null, ['signed_out' => true]], [$r->status, $r->location(), json_decode($r->body, true)]);
        self::assertSame('application/json; charset=utf-8', $r->header('content-type'));
        self::assertSame(0, self::rows());
        // A person who must change their password first (another sign-in in this browser): the same, never the password page.
        $forced = $this->signIn($this->uiUser('buyer', true), null, '/ui/password');
        $before = (string) self::$db->value('SELECT last_seen_at FROM staff_session WHERE id = ?', [self::sessionId($forced)]);
        $r = self::poll($forced, $id, 'x');
        self::assertSame([401, null], [$r->status, $r->location()]);
        self::assertSame($before, (string) self::$db->value('SELECT last_seen_at FROM staff_session WHERE id = ?', [self::sessionId($forced)]), 'not seen');
        self::assertSame(0, self::rows());

        $web = $this->signIn($buyer);
        $csrf = $this->token($web);
        // The session's token and the same origin, as every POST.
        foreach (['no token' => '', 'made-up token' => 'x', "another person's token" => $theirToken] as $what => $token) {
            $r = self::poll($web, $id, $token);
            self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()], $what);
        }
        foreach (['Origin' => 'http://evil.example', 'Sec-Fetch-Site' => 'cross-site'] as $h => $v) {
            $r = self::poll($web, $id, $csrf, [], [$h => $v]);
            self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()], "{$h}: {$v}");
        }
        self::assertSame(0, self::rows(), 'nothing written by a refused poll');

        // Right: JSON with the hard headers, sent as often as the page likes (no form-once key: a poll changes nothing but its own row).
        $first = self::json(self::poll($web, $id, $csrf, ['focus' => 'h:note']));
        $r = self::poll($web, $id, $csrf, ['focus' => 'h:note']);
        self::assertSame($first, self::json($r));
        self::assertSame(['v', 'status', 'mode', 'poll_ms', 'here'], array_keys($first));
        self::assertSame(['draft', 'presence', []], [$first['status'], $first['mode'], $first['here']]);
        foreach (['content-security-policy', 'x-content-type-options', 'x-frame-options', 'cache-control', 'x-request-id'] as $h) {
            self::assertNotNull($r->header($h), $h);
        }
        self::assertSame(['nosniff', 'no-store'], [$r->header('x-content-type-options'), $r->header('cache-control')]);
        self::assertSame(1, self::rows());

        // Two people: each sees the other, never themselves.
        $theirs2 = self::json(self::poll($theirs, $id, $theirToken, ['tab' => self::TAB2, 'focus' => 'h:expected_date', 'typing' => '1']));
        self::assertSame([$buyer['id']], array_column($theirs2['here'], 'id'));
        $mine = self::json(self::poll($web, $id, $csrf));
        self::assertSame([[$other['id'], 'h:expected_date', true, true]], array_map(static fn (array $p): array => [$p['id'], $p['field'], $p['typing'], $p['edits']],
            $mine['here']));
        self::assertSame((string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$other['id']]), $mine['here'][0]['name']);
        // A name is data in JSON, never markup: <, > and & are escaped even inside the JSON text (opened as a page by mistake, it shows nothing).
        self::$db->exec('UPDATE staff_user SET display_name = ? WHERE id = ?', ['<b>Asha & "co"</b>', $other['id']]);
        $r = self::poll($web, $id, $csrf);
        self::assertSame('<b>Asha & "co"</b>', self::json($r)['here'][0]['name']);
        self::assertStringNotContainsString('<b>', $r->body);
        self::assertStringContainsString('\u003Cb\u003EAsha \u0026 \"co\"\u003C/b\u003E', $r->body);

        // A malformed tab is refused (400) and writes nothing; a GET is the wrong method.
        $r = self::poll($web, $id, $csrf, ['tab' => 'nope']);
        self::assertSame([400, 'bad_tab'], [$r->status, $r->errorCode()]);
        self::assertSame(405, $web->get("/ui/purchasing/orders/{$id}/live")->status);
        // Another kind of document, or none: not found.
        $adj = self::$db->insert("INSERT INTO document (doc_type, status, created_actor) VALUES ('ADJ', 'draft', 'staff:1')");
        foreach ([$adj, $id + 1000] as $none) {
            $r = self::poll($web, $none, $csrf);
            self::assertSame([404, 'unknown_document'], [$r->status, $r->errorCode()], (string) $none);
        }
        self::assertSame(2, self::rows());
    }

    public function testNothingOfADraftReachesSomeoneWhoMayNotLookAtOrders(): void
    {
        $buyer = $this->uiUser('buyer');
        $id = $this->draftOrder($buyer['id']);
        $web = $this->signIn($buyer);
        self::json(self::poll($web, $id, $this->token($web), ['focus' => 'h:note']));
        $buyerName = (string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$buyer['id']]);
        foreach (['mapper', 'warehouse', 'admin'] as $role) {
            $u = $this->uiUser($role);
            $who = $this->signIn($u);
            $r = self::poll($who, $id, $this->token($who), ['tab' => self::TAB2]);
            self::assertSame(403, $r->status, "{$role}: " . $r->describe());
            self::assertStringStartsWith('text/html', (string) $r->header('content-type'), $role);
            self::assertStringNotContainsString($buyerName, $r->body, "{$role}: who is on the draft is not said");
            self::assertStringNotContainsString('"here"', $r->body, $role);
            self::assertSame(1, self::rows(), "{$role}: nothing written");
        }
        // Looking is enough to be shown and to see: a reviewer (purchasing.view, not doc.PO.post) is "looking".
        $rv = $this->uiUser('reviewer');
        $look = $this->signIn($rv);
        $seen = self::json(self::poll($look, $id, $this->token($look), ['tab' => self::TAB2]));
        self::assertSame([[$buyer['id'], true]], array_map(static fn (array $p): array => [$p['id'], $p['edits']], $seen['here']));
        $back = self::json(self::poll($web, $id, $this->token($web)));
        self::assertSame([[$rv['id'], false]], array_map(static fn (array $p): array => [$p['id'], $p['edits']], $back['here']));
    }

    public function testThePollNeverKeepsASessionAliveByItself(): void
    {
        $buyer = $this->uiUser('buyer');
        $id = $this->draftOrder($buyer['id']);
        $web = $this->signIn($buyer);
        $csrf = $this->token($web);
        self::idle($web, 600);
        $before = self::lastSeen($web);
        self::json(self::poll($web, $id, $csrf));
        self::json(self::poll($web, $id, $csrf, ['active' => '0', 'state' => 'away']));
        self::json(self::poll($web, $id, $csrf, ['active' => 'yes']));
        self::assertSame($before, self::lastSeen($web), 'polls without active=1: the last activity stays');
        self::json(self::poll($web, $id, $csrf, ['active' => '1']));
        self::assertGreaterThan($before, self::lastSeen($web), 'active=1 (a key or the mouse was used): the session is seen');
        // Any other page still moves it, as before.
        self::idle($web, 600);
        $before = self::lastSeen($web);
        self::assertSame(200, $web->get("/ui/purchasing/orders/{$id}")->status);
        self::assertGreaterThan($before, self::lastSeen($web));

        // Past the idle limit (30 minutes by default) a polling page is signed out like any other: the next poll is told so (401).
        self::idle($web, 31 * 60);
        $token = (string) $web->cookies['cw_session'];
        $r = self::poll($web, $id, $csrf);
        self::assertSame([401, null], [$r->status, $r->location()]);
        self::assertStringContainsString('cw_session=;', (string) $r->setCookie('cw_session'), 'the dead cookie is cleared');
        self::assertSame(1, (int) self::$db->value('SELECT revoked FROM staff_session WHERE id = ?', [hash('sha256', $token)]), 'the session ended for good');
        $web->cookies['cw_session'] = $token;
        $r = self::poll($web, $id, $csrf, ['active' => '1']);
        self::assertSame([401, null], [$r->status, $r->location()], 'and active=1 cannot bring it back');
        // Just inside the limit, a poll with active=1 keeps it (the person is there, typing).
        $again = $this->signIn($buyer);
        $csrf = $this->token($again);
        self::idle($again, 29 * 60);
        self::json(self::poll($again, $id, $csrf, ['active' => '1']));
        self::idle($again, 29 * 60);
        $before = self::lastSeen($again);
        self::json(self::poll($again, $id, $csrf));
        self::assertSame($before, self::lastSeen($again));
        self::idle($again, 31 * 60);
        self::assertSame(401, self::poll($again, $id, $csrf)->status);
    }

    public function testSigningOutRemovesTheSessionsRows(): void
    {
        $buyer = $this->uiUser('buyer');
        $id = $this->draftOrder($buyer['id']);
        $other = $this->uiUser('buyer');
        $web = $this->signIn($buyer);
        $csrf = $this->token($web);
        $theirs = $this->signIn($other);
        self::json(self::poll($web, $id, $csrf));
        self::json(self::poll($web, $id, $csrf, ['tab' => self::TAB2]));
        self::json(self::poll($theirs, $id, $this->token($theirs), ['tab' => 'fedcba9876543210']));
        self::assertSame(3, self::rows());
        $r = $web->post('/ui/logout', ['csrf' => $csrf]);
        self::assertSame([303, '/ui/login'], [$r->status, $r->location()]);
        self::assertSame(['fedcba9876543210'], self::$db->column('SELECT tab FROM draft_presence'), 'the other person\'s row stays');
        $seen = self::json(self::poll($theirs, $id, $this->token($theirs), ['tab' => 'fedcba9876543210']));
        self::assertSame([], $seen['here'], 'gone at once');
    }

    /**
     * The Codex review of 50fa425 (HIGH 1): removing the presence rows never stands in the way of signing out. The session ends first; when the
     * rows cannot be removed (a deadlock here, or no table before 0070), the failure is logged, the person is signed out all the same, and
     * the rows left are not shown (their session ended); the nightly clean-up removes them.
     */
    public function testASignOutGoesOnWhenThePresenceRowsCannotBeRemoved(): void
    {
        $buyer = $this->uiUser('buyer');
        $id = $this->draftOrder($buyer['id']);
        $db = Db::connect(TestDb::config()->dbApp()->withDatabase(TestDb::name()));
        $db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        $logged = [];
        $key = self::$uiKey;
        $web = $this->signIn($buyer, new KernelBrowser(new Kernel(static fn (): Db => $db, static fn (): string => $key, static function (string $m) use (&$logged): void {
            $logged[] = $m;
        }, fn (Db $d): array => $this->handlers($d))));
        $csrf = $this->token($web);
        self::json(self::poll($web, $id, $csrf, ['focus' => 'h:note']));
        $token = (string) $web->cookies['cw_session'];
        $other = $this->signIn($this->uiUser('buyer'));
        self::assertSame([$buyer['id']], array_column(self::json(self::poll($other, $id, $this->token($other), ['tab' => self::TAB2]))['here'], 'id'));

        HookedStatement::$before['DELETE FROM draft_presence WHERE session_id'] = static function (): void {
            throw new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
        };
        try {
            $r = $web->post('/ui/logout', ['csrf' => $csrf]);
        } finally {
            HookedStatement::$before = [];
        }
        self::assertSame([303, '/ui/login'], [$r->status, $r->location()], $r->describe());
        self::assertStringContainsString('cw_session=;', (string) $r->setCookie('cw_session'));
        self::assertSame(1, (int) self::$db->value('SELECT revoked FROM staff_session WHERE id = ?', [hash('sha256', $token)]), 'signed out');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM draft_presence WHERE session_id = ?', [hash('sha256', $token)]), 'the row was left');
        self::assertCount(1, array_filter($logged, static fn (string $m): bool => str_contains($m, 'presence rows of the session were left')));
        self::assertSame([], self::json(self::poll($other, $id, $this->token($other), ['tab' => self::TAB2]))['here'], 'and nobody is shown for it');
    }

    public function testWithLiveModeOffThePollSaysOnlyThat(): void
    {
        $buyer = $this->uiUser('buyer');
        $id = $this->draftOrder($buyer['id']);
        $web = $this->signIn($buyer);
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"off\"' AS JSON) WHERE setting_key = 'live.mode'");
        self::assertSame(['mode' => 'off'], self::json(self::poll($web, $id, $this->token($web), ['focus' => 'h:note'])));
        self::assertSame(0, self::rows());
    }
}
