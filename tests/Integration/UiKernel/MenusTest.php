<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Staff\StaffAdmin;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * Role-aware navigation and permission-guarded routes (I11, I14), through the real /ui kernel as cw_app. Mirrors the owner's
 * acceptance test of Phase I-1: "sign in as buyer, desk and reviewer and see different menus"; a page left out of a menu is also
 * refused (403), not only hidden.
 *
 * The approved redesign of 30 Oct 2026 (Sidebar.dc.html, Phone.dc.html; docs/decisions.md RD1-RD12): the sidebar is Home, Approvals,
 * Items, Inventory, Purchases, Sales, Store listings, Reports, Settings, each only for who may open it, the current section open with its
 * entries; the pages that belong together are one Show; Store listings' other matching pages are under the header's More; the phone bar
 * is Home, Receive, Count, Approvals, More; every page a person may open is reached from Home through what the pages draw; a count
 * counts only what the person can act on.
 */
final class MenusTest extends KernelUiTestCase
{
    public function testBuyerDeskAndReviewerSeeDifferentMenus(): void
    {
        $buyer = $this->signIn($this->uiUser('buyer'));
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $reviewer = $this->signIn($this->uiUser('reviewer'));

        $deskHome = $desk->get('/ui/');
        $all = ['Home', 'Approvals', 'Items', 'Inventory', 'Purchases', 'Sales', 'Reports', 'Settings'];
        self::assertSame($all, array_keys(self::nav($buyer->get('/ui/'))));
        self::assertSame($all, array_keys(self::nav($deskHome)));
        self::assertSame($all, array_keys(self::nav($reviewer->get('/ui/'))));
        self::assertSame(['href' => '/ui/', 'count' => 0], self::nav($deskHome)['Home'], 'Home first, for everyone');
        self::assertSame('Home', self::currentSection($deskHome));
        self::assertSame([], self::sectionTabs($deskHome), 'Home has no entries');
        // Sidebar.dc.html: two dividers (above Items, above Reports), no "Every day" / "More" groups.
        $xp = new \DOMXPath($deskHome->dom());
        self::assertSame(0, $xp->query('//nav[@aria-label="Main"]/p[@class="side-label"]')->length);
        self::assertSame([2, 4, 2], array_map(static fn (\DOMElement $ul): int => $ul->getElementsByTagName('li')->length,
            iterator_to_array($xp->query('//nav[@aria-label="Main"]/ul[@class="nav"]'))));
        self::assertSame(2, $xp->query('//nav[@aria-label="Main"]/hr[@class="nav-gap"]')->length);

        // Purchases: the buyer reorders, the desk receives, the reviewer reads all six.
        $tabs = static fn ($web): array => self::tabLabels($web->get('/ui/purchasing/orders'));
        self::assertSame(['Suppliers', 'Purchase orders', 'Purchase invoices', 'Purchase returns', 'Reorder'], $tabs($buyer));
        self::assertSame(['Suppliers', 'Purchase orders', 'Goods received', 'Purchase invoices', 'Purchase returns'], $tabs($desk), 'the desk receives, no reorder');
        self::assertSame(['Suppliers', 'Purchase orders', 'Goods received', 'Purchase invoices', 'Purchase returns', 'Reorder'], $tabs($reviewer));
        self::assertNotSame($tabs($buyer), $tabs($desk));
        self::assertSame('Purchase orders', self::currentTab($desk->get('/ui/purchasing/orders')));
        self::assertSame('/ui/purchasing/suppliers', self::nav($desk->get('/ui/'))['Purchases']['href'], 'a section links to its first entry the person may open');
        // Goods received's Show: the bench is for the people who receive.
        self::assertSame(['To receive', 'Checking', 'Issues', 'Duty register', 'Arriving'], array_column(self::segments($desk->get('/ui/receiving')), 'label'));
        self::assertSame(['To receive', 'Issues', 'Duty register', 'Arriving'], array_column(self::segments($reviewer->get('/ui/receiving')), 'label'));
        self::assertSame(0, (new \DOMXPath($buyer->get('/ui/purchasing/orders')->dom()))->query('//ol[contains(@class, "flow")]')->length, 'no buying-flow strip (DC5)');

        // Approvals: one entry; its Show: the inbox for who answers, the reviews, the history, the staff requests, the stores' queues.
        self::assertSame(['History', 'Lines to place', 'ERPNext corrections', 'Mode changes', 'Oversold', 'Website bugs'],
            array_column(self::segments($buyer->get('/ui/documents')), 'label'));
        self::assertSame(['Waiting for you', 'Documents to review', 'History', 'Staff requests', 'Lines to place', 'ERPNext corrections', 'Mode changes', 'Oversold',
            'Website bugs'], array_column(self::segments($reviewer->get('/ui/documents')), 'label'));
        self::assertSame([], self::sectionTabs($reviewer->get('/ui/documents')), 'one entry: no sub-items');
        self::assertSame('/ui/documents', self::nav($buyer->get('/ui/'))['Approvals']['href'], 'a buyer answers nothing: the history');
        // Settings: one entry; everyone reads; Stores (system.view) for the reviewer; "Roles and permissions" for all who read settings.
        self::assertSame(['Company', 'Warehouses', 'Roles and permissions', 'Approval rules', 'Reasons', 'Rules and numbers', 'Numbering'],
            array_column(self::segments($buyer->get('/ui/reference/company')), 'label'));
        self::assertSame(['Company', 'Warehouses', 'Stores', 'Roles and permissions', 'Approval rules', 'Reasons', 'Rules and numbers', 'Numbering', 'System checks',
            'Audit log'],
            array_column(self::segments($reviewer->get('/ui/reference/company')), 'label'));
        // Items: the items for everyone; the barcodes to check only for catalogue.edit (I109); item groups and multipacks for everyone.
        $cards = $buyer->get('/ui/items/cards');
        self::assertSame([['Items', null], ['Item groups', '/ui/items/families'], ['Multipacks', '/ui/items/packs']],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::sectionTabs($cards)), 'the page shown has no link (CV13)');
        self::assertSame([], self::segments($cards), 'a buyer makes no item: Made by hand is not theirs, so no Show');
        // Inventory: everyone who sees a product's stock; the stock records and the counts for the people who read records.
        $stockPage = $desk->get('/ui/stock');
        self::assertSame([['Stock on hand', null], ['Adjustments', '/ui/stock/adjustments'], ['Transfers', '/ui/stock/transfers'], ['Stock counts', '/ui/stock/counts'],
            ['Repack', '/ui/stock/repack'], ['Reserved stock', '/ui/stock/reservations'], ['Stock history', '/ui/stock/movements']],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::sectionTabs($stockPage)));
        self::assertSame(['Corrections', 'Stock in', 'Stock out', 'Releases'], array_column(self::segments($desk->get('/ui/stock/in')), 'label'));
        self::assertSame('Adjustments', self::currentTab($desk->get('/ui/stock/out')), 'a stock out is an Adjustments page (RD4)');
        // Sales: its five entries, the trade sales and the customers since the redesign.
        self::assertSame(['Sales orders', 'Shipments', 'Sales returns', 'Trade sales', 'Customers'], self::tabLabels($desk->get('/ui/stock/sales')));
        self::assertSame('Sales', self::currentSection($desk->get('/ui/stock/sales/dispatch')));
        self::assertSame('Sales', self::currentSection($desk->get('/ui/stock/handovers')));

        // The desk's screens of Phase I-4 to I-6 are named on Home, without phase codes.
        self::assertStringNotContainsString(Words::UI['coming_later'], $deskHome->text(), 'supplier invoices and returns are built (pack C), hand-overs (pack H)');
        self::assertStringNotContainsString('receiving deliveries', $deskHome->text(), 'receiving is built (IM6)');
        self::assertStringNotContainsString('Phase', $deskHome->text());
        self::assertStringNotContainsString('coming_later', $deskHome->text());

        // The phone's bottom bar (Phone.dc.html): Home · Receive · Count · Approvals · More (the whole sidebar), the ones the person has.
        self::assertSame(['Home', 'Count', 'Approvals', 'More'], array_column(self::tabs($buyer->get('/ui/')), 'label'), 'the phone tab bar of a buyer');
        self::assertSame(['/ui/', '/ui/stock/counts', '/ui/documents', '#menu'], array_column(self::tabs($buyer->get('/ui/')), 'href'),
            'a buyer answers nothing in the inbox: Approvals opens History');
        self::assertSame(['Home', 'Receive', 'Count', 'Approvals', 'More'], array_column(self::tabs($deskHome), 'label'));
        self::assertSame(['/ui/', '/ui/receiving', '/ui/stock/counts', '/ui/approvals', '#menu'], array_column(self::tabs($deskHome), 'href'));
    }

    /**
     * Same people reach the same pages (the brief's rule, RD12), as served: from Home, following only the links the pages draw in the
     * sidebar, a Show and the header's More, every page each job may open is reached and opens (200), for several jobs.
     */
    public function testEveryPageAPersonMayOpenIsReachedFromHomeAsServed(): void
    {
        foreach ([['buyer'], ['goods_in'], ['stock_controller'], ['mapping_lead', 'reviewer'], ['admin']] as $roles) {
            $web = $this->signIn($this->uiUser($roles));
            $seen = [];
            $todo = ['/ui/'];
            while ($todo !== []) {
                $url = array_shift($todo);
                if (isset($seen[$url])) {
                    continue;
                }
                $parts = parse_url($url);
                parse_str($parts['query'] ?? '', $query);
                $page = $web->get((string) $parts['path'], $query);
                self::assertSame(200, $page->status, implode('+', $roles) . " {$url}: " . $page->describe());
                $seen[$url] = true;
                $xp = new \DOMXPath($page->dom());
                $links = $xp->query('//nav[@aria-label="Main"]//a[contains(@class, "nav-item") or contains(@class, "nav-sub-item")][@href]'
                    . ' | //main//details[contains(@class, "show-menu")]//a[@href]'
                    . ' | //header[contains(@class, "page-head")]//details[contains(@class, "more")]//a[span[@class="menu-name"]]');
                foreach ($links ?: [] as $a) {
                    /** @var \DOMElement $a */
                    $to = html_entity_decode($a->getAttribute('href'));
                    if (!isset($seen[$to]) && str_starts_with($to, '/ui/')) {
                        $todo[] = $to;
                    }
                }
            }
            foreach (\CW\Ui\Sections::visible($roles) as $s => $tabsOf) {
                foreach ($tabsOf as $t => $tab) {
                    foreach ($tab['pages'] as $pg) {
                        self::assertArrayHasKey(\CW\Ui\Html::url((string) $pg['path'], $pg['query'] ?? []), $seen,
                            implode('+', $roles) . ": {$s} / {$t} / {$pg['key']} is reached from Home");
                    }
                }
            }
        }
    }

    public function testPagesOutsideTheMenuAreRefusedNotOnlyHidden(): void
    {
        $site = $this->site('vpg');
        $l = $this->queued($site, 'M1', 'Key', $this->item('legacy', 0, 'Menu item'), ['product_title' => 'Menu item']);
        $u = $this->uiUser('buyer');
        $buyer = $this->signIn($u);
        foreach (['/ui/review' => [['queue' => 'Key'], 'linking.view', null], '/ui/review/listing/' . $l => [[], 'linking.view', null],
            '/ui/people' => [[], 'staff.view', null], '/ui/people/' . $u['id'] => [[], 'staff.view', null], '/ui/system/audit' => [[], 'audit.view', null]]
            as $path => [$query, $perm, $tab]) {
            $page = $buyer->get($path, $query);
            self::assertSame(403, $page->status, "{$path}: " . $page->describe());
            self::assertSame('role_not_allowed', $page->errorCode());
            self::assertStringContainsString(Words::ERROR_TITLE['403'], $page->text());
            self::assertStringContainsString('This page is for ' . Words::whoCan($perm) . '. You work as: Buyer. If you need it for your work, ask '
                . Words::ASK_ROLE . '.', $page->text());
            self::assertStringNotContainsString('role_not_allowed', $page->text(), 'the code is not printed (plan F041)');
            self::assertSame(['Home', 'Approvals', 'Items', 'Inventory', 'Purchases', 'Sales', 'Reports', 'Settings'], array_keys(self::nav($page)),
                'the error page keeps the menu');
            self::assertSame($tab, self::currentTab($page), "{$path}: no entry marked");
        }
        self::assertSame('Purchases', self::currentSection($buyer->get('/ui/receiving/bench')), 'a refused page stays in its section');
        self::assertSame('Settings', self::currentSection($buyer->get('/ui/system/audit')), 'the audit log is a Settings page (RD7)');
        self::assertNull(self::currentSection($buyer->get('/ui/review', ['queue' => 'Key'])), 'a page of a section the person does not have marks none');
        self::assertSame([Words::MENU['stock_by_store'], Words::MENU['low_stock'], Words::MENU['slow_stock'], Words::MENU['sales_by_store']],
            array_column(self::sectionTabs($buyer->get('/ui/reports/low-stock')), 'label'), 'pack E: a buyer has Reports, without the money');
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
        self::assertSame(['Home', 'Approvals', 'Items', 'Inventory', 'Purchases', 'Sales', 'Reports', 'Settings'], array_keys(self::nav($both->get('/ui/'))));
        self::assertSame(['Suppliers', 'Purchase orders', 'Goods received', 'Purchase invoices', 'Purchase returns', 'Reorder'], self::tabLabels($both->get('/ui/purchasing/orders')),
            'the stock controller reads the receipts and the issues (IM6, I141)');
        $cards = $both->get('/ui/items/cards');
        self::assertSame([['Items', null], ['Item groups', '/ui/items/families'], ['Multipacks', '/ui/items/packs'], ['Barcodes', '/ui/items/barcodes']],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::sectionTabs($cards)));
        self::assertSame([['All items', null], ['Made by hand', '/ui/items/new?show=all']], array_map(static fn (array $t): array => [$t['label'], $t['href']],
            self::segments($cards)), 'the items made by hand: a Show choice of Items (RD2)');

        // A stock controller alone: no purchase orders; the phone bar of Phone.dc.html.
        $stock = $this->signIn($this->uiUser('stock_controller'));
        $home = $stock->get('/ui/');
        self::assertSame(['Home', 'Approvals', 'Items', 'Inventory', 'Purchases', 'Sales', 'Reports', 'Settings'], array_keys(self::nav($home)), 'pack E: the store reports');
        self::assertSame(['Suppliers', 'Goods received', 'Purchase returns'], self::tabLabels($stock->get('/ui/receiving')));
        self::assertSame(['Home', 'Receive', 'Count', 'Approvals', 'More'], array_column(self::tabs($home), 'label'));
    }

    public function testHomeIsTheSamePageForEveryoneAndTheMatchingProgressIsInMatching(): void
    {
        $site = $this->site('vpg');
        $this->queued($site, 'H1', 'Key', $this->item('legacy', 0, 'Home item'), ['product_title' => 'Home item']);
        $buyer = $this->signIn($this->uiUser('buyer'));
        $home = $buyer->get('/ui/');
        self::assertSame(200, $home->status, $home->describe());
        $xp = new \DOMXPath($home->dom());
        self::assertMatchesRegularExpression('/^Good (morning|afternoon|evening), Buyer$/', trim((string) $xp->evaluate('string(//main//h1)')),
            'the greeting with the first name (HM_Home)');
        self::assertStringNotContainsString(Words::HOME['coverage'], $home->text(), 'no matching progress on Home (HV1)');
        self::assertStringNotContainsString('coming in Phase', $home->text());
        self::assertSame(0, $xp->query('//main//ul[@class="uses"]')->length, 'no second menu on Home: the sidebar is the menu');
        self::assertSame(0, $xp->query('//main//details[@id="about"]')->length, 'no About box');
        self::assertSame(0, $xp->query('//main//form[@class="hm-find"]')->length, 'no Find box on Home: the sidebar\'s (the Main artboard, docs/decisions.md RD-C15)');
        self::assertSame(1, $xp->query('//header[@class="appbar"]//a[@href="/ui/search"]')->length, 'Phone.dc.html\'s top bar: Find');
        self::assertSame(1, $xp->query('//nav[@aria-label="Main"]//form[@class="side-find" and @action="/ui/search" and @method="get"]//input[@name="q"]')->length,
            'the sidebar\'s find box (Sidebar.dc.html)');
        $current = $xp->query('//nav[@aria-label="Main"]//a[@aria-current="page"]');
        self::assertSame(1, $current->length);
        self::assertSame('Home', trim((string) $current->item(0)?->textContent));
        self::assertSame(1, $xp->query('//nav[@aria-label="Main tasks"]//a[@aria-current="page" and @href="/ui/"]')->length, 'the Home tab is the current one');

        $mapper = $this->signIn($this->uiUser('mapper'));
        $dash = $mapper->get('/ui/');
        self::assertSame(200, $dash->status);
        self::assertStringNotContainsString(Words::HOME['coverage'], $dash->text(), 'the matching progress is in Matching (HV5)');
        self::assertSame(['Home', 'Items', 'Inventory', 'Store listings', 'Settings'], array_keys(self::nav($dash)));
        $queue = $mapper->get('/ui/review', ['queue' => 'Key']);
        self::assertSame('Store listings', self::currentSection($queue));
        self::assertSame([['By item', '/ui/review/products'], ['Check a match', null]],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::sectionTabs($queue)), 'a queue is Check a match (its address: no link, CV13)');
        self::assertSame([['Simple matching', '/ui/match'], ['Store products', '/ui/review/store'], ['Spot check', '/ui/review/samples'],
            ['Second approval', '/ui/review?queue=pending'], ['New variants', '/ui/review/new-variants'], ['Duplicates', '/ui/review/duplicates']],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::moreTabs($mapper->get('/ui/review/products'))),
            'the specialist matching pages under the header\'s More (RD6)');
        self::assertSame([], self::segments($queue), 'one page: no Show');
        $overview = $mapper->get('/ui/review');
        self::assertStringContainsString(Words::HOME['coverage'], $overview->text(), 'Store listings › Check a match: how much of what we sell is matched');
        self::assertSame('Home', self::currentSection($dash), 'Home is the same page for a matcher');
        self::assertSame(['Home', 'More'], array_column(self::tabs($dash), 'label'));

        // A person whose roles were all taken away (admin SQL only) is told so and sees no menu and no tab bar.
        $none = $this->uiUser('viewer');
        $web = $this->signIn($none);
        self::$db->exec('UPDATE staff_role SET revoked_at = NOW(6) WHERE staff_user_id = ?', [$none['id']]);
        $page = $web->get('/ui/');
        self::assertSame(200, $page->status);
        self::assertStringContainsString(Words::TASK['no_job']['text'], $page->text());
        self::assertSame([], self::nav($page));
        self::assertSame([], self::tabs($page));
        self::assertStringContainsString(Words::UI['no_jobs'], $page->text());
        self::assertSame(403, $web->get('/ui/search')->status);
    }

    public function testAdminSeesUsersAndMatchingButNoPurchasingAndCountsOnlyWhatThePersonCanAct(): void
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
        self::assertSame(['Home', 'Items', 'Inventory', 'Store listings', 'Settings'], array_keys($nav), 'no Purchases, no Approvals, no Reports for the admin (I12; the audit log is in Settings)');
        self::assertSame('/ui/people', array_column(self::segments($admin->get('/ui/reference/company')), 'href', 'label')['Users']);
        self::assertSame('/ui/system/audit', array_column(self::segments($admin->get('/ui/reference/company')), 'href', 'label')['Audit log']);
        $pending = $admin->get('/ui/review/products');
        self::assertSame(0, array_column(self::moreTabs($pending), 'count', 'label')['Second approval'], 'no count: admin cannot give the second OK (plan F007)');
        self::assertSame(0, array_sum(array_column($nav, 'count')));
        self::assertSame(['Home', 'More'], array_column(self::tabs($page), 'label'));

        // A matching lead who did not make the decision: the count, on Store listings and on Second approval under its More, with its words.
        $lead = $this->uiUser('mapping_lead');
        $leadWeb = $this->signIn($lead);
        $page = $leadWeb->get('/ui/review/products');
        self::assertSame(1, self::nav($page)['Store listings']['count']);
        self::assertSame(1, array_column(self::moreTabs($page), 'count', 'label')['Second approval']);
        self::assertStringContainsString('<span class="visually-hidden"> ' . Words::BADGE['linking_pending'] . '</span>', $page->body);
        $second = static fn ($web): int => array_column(self::moreTabs($web->get('/ui/review/products')), 'count', 'label')['Second approval'];
        // ... and not their own decision: a second one, made by this lead, waits for another lead.
        $l2 = $this->queued($site, 'B2', 'Key', $this->item('legacy', 0, 'Badge item 2'), ['product_title' => 'Badge item 2']);
        $this->decide(Caller::staff($lead['id']), 'link', $l2, ['sku_id' => $this->item('legacy', 0, 'Other 2'), 'units_per_item' => 2,
            'proposal_id' => (int) self::$db->value("SELECT id FROM match_proposal WHERE listing_id = ? AND status = 'open'", [$l2])]);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE state = 'pending_second'"));
        self::assertSame(1, $second($leadWeb), 'the lead\'s own decision is not counted');
        self::assertSame(2, $second($this->signIn($this->uiUser('mapping_lead'))));
        // The mapper sees the list but no count: a mapper cannot give the second OK.
        self::assertSame(0, $second($this->signIn($this->uiUser('mapper'))));

        $reviewer = $this->signIn($this->uiUser('reviewer'));
        self::assertSame(0, array_sum(array_column(self::nav($reviewer->get('/ui/')), 'count')), 'no linking.view, no linking count');
        $auditor = $this->signIn($this->uiUser('auditor'));
        self::assertSame(['Home', 'Approvals', 'Items', 'Inventory', 'Purchases', 'Sales', 'Store listings', 'Reports', 'Settings'], array_keys(self::nav($auditor->get('/ui/'))),
            'the auditor reads everything: all nine');
        $duplicates = $auditor->get('/ui/review/duplicates');
        self::assertSame('Store listings', self::currentSection($duplicates));
        self::assertSame(['By item', 'Check a match'], self::tabLabels($duplicates), 'a specialist page: no entry current');
        self::assertNull(self::currentTab($duplicates));
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
        self::assertSame(['Home', 'Items', 'Inventory', 'Store listings', 'Settings'], array_keys(self::nav($page)));

        $refused = $web->get('/ui/documents/reviews');
        self::assertSame(403, $refused->status);
        self::assertStringContainsString('You cannot open this page while this account has Admin: Admin switches off your Reviewer job. '
            . 'Ask Fazil to take Admin off this account.', $refused->text());
        self::assertSame(1, (new \DOMXPath($refused->dom()))->query('//main//div[contains(@class, "admin-off")]')->length, 'the strip on the error page too');

        // Without admin, nothing is switched off and there is no strip.
        $lead = $this->signIn($this->uiUser(['mapping_lead', 'reviewer']));
        $page = $lead->get('/ui/');
        self::assertSame(0, (new \DOMXPath($page->dom()))->query('//*[contains(@class, "admin-off")]')->length);
        self::assertSame(['Home', 'Approvals', 'Items', 'Inventory', 'Purchases', 'Sales', 'Store listings', 'Reports', 'Settings'], array_keys(self::nav($page)),
            'the owner\'s daily view: Sidebar.dc.html\'s nine');
        self::assertSame(['Home', 'Receive', 'Count', 'Approvals', 'More'], array_column(self::tabs($page), 'label'), 'the phone bar the owner approved (Phone.dc.html)');
    }

    public function testARoleTakenAwayStopsWorkingOnTheNextRequest(): void
    {
        $u = $this->uiUser(['mapper', 'buyer']);
        $web = $this->signIn($u);
        self::assertSame(200, $web->get('/ui/review', ['queue' => 'Key'])->status);
        $admin = $this->uiUser('admin');
        (new StaffAdmin(self::$db))->setRoles(Caller::staff($admin['id']), $u['id'], ['buyer'], null);
        self::assertSame(403, $web->get('/ui/review', ['queue' => 'Key'])->status, 'the same session, the next request');
        self::assertArrayNotHasKey('Store listings', self::nav($web->get('/ui/items/cards')), 'Store listings gone');
        self::assertSame(['Items', 'Item groups', 'Multipacks'], self::tabLabels($web->get('/ui/items/cards')));
    }

    /** Old menu addresses still lead where they did (bookmarks): nothing was renamed in a URL. */
    public function testOldMenuAddressesStillOpen(): void
    {
        $owner = $this->signIn($this->uiUser(['mapping_lead', 'reviewer']));
        foreach ([['/ui/', []], ['/ui/review', ['queue' => 'Key']], ['/ui/review', ['queue' => 'pending']], ['/ui/review/samples', []], ['/ui/review/duplicates', []],
            ['/ui/purchasing/reorder', []], ['/ui/purchasing/orders', []], ['/ui/purchasing/suppliers', []], ['/ui/purchasing/sales-history', []], ['/ui/receiving', []],
            ['/ui/receiving/incidents', []], ['/ui/items/cards', []], ['/ui/items/barcodes', []], ['/ui/documents', []], ['/ui/documents/reviews', []],
            ['/ui/reference/company', []], ['/ui/reference/settings', []], ['/ui/reference/approvals', []], ['/ui/reference/warehouses', []],
            ['/ui/reference/reasons', []], ['/ui/reference/series', []], ['/ui/reference/access', []], ['/ui/system/sites', []], ['/ui/system/checks', []],
            ['/ui/system/audit', []], ['/ui/staff-requests', []], ['/ui/stock', []], ['/ui/stock/movements', []], ['/ui/stock/reservations', []],
            ['/ui/match', []], ['/ui/approvals', []], ['/ui/stock/sales', []], ['/ui/stock/sales/dispatch', []], ['/ui/stock/counts', []], ['/ui/items/families', []]] as [$path, $query]) {
            $page = $owner->get($path, $query);
            self::assertSame(200, $page->status, $path . ' ' . $page->describe());
            self::assertNotNull(self::currentSection($page), "{$path}: its section is marked");
        }
        $bench = $this->signIn($this->uiUser('goods_in'));
        self::assertSame(200, $bench->get('/ui/receiving/bench')->status);
        $admin = $this->signIn($this->uiUser('admin'));
        self::assertSame(200, $admin->get('/ui/people')->status);
    }
}
