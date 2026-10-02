<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;

/**
 * The People and roles screen (I12, I13) through the real /ui kernel as cw_app: an admin gives and takes roles
 * with the checkbox form (history and audit kept), the separation-of-duties rule is explained on the page, nobody
 * edits their own row, the auditor only looks, switching an account off ends its sessions, a stale form is 409
 * and every POST needs the CSRF token.
 */
final class PeopleScreenTest extends KernelUiTestCase
{
    public function testAnAdminGivesABuyerTheReviewerRoleWithTheForm(): void
    {
        $admin = $this->uiUser('admin');
        $buyer = $this->uiUser('buyer');
        $web = $this->signIn($admin);

        $list = $web->get('/ui/people');
        self::assertSame(200, $list->status, $list->describe());
        self::assertContains('/ui/people/' . $buyer['id'], $list->hrefs());
        self::assertStringContainsString($buyer['email'], $list->text());
        self::assertStringContainsString('bin/create_staff.php', $list->text(), 'new people are made on the server');

        $page = $web->get('/ui/people/' . $buyer['id']);
        self::assertSame(200, $page->status, $page->describe());
        $form = $page->form('/ui/people/' . $buyer['id'] . '/roles');
        self::assertSame('1', $form['role_buyer'] ?? null, 'the live roles are ticked');
        self::assertSame('buyer', $form['roles_seen']);
        self::assertCount(14, self::checkboxes($page), 'one checkbox per role');
        foreach (['Linking', 'Purchasing and receiving', 'Stock', 'Review and finance', 'Admin', 'reviews documents posted by others'] as $shown) {
            self::assertStringContainsString($shown, $page->text());
        }

        $r = $web->post('/ui/people/' . $buyer['id'] . '/roles', $form + ['role_reviewer' => '1']);
        self::assertSame(303, $r->status, self::statusOf($r));
        self::assertSame('/ui/people/' . $buyer['id'] . '?notice=roles_saved', $r->location());
        $after = $web->follow($r);
        self::assertStringContainsString('Roles saved.', $after->text());
        self::assertSame(['buyer', 'reviewer'], array_map('strval', self::$db->column(
            'SELECT role FROM staff_role WHERE staff_user_id = ? AND revoked_at IS NULL ORDER BY CAST(role AS CHAR)', [$buyer['id']])));
        self::assertSame($admin['id'], (int) self::$db->value("SELECT granted_by FROM staff_role WHERE staff_user_id = ? AND role = 'reviewer'", [$buyer['id']]));
        $audit = self::$db->one("SELECT actor, detail FROM audit_log WHERE action = 'staff.roles'");
        self::assertSame('staff:' . $admin['id'], $audit['actor']);
        self::assertEquals(['email' => $buyer['email'], 'before' => ['buyer'], 'after' => ['buyer', 'reviewer'], 'added' => ['reviewer'], 'removed' => []],
            json_decode((string) $audit['detail'], true));
        $history = self::squash((string) (new \DOMXPath($after->dom()))->evaluate('string(//section[@aria-labelledby="history-h"])'));
        self::assertStringContainsString('reviewer', $history);
        self::assertStringContainsString('Admin 1', $history, 'given by the admin, by name');

        // Saving the same set again changes nothing and says so.
        $same = $web->post('/ui/people/' . $buyer['id'] . '/roles', $after->form('/ui/people/' . $buyer['id'] . '/roles'));
        self::assertSame('/ui/people/' . $buyer['id'] . '?notice=roles_unchanged', $same->location());
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.roles'"));
    }

