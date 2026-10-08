<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\PeopleController;
use CW\Ui\Words;

/**
 * The Staff and access screen (I12, I13) through the real /ui kernel as cw_app: an admin gives and takes roles
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
        self::assertStringContainsString(Words::STAFF['add'], $list->text(), 'new people are made by the developer (F427), never a command on the screen');
        self::assertStringNotContainsString('bin/', $list->text());
        $lx = new \DOMXPath($list->dom());
        self::assertSame(Words::MENU['people'], trim((string) $lx->evaluate('string(//main//h1)')));
        self::assertSame(['Settings', 'Users'], [self::currentSection($list), self::currentTab($list)]);
        self::assertSame(1, $lx->query('//table[contains(@class, "people") and contains(@class, "stack")]')->length, 'one card per person on a phone (F432)');
        $row = trim((string) preg_replace('/\s+/', ' ', (string) $lx->evaluate('string(//tr[th/a/@href="/ui/people/' . $buyer['id'] . '"])')));
        self::assertStringStartsWith('Buyer 2 Yes Buyer ' . $buyer['email'] . ' never ', $row, 'Can sign in, jobs in words, "never" for no sign-in (F430, F431)');
        self::assertStringNotContainsString('UTC', $list->text());

        $page = $web->get('/ui/people/' . $buyer['id']);
        self::assertSame(200, $page->status, $page->describe());
        $form = $page->form('/ui/people/' . $buyer['id'] . '/roles');
        self::assertSame('1', $form['role_buyer'] ?? null, 'the live roles are ticked');
        self::assertSame('buyer', $form['roles_seen']);
        self::assertCount(14, self::checkboxes($page), 'one checkbox per role');
        foreach ([...array_values(Words::ROLE_GROUP), Words::ROLE_HELP['reviewer'], Words::ROLE['mapping_lead'], 'code: mapping_lead'] as $shown) {
            self::assertStringContainsString($shown, $page->text(), $shown);
        }
        self::assertStringNotContainsString('Linking', $page->text(), 'the group names in words (F443)');

        $r = $web->post('/ui/people/' . $buyer['id'] . '/roles', $form + ['role_reviewer' => '1']);
        self::assertSame(303, $r->status, self::statusOf($r));
        self::assertSame('/ui/people/' . $buyer['id'] . '?notice=roles_saved', $r->location());
        $after = $web->follow($r);
        self::assertStringContainsString(PeopleController::NOTICES['roles_saved'], $after->text());
        self::assertSame(['buyer', 'reviewer'], array_map('strval', self::$db->column(
            'SELECT role FROM staff_role WHERE staff_user_id = ? AND revoked_at IS NULL ORDER BY CAST(role AS CHAR)', [$buyer['id']])));
        self::assertSame($admin['id'], (int) self::$db->value("SELECT granted_by FROM staff_role WHERE staff_user_id = ? AND role = 'reviewer'", [$buyer['id']]));
        $audit = self::$db->one("SELECT actor, detail FROM audit_log WHERE action = 'staff.roles'");
        self::assertSame('staff:' . $admin['id'], $audit['actor']);
        self::assertEquals(['email' => $buyer['email'], 'before' => ['buyer'], 'after' => ['buyer', 'reviewer'], 'added' => ['reviewer'], 'removed' => []],
            json_decode((string) $audit['detail'], true));
        $history = self::squash((string) (new \DOMXPath($after->dom()))->evaluate('string(//section[@aria-labelledby="history-h"])'));
        self::assertStringContainsString('Reviewer', $history);
        self::assertStringContainsString('Admin 1', $history, 'given by the admin, by name');
        self::assertStringContainsString(Words::STAFF['server'], $history, 'the first job was set up on the server (F449)');

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
        self::assertStringContainsString(Words::say('STAFF', 'role_conflict', 'Reviewer', 'Reviewer'), $r->text(), 'the service\'s refusal in the page\'s words (F438)');
        $kept = $r->form('/ui/people/' . $owner['id'] . '/roles');
        self::assertSame(['1', '1'], [$kept['role_admin'] ?? null, $kept['role_reviewer'] ?? null], 'the choices are kept on the page');
        self::assertSame($roles, (int) self::$db->value('SELECT COUNT(*) FROM staff_role'));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.roles'"));

        $none = $web->post('/ui/people/' . $owner['id'] . '/roles', ['csrf' => $form['csrf'], 'roles_seen' => $form['roles_seen']]);
        self::assertSame(422, $none->status);
        self::assertStringContainsString(Words::STAFF['no_roles'], $none->text());
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
        self::assertStringContainsString(Words::STAFF['yours'], $own->text());
        $token = $this->token($web);
        $post = $web->post('/ui/people/' . $admin['id'] . '/roles', ['csrf' => $token, 'roles_seen' => 'admin', 'role_admin' => '1', 'role_viewer' => '1']);
        self::assertSame(403, $post->status);
        self::assertStringContainsString(Words::STAFF['own_account'], $post->text());
        self::assertSame(403, $web->post('/ui/people/' . $admin['id'] . '/active', ['csrf' => $token, 'active' => '0', 'confirm' => '1'])->status);
        self::assertSame(1, (int) self::$db->value('SELECT is_active FROM staff_user WHERE id = ?', [$admin['id']]));

        $auditor = $this->signIn($this->uiUser('auditor'));
        $list = $auditor->get('/ui/people');
        self::assertSame(200, $list->status);
        self::assertStringContainsString($other['email'], $list->text());
        $page = $auditor->get('/ui/people/' . $other['id']);
        self::assertSame(200, $page->status);
        self::assertFalse($page->hasForm('/roles'));
        self::assertFalse($page->hasForm('/active'));
        self::assertStringContainsString(Words::STAFF['auditor'], $page->text());
        self::assertStringContainsString(Words::STAFF['look_only'], $list->text(), 'the auditor is told they can only look');
        self::assertStringNotContainsString(Words::STAFF['add'], $list->text());
        $t = $this->token($auditor);
        foreach (['/roles' => ['roles_seen' => 'buyer', 'role_reviewer' => '1'], '/active' => ['active' => '0']] as $suffix => $fields) {
            $refused = $auditor->post('/ui/people/' . $other['id'] . $suffix, ['csrf' => $t] + $fields);
            self::assertSame(403, $refused->status, $suffix);
            self::assertSame('role_not_allowed', $refused->errorCode());
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
        self::assertSame(Words::STAFF['stop'], trim((string) (new \DOMXPath($page->dom()))->evaluate('string(//form[contains(@action, "/active")]//button)')), 'F441: one phrase');
        // Behaviour item 4 (F441): the button needs its tick-box first, in the browser and on the server.
        $xp = new \DOMXPath($page->dom());
        $tick = $xp->query('//form[contains(@action, "/active")]//input[@type="checkbox" and @name="confirm" and @required]');
        self::assertSame(1, $tick === false ? 0 : $tick->length, 'a required tick-box');
        self::assertSame(Words::STAFF['stop_confirm'], trim((string) $xp->evaluate('string(//form[contains(@action, "/active")]//label[input[@name="confirm"]])')));
        self::assertArrayNotHasKey('confirm', $form, 'not ticked when the page opens');
        $untouched = $admin->post('/ui/people/' . $u['id'] . '/active', $form);
        self::assertSame(422, $untouched->status, $untouched->describe());
        self::assertStringContainsString(Words::STAFF['stop_unconfirmed'], $untouched->text());
        self::assertSame(1, (int) self::$db->value('SELECT is_active FROM staff_user WHERE id = ?', [$u['id']]), 'nothing changed without the tick');
        self::assertSame(200, $victim->get('/ui/')->status, 'still signed in');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.deactivate'"));
        $r = $admin->post('/ui/people/' . $u['id'] . '/active', ['confirm' => '1'] + $form);
        self::assertSame('/ui/people/' . $u['id'] . '?notice=deactivated', $r->location());
        $out = $victim->get('/ui/');
        self::assertSame(303, $out->status);
        self::assertSame('/ui/login?why=signed_out', $out->location(), 'their next request goes to the sign-in, which says why');
        self::assertSame('/ui/login', $victim->get('/ui/')->location(), 'and the dead cookie is gone');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.deactivate' AND actor = ?", ['staff:' . $this->adminId()]));

        $back = $admin->follow($r);
        self::assertStringContainsString(PeopleController::NOTICES['deactivated'], $back->text());
        self::assertSame(Words::STAFF['allow'], trim((string) (new \DOMXPath($back->dom()))->evaluate('string(//form[contains(@action, "/active")]//button)')));
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
        self::assertStringContainsString(Words::say('STAFF', 'roles_changed', 'Buyer and Goods in'), $r->text(), 'F440: their jobs now, in words');
        $again = $r->form('/ui/people/' . $p['id'] . '/roles');
        self::assertSame('buyer,goods_in', $again['roles_seen'], 'the re-drawn form knows the current roles');
        self::assertSame('1', $again['role_reviewer'] ?? null, 'the choices are kept');
        self::assertArrayNotHasKey('role_goods_in', $again, 'and are the admin\'s, not the new ones');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM staff_role WHERE staff_user_id = ? AND role = 'reviewer'", [$p['id']]));

        $noToken = $web->post('/ui/people/' . $p['id'] . '/roles', ['roles_seen' => 'buyer,goods_in', 'role_buyer' => '1']);
        self::assertSame(403, $noToken->status);
        self::assertSame('csrf', $noToken->errorCode());
        $missing = $web->post('/ui/people/' . $p['id'] . '/roles', ['csrf' => $form['csrf'], 'role_buyer' => '1']);
        self::assertSame(400, $missing->status, 'a form without roles_seen');
        $gone = $web->get('/ui/people/999999');
        self::assertSame(404, $gone->status);
        self::assertStringContainsString(Words::ERROR['unknown_staff'], $gone->text());
        self::assertContains('/ui/people', $gone->hrefs(), 'F048: back to Staff and access');
        self::assertSame(404, $web->post('/ui/people/999999/roles', ['csrf' => $form['csrf'], 'roles_seen' => '', 'role_buyer' => '1'])->status);
    }

    public function testTheListWarnsAboutTooFewReviewersOrNoAdmin(): void
    {
        $admin = $this->signIn($this->uiUser('admin'));
        $this->uiUser('reviewer');
        $text = $admin->get('/ui/people')->text();
        self::assertStringContainsString(Words::say('STAFF', 'reviewers_one', 2), $text);
        self::assertStringNotContainsString('decision 3', $text);
        self::assertStringNotContainsString(Words::STAFF['no_admin'], $text);
        $second = $this->uiUser(['reviewer', 'accountant']);
        self::assertStringNotContainsString(Words::say('STAFF', 'reviewers_one', 2), $admin->get('/ui/people')->text());

        // A Reviewer who also has Admin cannot approve: the count is of the jobs that work (F429), and the clash says the one fix.
        $clash = $this->uiUser(['mapping_lead', 'reviewer']);
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'admin')", [$clash['id']]); // as on staging: no screen gives both
        self::$db->exec("UPDATE staff_role SET revoked_at = NOW(6) WHERE staff_user_id = ? AND role = 'reviewer'", [$second['id']]);
        $text = $admin->get('/ui/people')->text();
        self::assertStringContainsString(Words::say('STAFF', 'reviewers_one', 2) . ' ' . Words::STAFF['reviewers_admin'], $text);
        $name = (string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$clash['id']]);
        self::assertStringContainsString(Words::say('STAFF', 'clash', $name, 'Reviewer and Matching lead'), $text);
        self::assertDoesNotMatchRegularExpression('/second account|two accounts/i', $text, 'correction a');
        $page = $admin->get('/ui/people/' . $clash['id']);
        self::assertStringContainsString(Words::say('STAFF', 'clash_person', 'Reviewer and Matching lead'), $page->text(), 'F035: the warning on their page');
        self::assertStringContainsString('Admin · Matching lead (off) · Reviewer (off)', $page->text());

        // The auditor looking at a CW without an admin is told who to ask, never a server command.
        $auditor = $this->signIn($this->uiUser('auditor'));
        self::$db->exec("UPDATE staff_user SET is_active = 0 WHERE email LIKE 'k-admin-%' OR id = ?", [$clash['id']]);
        $warn = $auditor->get('/ui/people')->text();
        self::assertStringContainsString(Words::STAFF['no_admin'], $warn);
        self::assertStringNotContainsString('bin/', $warn);
    }

    public function testAReviewerWhoseRoleIsRemovedLosesTheReviewMenuOnTheNextRequest(): void
    {
        $admin = $this->signIn($this->uiUser('admin'));
        $u = $this->uiUser(['reviewer', 'stock_controller']);
        $web = $this->signIn($u);
        self::assertContains(Words::MENU['reviews'], self::tabLabels($web->get('/ui/documents')));

        $form = $admin->get('/ui/people/' . $u['id'])->form('/ui/people/' . $u['id'] . '/roles');
        unset($form['role_reviewer']);
        self::assertSame(303, $admin->post('/ui/people/' . $u['id'] . '/roles', $form)->status);
        self::assertSame([Words::MENU['documents']], self::tabLabels($web->get('/ui/documents')), 'the same session, the next request');
        self::assertSame(['Dashboard', 'Products', 'Stock', 'Purchasing', 'Approvals', 'Settings'], array_keys(self::nav($web->get('/ui/'))), 'a stock controller now');
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
        self::assertStringContainsString(Words::STAFF['test_off'], $page->text());
        $forged = $admin->post('/ui/people/' . $made['id'] . '/active', ['csrf' => $this->token($admin), 'active' => '1']);
        self::assertSame(409, $forged->status, $forged->describe());
        self::assertStringContainsString(Words::STAFF['placeholder'], $forged->text());
        $roles = $admin->post('/ui/people/' . $made['id'] . '/roles', ['csrf' => $this->token($admin), 'roles_seen' => 'mapping_lead',
            'role_mapping_lead' => '1', 'role_reviewer' => '1']);
        self::assertSame(409, $roles->status, $roles->describe());
        self::assertSame(0, (int) self::$db->value('SELECT is_active FROM staff_user WHERE id = ?', [$made['id']]));
        $warning = Words::say('STAFF', 'test_account', 'mapping-lead-placeholder@cw-staging.invalid');
        self::assertStringNotContainsString($warning, $admin->get('/ui/people')->text());
        self::$db->exec('UPDATE staff_user SET is_active = 1 WHERE id = ?', [$made['id']]);
        self::assertStringContainsString($warning, self::squash($admin->get('/ui/people')->text()));
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
