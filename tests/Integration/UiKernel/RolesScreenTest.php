<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Admin\ConfigHistory;
use CW\Auth\Permissions;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * Pack E (docs/decisions.md PE2-PE8): Settings › Users › Roles and permissions through the real /ui kernel as cw_app. Everyone looks;
 * an admin or a reviewer opens a task and changes which jobs can do it, with a reason; the service's refusals come back in words with
 * the ticks kept; the route itself is settings.manage, the form carries its version and the CSRF token, and the change works on the
 * next page.
 */
final class RolesScreenTest extends KernelUiTestCase
{
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

    /** @param list<string> $jobs @return array<string, string> the form as a browser would send it, with these jobs ticked */
    private static function ticked(array $form, array $jobs, string $reason = 'testing the roles screen'): array
    {
        foreach (Permissions::ROLES as $r) {
            unset($form['job_' . $r]);
        }
        foreach ($jobs as $j) {
            $form['job_' . $j] = '1';
        }
        return ['reason' => $reason] + $form;
    }

    public function testEveryoneLooksAndOnlyAdminsAndReviewersSeeTheChangeLinks(): void
    {
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get('/ui/reference/access');
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::ACCESS['rule_give'], $page->text());
        self::assertStringNotContainsString(Words::ACCESS['change_who'], $page->text(), 'a buyer only looks');
        $one = $web->get('/ui/reference/access', ['perm' => 'reorder.view']);
        self::assertSame(200, $one->status, $one->describe());
        self::assertStringContainsString(Words::ROLES_EDIT['look_only'], $one->text());
        self::assertFalse($one->hasForm('/ui/reference/access'), 'no form for a person who may not change it');
        self::assertSame(404, $web->get('/ui/reference/access', ['perm' => 'made.up'])->status);
        // Posting anyway: the route is settings.manage.
        $r = $web->post('/ui/reference/access', ['csrf' => $this->token($web), 'perm' => 'reorder.view', 'seen' => '1', 'job_buyer' => '1', 'reason' => 'let me']);
        self::assertSame(403, $r->status);
        self::assertSame($this->saved['reorder.view'], (string) self::$db->value("SELECT roles FROM role_permission WHERE permission = 'reorder.view'"));