    public function testAdminWithReviewerIsRefusedWithTheReasonAndNothingIsWritten(): void
    {
        $admin = $this->uiUser('admin');
        $owner = $this->uiUser('reviewer');
        $web = $this->signIn($admin);
        $form = $web->get('/ui/people/' . $owner['id'])->form('/ui/people/' . $owner['id'] . '/roles');
        $roles = (int) self::$db->value('SELECT COUNT(*) FROM staff_role');

        $r = $web->post('/ui/people/' . $owner['id'] . '/roles', $form + ['role_admin' => '1']);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString('admin cannot be combined with reviewer: the person who manages people and roles never posts, reviews or decides', $r->text());
        $kept = $r->form('/ui/people/' . $owner['id'] . '/roles');
        self::assertSame(['1', '1'], [$kept['role_admin'] ?? null, $kept['role_reviewer'] ?? null], 'the choices are kept on the page');
        self::assertSame($roles, (int) self::$db->value('SELECT COUNT(*) FROM staff_role'));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.roles'"));

        $none = $web->post('/ui/people/' . $owner['id'] . '/roles', ['csrf' => $form['csrf'], 'roles_seen' => $form['roles_seen']]);
        self::assertSame(422, $none->status);
        self::assertStringContainsString('deactivate the person instead', $none->text());
    }

    public function testNobodyEditsTheirOwnRowAndTheAuditorOnlyLooks(): void
    {
        $admin = $this->uiUser('admin');
        $other = $this->uiUser('buyer');
        $web = $this->signIn($admin);
        $own = $web->get('/ui/people/' . $admin['id']);
        self::assertSame(200, $own->status);
        self::assertFalse($own->hasForm('/roles'));
        self::assertFalse($own->hasForm('/active'));
        self::assertStringContainsString('This is your own account', $own->text());
        $token = $this->token($web);
        $post = $web->post('/ui/people/' . $admin['id'] . '/roles', ['csrf' => $token, 'roles_seen' => 'admin', 'role_admin' => '1', 'role_viewer' => '1']);
        self::assertSame(403, $post->status);
        self::assertStringContainsString('ask another admin', $post->text());
        self::assertSame(403, $web->post('/ui/people/' . $admin['id'] . '/active', ['csrf' => $token, 'active' => '0'])->status);
        self::assertSame(1, (int) self::$db->value('SELECT is_active FROM staff_user WHERE id = ?', [$admin['id']]));

        $auditor = $this->signIn($this->uiUser('auditor'));
        $list = $auditor->get('/ui/people');
        self::assertSame(200, $list->status);
        self::assertStringContainsString($other['email'], $list->text());
        $page = $auditor->get('/ui/people/' . $other['id']);
        self::assertSame(200, $page->status);
        self::assertFalse($page->hasForm('/roles'));
        self::assertFalse($page->hasForm('/active'));
        self::assertStringContainsString('Your role (auditor) can look at people and roles but not change them.', $page->text());
        $t = $this->token($auditor);
        foreach (['/roles' => ['roles_seen' => 'buyer', 'role_reviewer' => '1'], '/active' => ['active' => '0']] as $suffix => $fields) {
            $refused = $auditor->post('/ui/people/' . $other['id'] . $suffix, ['csrf' => $t] + $fields);
            self::assertSame(403, $refused->status, $suffix);
            self::assertStringContainsString('role_not_allowed', $refused->text());
        }
        self::assertSame(['buyer'], array_map('strval', self::$db->column('SELECT role FROM staff_role WHERE staff_user_id = ? AND revoked_at IS NULL', [$other['id']])));
    }

