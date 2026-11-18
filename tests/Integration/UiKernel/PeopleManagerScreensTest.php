<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Auth\Permissions;
use CW\Staff\StaffRoles;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\PeopleController;
use CW\Ui\Words;

/**
 * The People manager on the screens (docs/decisions.md PM1-PM7) through the real /ui kernel as cw_app: the owner, with his working jobs and
 * People manager, opens Settings › People, adds a person with the sign-up sheet, gives and takes jobs and switches people off, while his
 * own work pages stay open; People manager alone opens the people screens and what every job reads, nothing else; the Roles and permissions
 * page never gives it another task; the People list warns when nobody can manage people.
 */
final class PeopleManagerScreensTest extends KernelUiTestCase
{
    private const OWNER = ['accountant', 'mapping_lead', 'people_manager', 'purchasing_manager', 'reviewer', 'stock_controller'];

    /** @var array<string, string> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = [];
        foreach (self::$db->all('SELECT permission, roles FROM role_permission') as $r) {
            $this->saved[(string) $r['permission']] = (string) $r['roles'];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $p => $roles) {
            self::$db->exec('UPDATE role_permission SET roles = ? WHERE permission = ?', [$roles, $p]);
        }
        Permissions::useStored(null);
        parent::tearDown();
    }

    public function testTheOwnerManagesPeopleOnTheScreensAndKeepsHisWork(): void
    {
        $owner = $this->uiUser(self::OWNER);
        $buyer = $this->uiUser('buyer');
        $web = $this->signIn($owner);

        $list = $web->get('/ui/people');
        self::assertSame(200, $list->status, $list->describe());
        self::assertContains('/ui/people', $web->get('/ui/')->hrefs(), 'People is in his menu');
        self::assertTrue($list->hasForm('/ui/people'), 'the add form');
        self::assertStringNotContainsString(Words::STAFF['look_only'], $list->text());
        self::assertStringNotContainsString(Words::STAFF['no_admin'], $list->text(), 'he can manage people: no warning');

        // His own page: no forms, and why.
        $own = $web->get('/ui/people/' . $owner['id']);
        self::assertFalse($own->hasForm('/roles'));
        self::assertStringContainsString(Words::STAFF['yours'], $own->text());

        // A person's jobs: one box per job, People manager among them, next to Admin.
        $page = $web->get('/ui/people/' . $buyer['id']);
        self::assertSame(200, $page->status, $page->describe());
        $form = $page->form('/ui/people/' . $buyer['id'] . '/roles');
        self::assertCount(count(Permissions::ROLES), self::checkboxes($page));
        foreach ([Words::ROLE['people_manager'], Words::ROLE_HELP['people_manager'], 'code: people_manager', Words::ROLE_GROUP['Admin']] as $shown) {
            self::assertStringContainsString($shown, $page->text(), $shown);
        }
        $r = $web->post('/ui/people/' . $buyer['id'] . '/roles', $form + ['role_people_manager' => '1', 'role_reviewer' => '1']);
        self::assertSame('/ui/people/' . $buyer['id'] . '?notice=roles_saved', $r->location(), $r->describe());
        self::assertSame(['buyer', 'people_manager', 'reviewer'], StaffRoles::of(self::$db, $buyer['id']));
        self::assertSame($owner['id'], (int) self::$db->value("SELECT granted_by FROM staff_role WHERE staff_user_id = ? AND role = 'people_manager'", [$buyer['id']]));
        // Admin with a working job: refused in words, as for an Admin.
        $form = $web->get('/ui/people/' . $buyer['id'])->form('/ui/people/' . $buyer['id'] . '/roles');
        $bad = $web->post('/ui/people/' . $buyer['id'] . '/roles', $form + ['role_admin' => '1']);
        self::assertSame(422, $bad->status);
        self::assertSame(['buyer', 'people_manager', 'reviewer'], StaffRoles::of(self::$db, $buyer['id']));

        // Adds a person: the sign-up sheet, once.
        $csrf = $this->token($web);
        $sheet = $web->post('/ui/people', ['csrf' => $csrf, 'name' => 'Sam Stock', 'email' => 'sam-stock@test.example', 'role_stock_controller' => '1']);
        self::assertSame(200, $sheet->status, $sheet->describe());
        self::assertSame('no-store', $sheet->header('cache-control'));
        $sam = (int) self::$db->value("SELECT id FROM staff_user WHERE email = 'sam-stock@test.example'");
        self::assertSame(['stock_controller'], StaffRoles::of(self::$db, $sam));
        // Switches a person off (with the tick), and on again.
        $off = $web->post('/ui/people/' . $buyer['id'] . '/active', ['csrf' => $csrf, 'active' => '0', 'confirm' => '1']);
        self::assertSame('/ui/people/' . $buyer['id'] . '?notice=deactivated', $off->location(), $off->describe());
        self::assertSame(0, (int) self::$db->value('SELECT is_active FROM staff_user WHERE id = ?', [$buyer['id']]));
        self::assertSame('/ui/people/' . $buyer['id'] . '?notice=activated', $web->post('/ui/people/' . $buyer['id'] . '/active', ['csrf' => $csrf, 'active' => '1'])->location());
        // Every POST needs the token.
        $noToken = $web->post('/ui/people/' . $buyer['id'] . '/active', ['active' => '0', 'confirm' => '1']);
        self::assertSame([403, 'csrf'], [$noToken->status, $noToken->errorCode()]);
        self::assertSame(1, (int) self::$db->value('SELECT is_active FROM staff_user WHERE id = ?', [$buyer['id']]));

        // His work pages are open as before.
        foreach (['/ui/documents/reviews', '/ui/staff-requests', '/ui/reference/access', '/ui/purchasing/reorder'] as $path) {
            self::assertSame(200, $web->get($path)->status, $path);
        }
    }

    public function testPeopleManagerAloneOpensThePeopleScreensAndNothingElse(): void
    {
        $pm = $this->uiUser('people_manager');
        $other = $this->uiUser('buyer');
        $web = $this->signIn($pm);
        foreach (['/ui/', '/ui/people', '/ui/people/' . $other['id'], '/ui/people.csv', '/ui/reference/access', '/ui/reference/settings'] as $path) {
            self::assertSame(200, $web->get($path)->status, $path);
        }
        self::assertTrue($web->get('/ui/people/' . $other['id'])->hasForm('/ui/people/' . $other['id'] . '/roles'));
        foreach (['/ui/staff-requests', '/ui/system/audit', '/ui/documents', '/ui/documents/reviews', '/ui/purchasing/reorder', '/ui/review'] as $path) {
            self::assertSame(403, $web->get($path)->status, $path);
        }
        // Settings stay a Reviewer's and an Admin's (settings.manage): no change form on Roles and permissions.
        self::assertFalse($web->get('/ui/reference/access', ['perm' => 'reorder.view'])->hasForm('/ui/reference/access'));
    }

    public function testTheRolesPageNeverGivesThePeopleManagerAnotherTask(): void
    {
        $rev = $this->signIn($this->uiUser('reviewer'));
        $page = $rev->get('/ui/reference/access', ['perm' => 'reorder.view']);
        $xp = new \DOMXPath($page->dom());
        self::assertSame(1, $xp->query('//input[@name="job_people_manager" and @type="checkbox" and @disabled]')->length);
        self::assertStringContainsString(Words::ROLES_EDIT['people_manager_never'], $page->text());
        $form = $page->form('/ui/reference/access');
        foreach (Permissions::ROLES as $r) {
            unset($form['job_' . $r]);
        }
        foreach ([...Permissions::MAP['reorder.view'], 'people_manager'] as $j) {
            $form['job_' . $j] = '1';
        }
        $r = $rev->post('/ui/reference/access', ['reason' => 'people managers buy now'] + $form);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::ROLES_ERROR['people_manager_job'], $r->text());
        self::assertSame($this->saved['reorder.view'], (string) self::$db->value("SELECT roles FROM role_permission WHERE permission = 'reorder.view'"));

        // The people list: People manager always keeps it (ticked, switched off), even on production's row of 0034.
        self::assertSame('admin,auditor', $this->saved['staff.view'], "0034's row, left as it was");
        $view = $rev->get('/ui/reference/access', ['perm' => 'staff.view']);
        self::assertSame('1', $view->form('/ui/reference/access')['job_people_manager'] ?? null);
        self::assertStringContainsString(Words::ROLES_EDIT['always'], $view->text());
        // By job: the People manager's line names what it can do.
        $all = $rev->get('/ui/reference/access');
        self::assertStringContainsString(Words::ROLE_HELP['people_manager'], $all->text());
    }

    public function testTheListWarnsWhenNobodyCanManagePeople(): void
    {
        $auditor = $this->signIn($this->uiUser('auditor'));
        self::assertStringContainsString(Words::STAFF['no_admin'], $auditor->get('/ui/people')->text());
        $pm = $this->uiUser(['people_manager', 'reviewer']);
        self::assertStringNotContainsString(Words::STAFF['no_admin'], $auditor->get('/ui/people')->text(), 'a People manager can');
        self::$db->exec('UPDATE staff_user SET is_active = 0 WHERE id = ?', [$pm['id']]);
        self::assertStringContainsString(Words::STAFF['no_admin'], $auditor->get('/ui/people')->text(), 'a switched-off one cannot');
        self::assertStringNotContainsString('bin/', $auditor->get('/ui/people')->text());
        self::assertSame(PeopleController::NOTICES, Words::STAFF_NOTICE);
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
}
