<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Staff\StaffAdmin;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * Role-aware menus and permission-guarded routes (I11, I14), through the real /ui kernel as cw_app. Mirrors the
 * owner's acceptance test of Phase I-1: "sign in as buyer, desk and reviewer and see different menus"; none of them
 * sees Match products or Staff, and a page left out of a menu is also refused (403), not only hidden.
 *
 * The menu is task-based since the plain-words redesign (plan §2, 7 Oct 2026): Home first for everyone, then To check,
 * Match products, Buying, Deliveries (IM6), Products, Records, Staff, Settings; screens not built yet are named on Home ("Coming later"),
 * never in the menu; a badge counts only what the person can act on.
 */
final class MenusTest extends KernelUiTestCase
{
    private const HOME = [['label' => 'Home', 'href' => '/ui/']];
    private const SETTINGS = [['label' => 'Company details', 'href' => '/ui/reference/company'], ['label' => 'Settings and lists', 'href' => '/ui/reference/settings'],
        ['label' => 'Approval rules', 'href' => '/ui/reference/approvals'], ['label' => 'Warehouses', 'href' => '/ui/reference/warehouses']];
    /** The read-only system pages of 0019 (Y30-Y33): system.view and audit.view (a reviewer, an admin, an auditor; a manager the first two). */
    private const SYSTEM = [['label' => 'Websites', 'href' => '/ui/system/sites'], ['label' => 'Safety checks', 'href' => '/ui/system/checks'],
        ['label' => 'Audit log', 'href' => '/ui/system/audit']];

    public function testBuyerDeskAndReviewerSeeDifferentMenus(): void
    {
        $buyer = $this->signIn($this->uiUser('buyer'));
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $reviewer = $this->signIn($this->uiUser('reviewer'));

        $deskHome = $desk->get('/ui/');
        $b = self::nav($buyer->get('/ui/'));
        $d = self::nav($deskHome);
        $r = self::nav($reviewer->get('/ui/'));
        self::assertSame(['Home', 'Buying', 'Products', 'Records', 'Settings'], array_keys($b));
        self::assertSame(['Home', 'Buying', 'Deliveries', 'Products', 'Records', 'Settings'], array_keys($d));
        self::assertSame(['Home', 'To check', 'Buying', 'Deliveries', 'Products', 'Records', 'Settings'], array_keys($r));
        foreach (['b' => $b, 'd' => $d, 'r' => $r] as $who => $nav) {
            self::assertSame(self::HOME, $nav['Home'], 'Home first, for everyone');
            self::assertArrayNotHasKey('Match products', $nav);
            self::assertArrayNotHasKey('Staff', $nav);
            self::assertSame([['label' => 'Product list', 'href' => '/ui/items/cards']], $nav['Products'],
                'the product list for everyone; the barcodes to check only for catalogue.edit (I109)');
            self::assertSame([['label' => 'All records', 'href' => '/ui/documents']], $nav['Records']);
            self::assertSame($who === 'r' ? [...self::SETTINGS, ...self::SYSTEM] : self::SETTINGS, $nav['Settings'],
                'every role reads the company details (I90), the approval rules and the warehouses (Y9, Y14); the reviewer also the system pages');
        }
        self::assertSame([
            ['label' => 'What to buy', 'href' => '/ui/purchasing/reorder'],
            ['label' => 'Purchase orders', 'href' => '/ui/purchasing/orders'],
            ['label' => 'Suppliers', 'href' => '/ui/purchasing/suppliers'],
            ['label' => 'Sales data', 'href' => '/ui/purchasing/sales-history'],
        ], $b['Buying'], 'every Buying item is live (the I-2 suppliers, pos and reorder tasks)');
        self::assertSame([
            ['label' => 'Purchase orders', 'href' => '/ui/purchasing/orders'],
            ['label' => 'Suppliers', 'href' => '/ui/purchasing/suppliers'],
        ], $d['Buying'], 'the desk sees suppliers and purchase orders, not what to buy');
        self::assertSame($b['Buying'], $r['Buying'], 'the reviewer reads the buying screens');
        self::assertSame([['label' => 'Waiting for me', 'href' => '/ui/documents/reviews']], $r['To check']);
        // Live since IM6 (I141): the receipts, the goods-in bench and the incident register, after Buying.
        self::assertSame([
            ['label' => 'Receive + invoice', 'href' => '/ui/receiving'],
            ['label' => 'Goods-in bench', 'href' => '/ui/receiving/bench'],
            ['label' => 'Incidents', 'href' => '/ui/receiving/incidents'],
        ], $d['Deliveries'], 'live since IM6 (I141); supplier invoices and returns are not built yet, so not in the menu');
        self::assertSame([['label' => 'Receive + invoice', 'href' => '/ui/receiving'], ['label' => 'Incidents', 'href' => '/ui/receiving/incidents']],
            $r['Deliveries'], 'the reviewer reads receipts and incidents; the bench is for the people who receive');
        // The desk's screens of Phase I-4 to I-6 are not in the menu: Home names them, without phase codes.
        self::assertStringContainsString(Words::UI['coming_later'] . ' supplier invoices, returns to suppliers, trade sales.', $deskHome->text());
        self::assertStringNotContainsString('receiving deliveries', $deskHome->text(), 'receiving is built (IM6)');
        self::assertStringNotContainsString('Phase', $deskHome->text());
        self::assertStringNotContainsString('coming_later', $deskHome->text());
        self::assertNotSame($b, $d);
        self::assertNotSame($d, $r);
        self::assertNotSame($b, $r);

        self::assertSame(['To do', 'Orders', 'To buy', 'Suppliers', 'More'], array_column(self::tabs($buyer->get('/ui/')), 'label'), 'the phone tab bar of a buyer');
        self::assertSame(['/ui/', '/ui/purchasing/orders', '/ui/purchasing/reorder', '/ui/purchasing/suppliers', '#menu'], array_column(self::tabs($buyer->get('/ui/')), 'href'));
        self::assertSame(['To do', 'Bench', 'Orders', 'Suppliers', 'More'], array_column(self::tabs($deskHome), 'label'),
            'the delivery check is done on the tablet: the bench first (IM6)');
    }