    public function testSwitchingAnAccountOffEndsItsSession(): void
    {
        $admin = $this->signIn($this->uiUser('admin'));
        $u = $this->uiUser('stock_controller');
        $victim = $this->signIn($u);
        self::assertSame(200, $victim->get('/ui/')->status);

        $page = $admin->get('/ui/people/' . $u['id']);
        $form = $page->form('/ui/people/' . $u['id'] . '/active');
        self::assertSame('0', $form['active']);
        $r = $admin->post('/ui/people/' . $u['id'] . '/active', $form);
        self::assertSame('/ui/people/' . $u['id'] . '?notice=deactivated', $r->location());
        self::assertSame(303, $victim->get('/ui/')->status);
        self::assertSame('/ui/login', $victim->get('/ui/')->location(), 'their next request goes to the sign-in');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.deactivate' AND actor = ?", ['staff:' . $this->adminId()]));

        $back = $admin->follow($r);
        self::assertStringContainsString('switched off', $back->text());
        $on = $admin->post('/ui/people/' . $u['id'] . '/active', $back->form('/ui/people/' . $u['id'] . '/active'));
        self::assertSame('/ui/people/' . $u['id'] . '?notice=activated', $on->location());
        self::assertSame(1, (int) self::$db->value('SELECT is_active FROM staff_user WHERE id = ?', [$u['id']]));
        self::assertSame(400, $admin->post('/ui/people/' . $u['id'] . '/active', ['csrf' => $this->token($admin), 'active' => 'yes'])->status);
    }

    public function testAStaleFormIs409AndAPostWithoutTheTokenIsRefused(): void
    {
        $admin = $this->uiUser('admin');
        $p = $this->uiUser('buyer');
        $web = $this->signIn($admin);
        $form = $web->get('/ui/people/' . $p['id'])->form('/ui/people/' . $p['id'] . '/roles');
        // Meanwhile someone else (here: the server tool) changes the roles.
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'goods_in')", [$p['id']]);

        $r = $web->post('/ui/people/' . $p['id'] . '/roles', $form + ['role_reviewer' => '1']);
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString('changed by someone else', $r->text());
        self::assertStringContainsString('Roles now: buyer, goods_in.', $r->text());
        $again = $r->form('/ui/people/' . $p['id'] . '/roles');
        self::assertSame('buyer,goods_in', $again['roles_seen'], 'the re-drawn form knows the current roles');
        self::assertSame('1', $again['role_reviewer'] ?? null, 'the choices are kept');
        self::assertArrayNotHasKey('role_goods_in', $again, 'and are the admin\'s, not the new ones');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM staff_role WHERE staff_user_id = ? AND role = 'reviewer'", [$p['id']]));