        $rev = $this->signIn($this->uiUser('reviewer'));
        $list = $rev->get('/ui/reference/access');
        self::assertStringContainsString(Words::ACCESS['change_who'], $list->text());
        self::assertContains('/ui/reference/access?perm=reorder.view', $list->hrefs());
        self::assertNotContains('/ui/reference/access?perm=settings.manage', $list->hrefs(), 'a fixed task has no change link');
        self::assertStringContainsString(Words::ACCESS['fixed'], $list->text());
        $fixed = $rev->get('/ui/reference/access', ['perm' => 'settings.manage']);
        self::assertStringContainsString(Words::ACCESS['fixed_why'], $fixed->text());
        self::assertFalse($fixed->hasForm('/ui/reference/access'));
    }

    public function testAReviewerGivesAJobATaskAndItWorksOnTheNextPage(): void
    {
        $goods = $this->uiUser('goods_in');
        $gw = $this->signIn($goods);
        self::assertSame(403, $gw->get('/ui/purchasing/reorder')->status, 'goods in cannot open the reorder list');

        $rev = $this->signIn($this->uiUser('reviewer'));
        $page = $rev->get('/ui/reference/access', ['perm' => 'reorder.view']);
        $form = $page->form('/ui/reference/access');
        self::assertSame(['reorder.view', '1'], [$form['perm'], $form['seen']]);
        foreach (Permissions::holders('reorder.view') as $job) {
            self::assertSame('1', $form['job_' . $job] ?? null, "{$job} is ticked");
        }
        $r = $rev->post('/ui/reference/access', self::ticked($form, [...Permissions::MAP['reorder.view'], 'goods_in'], 'goods in checks what is coming'));
        self::assertSame(303, $r->status, $r->describe());
        $done = $rev->follow($r);
        self::assertStringContainsString(Words::ROLES_EDIT['saved'], $done->text());
        self::assertStringContainsString('goods in checks what is coming', $done->text(), 'the reason is in the history');
        self::assertSame(2, ConfigHistory::version(self::$db, 'permission', 'reorder.view'));
        self::assertSame(200, $gw->get('/ui/purchasing/reorder')->status, 'goods in opens it on the next page');

        // The same form again (its version is old now): refused in words, nothing saved, the ticks kept.
        $again = $rev->post('/ui/reference/access', self::ticked($form, ['buyer']));
        self::assertSame(409, $again->status);
        self::assertStringContainsString('Nothing was saved', $again->text());
    }

    public function testTheRefusalsAreInWordsAndNothingIsSaved(): void
    {
        $admin = $this->signIn($this->uiUser('admin'));
        $form = $admin->get('/ui/reference/access', ['perm' => 'reorder.view'])->form('/ui/reference/access');
        $r = $admin->post('/ui/reference/access', self::ticked($form, [...Permissions::MAP['reorder.view'], 'goods_in']));
        self::assertSame(403, $r->status);
        self::assertStringContainsString(Words::ROLES_ERROR['loosen_needs_reviewer'], $r->text());
        self::assertSame('1', $r->form('/ui/reference/access')['job_goods_in'] ?? null, 'what was ticked is kept');

        $rev = $this->signIn($this->uiUser(['reviewer', 'buyer']));
        $form = $rev->get('/ui/reference/access', ['perm' => 'incidents.view'])->form('/ui/reference/access');
        $r = $rev->post('/ui/reference/access', self::ticked($form, [...Permissions::MAP['incidents.view'], 'buyer']));
        self::assertSame(403, $r->status);
        self::assertStringContainsString(sprintf(Words::ROLES_ERROR['own_job'], Words::ROLE_PLURAL['buyer']), $r->text());

        // Admin and the look-only jobs cannot be ticked for a task that changes something: their boxes are switched off.
        $page = $rev->get('/ui/reference/access', ['perm' => 'doc.PO.post']);
        $xp = new \DOMXPath($page->dom());
        foreach (['admin', 'viewer', 'accountant', 'auditor'] as $job) {
            self::assertSame(1, $xp->query("//input[@name=\"job_{$job}\" and @disabled]")->length, $job);
        }
        $form = $page->form('/ui/reference/access');
        $r = $rev->post('/ui/reference/access', self::ticked($form, ['buyer', 'auditor']));
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::ROLES_ERROR['acting_job'], $r->text());
        // A reviewer's own task always stays ticked (sent as a hidden field), and taking it away is refused.
        $page = $rev->get('/ui/reference/access', ['perm' => 'documents.review']);
        self::assertSame('1', $page->form('/ui/reference/access')['job_reviewer'] ?? null);
        $r = $rev->post('/ui/reference/access', ['reason' => 'x x x'] + array_diff_key($page->form('/ui/reference/access'), ['job_reviewer' => true]) + ['job_buyer' => '1']);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(sprintf(Words::ROLES_ERROR['required_job'], Words::ROLE_PLURAL['reviewer']), $r->text());
        $r = $rev->post('/ui/reference/access', self::ticked($rev->get('/ui/reference/access', ['perm' => 'doc.WO.post'])->form('/ui/reference/access'), []));
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::ROLES_ERROR['no_holders'], $r->text());
        // No CSRF token: refused before anything.
        $r = $rev->post('/ui/reference/access', ['perm' => 'reorder.view', 'seen' => '1', 'job_buyer' => '1', 'reason' => 'no token']);
        self::assertSame(403, $r->status);
        foreach (['reorder.view', 'incidents.view', 'doc.PO.post', 'documents.review', 'doc.WO.post'] as $p) {
            self::assertSame($this->saved[$p], (string) self::$db->value('SELECT roles FROM role_permission WHERE permission = ?', [$p]), $p);
        }
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'permission.change'"));
    }

    /** Review finding 2 (PE17): without the table no page is served (503), never one from the code's defaults. */
    public function testWithoutTheTableNoPageIsServed(): void
    {
        $web = $this->signIn($this->uiUser('buyer'));
        self::$db->exec('RENAME TABLE role_permission TO role_permission_gone');
        try {
            $r = $web->get('/ui/purchasing/reorder');
            self::assertSame(503, $r->status, $r->describe());
            self::assertSame('unavailable', $r->errorCode());
        } finally {
            self::$db->exec('RENAME TABLE role_permission_gone TO role_permission');
        }
        self::assertSame(200, $web->get('/ui/purchasing/reorder')->status);
    }

    /** An admin takes a task away (tightening needs no reviewer); the job loses the page on its next request. */
    public function testAnAdminTakesATaskAwayAndThePageCloses(): void
    {
        $manager = $this->signIn($this->uiUser('manager'));
        self::assertSame(200, $manager->get('/ui/purchasing/reorder')->status);
        $admin = $this->signIn($this->uiUser('admin'));
        $form = $admin->get('/ui/reference/access', ['perm' => 'reorder.view'])->form('/ui/reference/access');
        $r = $admin->post('/ui/reference/access', self::ticked($form, array_values(array_diff(Permissions::MAP['reorder.view'], ['manager'])), 'stock managers do not buy'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(403, $manager->get('/ui/purchasing/reorder')->status);
    }
}