    public function testPagesOutsideTheMenuAreRefusedNotOnlyHidden(): void
    {
        $site = $this->site('vpg');
        $l = $this->queued($site, 'M1', 'Key', $this->item('legacy', 0, 'Menu item'), ['product_title' => 'Menu item']);
        $u = $this->uiUser('buyer');
        $buyer = $this->signIn($u);
        foreach (['/ui/review' => [['queue' => 'Key'], 'linking.view'], '/ui/review/listing/' . $l => [[], 'linking.view'], '/ui/people' => [[], 'staff.view'],
            '/ui/people/' . $u['id'] => [[], 'staff.view']] as $path => [$query, $perm]) {
            $page = $buyer->get($path, $query);
            self::assertSame(403, $page->status, "{$path}: " . $page->describe());
            self::assertSame('role_not_allowed', $page->errorCode());
            self::assertStringContainsString(Words::ERROR_TITLE['403'], $page->text());
            self::assertStringContainsString('This page is for ' . Words::whoCan($perm) . '. You work as: Buyer. If you need it for your work, ask '
                . Words::ASK_ROLE . '.', $page->text());
            self::assertStringNotContainsString('role_not_allowed', $page->text(), 'the code is not printed (plan F041)');
            self::assertSame(['Home', 'Buying', 'Products', 'Records', 'Settings'], array_keys(self::nav($page)), 'the error page keeps the menu');
        }
        self::assertSame('This page is for Admins and Auditors. You work as: Buyer. If you need it for your work, ask Fazil (the admin).',
            \CW\Ui\Kernel::refusal('staff.view', new \CW\Auth\StaffIdentity($u['id'], $u['email'], 'Buyer', ['buyer'], false, 'x')), 'the words, once in full');
        self::assertSame(200, $buyer->get('/ui/search', ['q' => 'Menu'])->status);
        $decide = $buyer->post("/ui/review/listing/{$l}/decide", ['csrf' => $this->token($buyer), 'action' => 'ignore', 'reason' => 'x']);
        self::assertSame(403, $decide->status);
        self::assertSame('role_not_allowed', $decide->errorCode());
        self::assertStringContainsString(Words::ERROR['decide_required'], $decide->text());
        $approve = $buyer->post('/ui/review/decision/1/approve', ['csrf' => $this->token($buyer)]);
        self::assertSame(403, $approve->status);
        self::assertSame('lead_required', $approve->errorCode());
        self::assertStringContainsString(Words::ERROR['lead_only'], $approve->text());

        // Several roles: the union; the message names them all, as jobs.
        $both = $this->signIn($this->uiUser(['buyer', 'stock_controller']));
        $page = $both->get('/ui/review', ['queue' => 'Key']);
        self::assertSame(403, $page->status);
        self::assertStringContainsString('You work as: Buyer · Stock controller.', $page->text());
        self::assertSame(['Home', 'Buying', 'Deliveries', 'Products', 'Records', 'Settings'], array_keys(self::nav($both->get('/ui/'))),
            'a stock controller who also buys keeps the fixed order; the stock controller reads the receipts and the incident register (IM6, I141)');
        self::assertSame([['label' => 'Product list', 'href' => '/ui/items/cards'], ['label' => 'Barcodes to check', 'href' => '/ui/items/barcodes']],
            self::nav($both->get('/ui/'))['Products']);

        // A stock controller alone: Products is their work and comes second.
        $stock = $this->signIn($this->uiUser('stock_controller'));
        $home = $stock->get('/ui/');
        self::assertSame(['Home', 'Products', 'Buying', 'Deliveries', 'Records', 'Settings'], array_keys(self::nav($home)));
        self::assertSame(['To do', 'Products', 'Barcodes', 'Suppliers', 'More'], array_column(self::tabs($home), 'label'));
    }