        $noToken = $web->post('/ui/people/' . $p['id'] . '/roles', ['roles_seen' => 'buyer,goods_in', 'role_buyer' => '1']);
        self::assertSame(403, $noToken->status);
        self::assertStringContainsString('csrf', $noToken->text());
        $missing = $web->post('/ui/people/' . $p['id'] . '/roles', ['csrf' => $form['csrf'], 'role_buyer' => '1']);
        self::assertSame(400, $missing->status, 'a form without roles_seen');
        self::assertSame(404, $web->get('/ui/people/999999')->status);
        self::assertSame(404, $web->post('/ui/people/999999/roles', ['csrf' => $form['csrf'], 'roles_seen' => '', 'role_buyer' => '1'])->status);
    }

    public function testTheListWarnsAboutTooFewReviewersOrNoAdmin(): void
    {
        $admin = $this->signIn($this->uiUser('admin'));
        $this->uiUser('reviewer');
        $text = $admin->get('/ui/people')->text();
        self::assertStringContainsString('Only 1 active person holds the reviewer role: at least 2 are needed (decision 3)', $text);
        self::assertStringNotContainsString('No active person holds the admin role', $text);
        $this->uiUser(['reviewer', 'accountant']);
        self::assertStringNotContainsString('reviewer role', $admin->get('/ui/people')->text());

        // The auditor looking at a CW without an admin is told how to get one back.
        $auditor = $this->signIn($this->uiUser('auditor'));
        self::$db->exec("UPDATE staff_user SET is_active = 0 WHERE email LIKE 'k-admin-%'");
        $warn = $auditor->get('/ui/people')->text();
        self::assertStringContainsString('No active person holds the admin role', $warn);
        self::assertStringContainsString('bin/reset_staff.php', $warn);
    }

    public function testAReviewerWhoseRoleIsRemovedLosesTheReviewMenuOnTheNextRequest(): void
    {
        $admin = $this->signIn($this->uiUser('admin'));
        $u = $this->uiUser(['reviewer', 'stock_controller']);
        $web = $this->signIn($u);
        self::assertArrayHasKey('Document reviews', self::nav($web->get('/ui/')));

        $form = $admin->get('/ui/people/' . $u['id'])->form('/ui/people/' . $u['id'] . '/roles');
        unset($form['role_reviewer']);
        self::assertSame(303, $admin->post('/ui/people/' . $u['id'] . '/roles', $form)->status);
        $nav = self::nav($web->get('/ui/'));
        self::assertArrayNotHasKey('Document reviews', $nav, 'the same session, the next request');
        self::assertArrayHasKey('Stock control', $nav);
        $revoked = self::$db->one("SELECT revoked_at, revoked_by FROM staff_role WHERE staff_user_id = ? AND role = 'reviewer'", [$u['id']]);
        self::assertNotNull($revoked['revoked_at']);
        self::assertSame($this->adminId(), (int) $revoked['revoked_by']);
        self::assertSame(403, $web->get('/ui/documents/reviews')->status, 'the queue itself is refused, not only hidden');
    }

    /**
     * I35 (review finding): a placeholder account (e-mail under .invalid, U23) is never switched back on or given a role
     * from the browser: no switch-on form, a forged POST is a 409 page with the reason and changes nothing, and the list
     * warns while one is active (e.g. switched on by the server tool).
     */
    public function testAPlaceholderAccountIsNeverSwitchedOnFromTheScreen(): void
    {
        $admin = $this->signIn($this->uiUser('admin'));
        $made = (new \CW\Staff\StaffAdmin(self::$db))->create(\CW\Caller::system('people_test'), 'mapping-lead-placeholder@cw-staging.invalid',
            'mapping_lead', self::$box, 'Placeholder');
        self::$db->exec('UPDATE staff_user SET is_active = 0 WHERE id = ?', [$made['id']]);
        $page = $admin->get('/ui/people/' . $made['id']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertFalse($page->hasForm('/ui/people/' . $made['id'] . '/active'), 'no switch-on form');
        self::assertStringContainsString('placeholder account', $page->text());
        $forged = $admin->post('/ui/people/' . $made['id'] . '/active', ['csrf' => $this->token($admin), 'active' => '1']);
        self::assertSame(409, $forged->status, $forged->describe());
        self::assertStringContainsString('two-person rule', $forged->text());
        $roles = $admin->post('/ui/people/' . $made['id'] . '/roles', ['csrf' => $this->token($admin), 'roles_seen' => 'mapping_lead',
            'role_mapping_lead' => '1', 'role_reviewer' => '1']);
        self::assertSame(409, $roles->status, $roles->describe());
        self::assertSame(0, (int) self::$db->value('SELECT is_active FROM staff_user WHERE id = ?', [$made['id']]));
        self::assertStringNotContainsString('placeholder account (an e-mail under .invalid) and it is switched on', $admin->get('/ui/people')->text());
        self::$db->exec('UPDATE staff_user SET is_active = 1 WHERE id = ?', [$made['id']]);
        self::assertStringContainsString('mapping-lead-placeholder@cw-staging.invalid is a placeholder account (an e-mail under .invalid) and it is switched on',
            self::squash($admin->get('/ui/people')->text()));
    }

    /** @return list<string> the names of the role checkboxes of a person page */
    private static function checkboxes(UiResponse $page): array
    {
        $out = [];
        foreach ((new \DOMXPath($page->dom()))->query('//form[contains(@action, "/roles")]//input[@type="checkbox"]') ?: [] as $in) {
            /** @var \DOMElement $in */
            $out[] = $in->getAttribute('name');
        }
        return $out;
    }

    private function adminId(): int
    {
        return (int) self::$db->value("SELECT MIN(id) FROM staff_user WHERE email LIKE 'k-admin-%'");
    }

    private static function squash(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}