    public function testHomeIsTheSamePageForEveryoneWithMatchingProgressForLinkingRoles(): void
    {
        $site = $this->site('vpg');
        $this->queued($site, 'H1', 'Key', $this->item('legacy', 0, 'Home item'), ['product_title' => 'Home item']);
        $buyer = $this->signIn($this->uiUser('buyer'));
        $home = $buyer->get('/ui/');
        self::assertSame(200, $home->status, $home->describe());
        self::assertStringContainsString('Hello Buyer 1. You work as: Buyer.', $home->text());
        self::assertStringNotContainsString(Words::HOME['progress'], $home->text(), 'no matching progress without linking.view');
        self::assertStringNotContainsString('coming in Phase', $home->text());
        $xp = new \DOMXPath($home->dom());
        self::assertSame(['/ui/purchasing/reorder', '/ui/purchasing/orders', '/ui/purchasing/suppliers', '/ui/purchasing/sales-history', '/ui/items/cards',
            '/ui/documents', '/ui/reference/company', '/ui/reference/settings', '/ui/reference/approvals', '/ui/reference/warehouses'],
            array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array($xp->query('//main//ul[@class="uses"]//a'))), '"What you can use": the live links of a buyer');
        self::assertStringContainsString(Words::MENU_HELP['suppliers'], $home->text(), 'each with one line on what it is for');
        self::assertSame(1, $xp->query('//nav[@aria-label="Main"]//form[@action="/ui/search"]')->length, 'the find box: catalogue.view, in the menu');
        $current = $xp->query('//nav[@aria-label="Main"]//a[@aria-current="page"]');
        self::assertSame(1, $current->length);
        self::assertSame('Home', trim((string) $current->item(0)?->textContent));
        self::assertSame(1, $xp->query('//nav[@aria-label="Main tasks"]//a[@aria-current="page" and @href="/ui/"]')->length, 'the To do tab is the current one');

        $mapper = $this->signIn($this->uiUser('mapper'));
        $dash = $mapper->get('/ui/');
        self::assertSame(200, $dash->status);
        self::assertStringContainsString(Words::HOME['progress'], $dash->text());
        self::assertStringContainsString(Words::HOME['to_match'], $dash->text());
        self::assertStringNotContainsString(Words::HOME['look_match'], $dash->text(), 'a matcher decides');
        $nav = self::nav($dash);
        self::assertSame(['Home', 'Match products', 'Products', 'Settings'], array_keys($nav));
        self::assertSame(['/ui/review?queue=Key', '/ui/review?queue=pending', '/ui/review/samples', '/ui/review/duplicates'], array_column($nav['Match products'], 'href'));
        self::assertSame(['Products to match', 'Waiting for 2nd OK', 'Spot check', 'Possible duplicates'], array_column($nav['Match products'], 'label'));
        $current = (new \DOMXPath($dash->dom()))->query('//nav[@aria-label="Main"]//a[@aria-current="page"]');
        self::assertSame(1, $current->length);
        self::assertSame('Home', trim((string) $current->item(0)?->textContent), 'Home is the same page for a matcher');
        self::assertSame(['To do', 'Matches', 'Duplicates', 'Products', 'More'], array_column(self::tabs($dash), 'label'));

        // A person whose roles were all taken away (admin SQL only) is told so and sees no menu and no tab bar.
        $none = $this->uiUser('viewer');
        $web = $this->signIn($none);
        self::$db->exec('UPDATE staff_role SET revoked_at = NOW(6) WHERE staff_user_id = ?', [$none['id']]);
        $page = $web->get('/ui/');
        self::assertSame(200, $page->status);
        self::assertStringContainsString(Words::TASK['no_job']['text'], $page->text());
        self::assertSame([], self::nav($page));
        self::assertSame([], self::tabs($page));
        self::assertStringContainsString('You work as: ' . Words::UI['no_jobs'], $page->text());
        self::assertSame(403, $web->get('/ui/search')->status);
    }

    public function testAdminSeesStaffAndMatchingButNoBuyingAndBadgesCountOnlyWhatThePersonCanAct(): void
    {
        $site = $this->site('vpg');
        $l = $this->queued($site, 'B1', 'Key', $this->item('legacy', 0, 'Badge item'), ['product_title' => 'Badge item']);
        $mapper = $this->staffUser('mapper');
        $this->decide($mapper, 'link', $l, ['sku_id' => $this->item('legacy', 0, 'Other'), 'units_per_item' => 2,
            'proposal_id' => (int) self::$db->value("SELECT id FROM match_proposal WHERE listing_id = ? AND status = 'open'", [$l])]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE state = 'pending_second'"));

        $admin = $this->signIn($this->uiUser('admin'));
        $page = $admin->get('/ui/');
        $nav = self::nav($page);
        self::assertSame(['Home', 'Staff', 'Match products', 'Products', 'Settings'], array_keys($nav), 'Staff is the admin\'s work: second place');
        self::assertArrayNotHasKey('Buying', $nav);
        self::assertSame([['label' => 'Staff and access', 'href' => '/ui/people']], $nav['Staff']);
        self::assertSame('Waiting for 2nd OK', $nav['Match products'][1]['label'], 'no badge: admin cannot give the second OK (plan F007)');
        self::assertStringNotContainsString('class="badge"', $page->body);
        self::assertSame(['To do', 'Staff', 'Matches', 'Duplicates', 'More'], array_column(self::tabs($page), 'label'));

        // A matching lead who did not make the decision: the badge, in the menu with its words for a screen reader.
        $lead = $this->uiUser('mapping_lead');
        $leadWeb = $this->signIn($lead);
        $page = $leadWeb->get('/ui/');
        self::assertSame('Waiting for 2nd OK 1', self::nav($page)['Match products'][1]['label']);
        self::assertStringContainsString('<span class="visually-hidden"> ' . Words::BADGE['linking_pending'] . '</span>', $page->body);
        // ... and not their own decision: a second one, made by this lead, waits for another lead.
        $l2 = $this->queued($site, 'B2', 'Key', $this->item('legacy', 0, 'Badge item 2'), ['product_title' => 'Badge item 2']);
        $this->decide(Caller::staff($lead['id']), 'link', $l2, ['sku_id' => $this->item('legacy', 0, 'Other 2'), 'units_per_item' => 2,
            'proposal_id' => (int) self::$db->value("SELECT id FROM match_proposal WHERE listing_id = ? AND status = 'open'", [$l2])]);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE state = 'pending_second'"));
        self::assertSame('Waiting for 2nd OK 1', self::nav($leadWeb->get('/ui/'))['Match products'][1]['label'], 'the lead\'s own decision is not counted');
        self::assertSame('Waiting for 2nd OK 2', self::nav($this->signIn($this->uiUser('mapping_lead'))->get('/ui/'))['Match products'][1]['label']);
        // The mapper sees the list but no badge: a mapper cannot give the second OK.
        $mapperWeb = $this->signIn($this->uiUser('mapper'));
        self::assertSame('Waiting for 2nd OK', self::nav($mapperWeb->get('/ui/'))['Match products'][1]['label']);

        $reviewer = $this->signIn($this->uiUser('reviewer'));
        self::assertStringNotContainsString('class="badge"', $reviewer->get('/ui/')->body, 'no linking.view, no linking count');
        $auditor = $this->signIn($this->uiUser('auditor'));
        self::assertSame(['Home', 'Match products', 'Buying', 'Deliveries', 'Products', 'Records', 'Staff', 'Settings'], array_keys(self::nav($auditor->get('/ui/'))),
            'the auditor reads everything: no lift (staff.view is not staff.manage)');
        self::assertSame(['/ui/review?queue=Key', '/ui/review?queue=pending', '/ui/review/samples', '/ui/review/duplicates'],
            array_column(self::nav($auditor->get('/ui/'))['Match products'], 'href'));
    }

    public function testTheOwnersAccountWithAdminSaysWhichJobsAreSwitchedOff(): void
    {
        $owner = $this->uiUser(['mapping_lead', 'reviewer']);
        // checkRoleSet refuses this set (I12); only admin SQL writes it, as on staging today.
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'admin')", [$owner['id']]);
        $web = $this->signIn($owner);
        $page = $web->get('/ui/');
        self::assertSame(200, $page->status, $page->describe());
        $strip = (new \DOMXPath($page->dom()))->query('//main//div[contains(@class, "admin-off")]');
        self::assertSame(1, $strip->length, 'the yellow strip on every page');
        self::assertStringContainsString('Your Reviewer and Matching lead jobs are switched off because this account also has Admin. '
            . 'Ask Fazil to take Admin off this account.', (string) $strip->item(0)?->textContent);
        self::assertStringNotContainsString('second account', $page->text(), 'correction a: never suggest a second account');
        self::assertStringContainsString('You work as: Admin · Matching lead (off) · Reviewer (off)', $page->text());
        self::assertSame(['Home', 'Staff', 'Match products', 'Products', 'Settings'], array_keys(self::nav($page)));

        $refused = $web->get('/ui/documents/reviews');
        self::assertSame(403, $refused->status);
        self::assertStringContainsString('You cannot open this page while this account has Admin: Admin switches off your Reviewer job. '
            . 'Ask Fazil to take Admin off this account.', $refused->text());
        self::assertSame(1, (new \DOMXPath($refused->dom()))->query('//main//div[contains(@class, "admin-off")]')->length, 'the strip on the error page too');

        // Without admin, nothing is switched off and there is no strip.
        $lead = $this->signIn($this->uiUser(['mapping_lead', 'reviewer']));
        $page = $lead->get('/ui/');
        self::assertSame(0, (new \DOMXPath($page->dom()))->query('//*[contains(@class, "admin-off")]')->length);
        self::assertSame(['Home', 'To check', 'Match products', 'Buying', 'Deliveries', 'Products', 'Records', 'Settings'], array_keys(self::nav($page)),
            'the owner\'s daily view (plan §2.2)');
        self::assertSame(['To do', 'Matches', 'Duplicates', 'Orders', 'More'], array_column(self::tabs($page), 'label'), 'design A\'s tab bar');
    }

    public function testARoleTakenAwayStopsWorkingOnTheNextRequest(): void
    {
        $u = $this->uiUser(['mapper', 'buyer']);
        $web = $this->signIn($u);
        self::assertSame(200, $web->get('/ui/review', ['queue' => 'Key'])->status);
        $admin = $this->uiUser('admin');
        (new StaffAdmin(self::$db))->setRoles(Caller::staff($admin['id']), $u['id'], ['buyer'], null);
        self::assertSame(403, $web->get('/ui/review', ['queue' => 'Key'])->status, 'the same session, the next request');
        self::assertArrayNotHasKey('Match products', self::nav($web->get('/ui/')));
    }
}
