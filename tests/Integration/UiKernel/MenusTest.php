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
 * Since the owner's request of 8 Oct 2026 ("sidebar name make professional and make related tabs") the sidebar names standard sections
 * and everything else is a tab inside its section (Ui\Sections); since the shorter menu of 29 Oct 2026 (docs/decisions.md HV2-HV4) it is
 * Home, "Every day" (Goods in, Counts, Approvals, Purchase orders, All products) and "More" (the other sections), each entry only for who
 * may open it; a section's set-up and specialist pages are under its tab row's More; screens not built yet are "Soon" tabs or named on
 * Home; a count counts only what the person can act on.
 */
final class MenusTest extends KernelUiTestCase
{
    public function testBuyerDeskAndReviewerSeeDifferentMenus(): void
    {
        $buyer = $this->signIn($this->uiUser('buyer'));
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $reviewer = $this->signIn($this->uiUser('reviewer'));

        $deskHome = $desk->get('/ui/');
        // Pack E (PE1): the store reports are for buyers and the desk too (reports.view).
        self::assertSame(['Home', 'Counts', 'Approvals', 'Purchase orders', 'All products', 'Stock', 'Purchasing', 'Sales', 'Reports', 'Settings'], array_keys(self::nav($buyer->get('/ui/'))),
            'no Goods In for a buyer');
        self::assertSame(['Home', 'Goods in', 'Counts', 'Approvals', 'Purchase orders', 'All products', 'Stock', 'Purchasing', 'Sales', 'Reports', 'Settings'], array_keys(self::nav($deskHome)));
        self::assertSame(['Home', 'Goods in', 'Counts', 'Approvals', 'Purchase orders', 'All products', 'Stock', 'Purchasing', 'Sales', 'Reports', 'Settings'], array_keys(self::nav($reviewer->get('/ui/'))));
        self::assertSame(['href' => '/ui/', 'count' => 0], self::nav($deskHome)['Home'], 'Home first, for everyone');
        self::assertSame('Home', self::currentSection($deskHome));
        self::assertSame([], self::sectionTabs($deskHome), 'Home has no tabs');
        // The groups (HV2): Home, then "Every day", then "More", each a list named by its heading.
        $xp = new \DOMXPath($deskHome->dom());
        self::assertSame(['Every day', 'More'], array_map(static fn (\DOMElement $p): string => trim($p->textContent),
            iterator_to_array($xp->query('//nav[@aria-label="Main"]/p[@class="side-label"]'))));
        self::assertSame([['', 1], ['nav-daily', 5], ['nav-more', 5]], array_map(static fn (\DOMElement $ul): array => [$ul->getAttribute('aria-labelledby'),
            $ul->getElementsByTagName('li')->length], iterator_to_array($xp->query('//nav[@aria-label="Main"]/ul[@class="nav"]'))));

        // Purchasing: the buyer reorders, the desk receives, the reviewer reads all four.
        $tabs = static fn ($web): array => self::tabLabels($web->get('/ui/purchasing/orders'));
        self::assertSame(['Reorder', 'Purchase Orders', 'Invoices', 'Returns', 'Suppliers'], $tabs($buyer), 'invoices and returns since pack C');
        self::assertSame(['Purchase Orders', 'Goods In', 'Invoices', 'Returns', 'Suppliers'], $tabs($desk), 'the desk sees goods in, not reorder');
        self::assertSame(['Reorder', 'Purchase Orders', 'Goods In', 'Invoices', 'Returns', 'Suppliers'], $tabs($reviewer));
        self::assertNotSame($tabs($buyer), $tabs($desk));
        self::assertSame(['/ui/purchasing/orders', '/ui/purchasing/orders', '/ui/purchasing/reorder'],
            [self::nav($desk->get('/ui/'))['Purchasing']['href'], self::nav($desk->get('/ui/'))['Purchasing']['href'], self::nav($buyer->get('/ui/'))['Purchasing']['href']],
            'a section links to its first tab the person may open');
        // Goods In's segments: the bench is for the people who receive.
        self::assertSame(['To receive', 'Checking', 'Issues', 'Duty register', 'Arriving'], array_column(self::segments($desk->get('/ui/receiving')), 'label')); // Pack J: Arriving
        self::assertSame(['To receive', 'Issues', 'Duty register', 'Arriving'], array_column(self::segments($reviewer->get('/ui/receiving')), 'label'));
        // The buying flow: every step for the reviewer; a step the buyer cannot open has no link.
        $flow = static function ($page): array {
            $xp = new \DOMXPath($page->dom());
            return array_map(static fn (\DOMElement $li): array => [trim((string) $xp->evaluate('string(.//span[@class="flow-t"])', $li)),
                $li->getElementsByTagName('a')->length === 1], iterator_to_array($xp->query('//ol[@class="flow"]/li')));
        };
        self::assertSame([['Reorder', true], ['Purchase Order', true], ['Goods In', true], ['Stock updated', true]], $flow($reviewer->get('/ui/purchasing/orders')));
        self::assertSame([['Reorder', true], ['Purchase Order', true], ['Goods In', false], ['Stock updated', true]], $flow($buyer->get('/ui/purchasing/orders')));

        // Approvals: the reviewer's queue; the others read the history.
        // Pack F (PF5): From the stores, for everyone who reads records.
        self::assertSame(['History', 'From the stores'], self::tabLabels($buyer->get('/ui/documents')));
        // Waiting for you (docs/decisions.md AV1) first, for the people who answer any of its kinds; the old queue is "Documents to review".
        self::assertSame(['Waiting for you', 'Documents to review', 'History', 'Staff requests', 'From the stores'], self::tabLabels($reviewer->get('/ui/documents')));
        // Settings: everyone reads; Stores (system.view) for the reviewer; Users leads the others to "Roles and permissions".
        self::assertSame(['Company', 'Warehouses', 'Users', 'Approval Rules', 'Reasons', 'System'], self::tabLabels($buyer->get('/ui/reference/company')));
        self::assertSame(['Company', 'Warehouses', 'Stores', 'Users', 'Approval Rules', 'Reasons', 'System'], self::tabLabels($reviewer->get('/ui/reference/company')));
        self::assertSame('/ui/reference/access', array_column(self::sectionTabs($buyer->get('/ui/reference/company')), 'href', 'label')['Users']);
        // Products: the product list for everyone; the barcodes to check only for catalogue.edit (I109); no matching for these jobs. The
        // families and multipacks (set-up pages) are under the row's More (HV3).
        $cards = $buyer->get('/ui/items/cards');
        self::assertSame(['All Products'], self::tabLabels($cards));
        self::assertSame([['Families', '/ui/items/families'], ['Multipacks', '/ui/items/packs']], array_map(static fn (array $t): array => [$t['label'], $t['href']],
            self::moreTabs($cards)), 'pack B: families and pack items for everyone');
        // Stock: everyone who sees a product's stock; the stock records and the counts for the people who read records (packs A1, A2a); the
        // hand-overs, transfers, reservations and repacks under More, with the screen not built yet (Quality), never a link (HV3).
        $stockPage = $desk->get('/ui/stock');
        self::assertSame([['Overview', null], ['Movements', '/ui/stock/movements'], ['Stock In', '/ui/stock/in'], ['Stock Out', '/ui/stock/out'],
            ['Adjustments', '/ui/stock/adjustments'], ['Counts', '/ui/stock/counts']],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::sectionTabs($stockPage)));
        self::assertSame([['Hand-overs', '/ui/stock/handovers'], ['Transfers', '/ui/stock/transfers'], ['Reservations', '/ui/stock/reservations'], ['Quality', null],
            ['Repack', '/ui/stock/repack']], array_map(static fn (array $t): array => [$t['label'], $t['href']], self::moreTabs($stockPage)));
        // Sales: a section of its own, its three pages its tabs (HV3).
        self::assertSame(['Store orders', 'Dispatch', 'Returns and cancels'], self::tabLabels($desk->get('/ui/stock/sales')));
        self::assertSame('Sales', self::currentSection($desk->get('/ui/stock/sales/dispatch')));

        // The desk's screens of Phase I-4 to I-6 are named on Home, without phase codes.
        self::assertStringNotContainsString(Words::UI['coming_later'], $deskHome->text(), 'supplier invoices and returns are built (pack C), hand-overs (pack H)');
        self::assertStringNotContainsString('receiving deliveries', $deskHome->text(), 'receiving is built (IM6)');
        self::assertStringNotContainsString('Phase', $deskHome->text());
        self::assertStringNotContainsString('coming_later', $deskHome->text());

        // The phone's bottom bar (HM_Phone): Home · Goods in · Counts · Approvals · More (the whole sidebar), the ones the person has.
        self::assertSame(['Home', 'Counts', 'Approvals', 'More'], array_column(self::tabs($buyer->get('/ui/')), 'label'), 'the phone tab bar of a buyer');
        self::assertSame(['/ui/', '/ui/stock/counts', '/ui/documents', '#menu'], array_column(self::tabs($buyer->get('/ui/')), 'href'),
            'a buyer answers nothing in the inbox: Approvals opens History');
        self::assertSame(['Home', 'Goods in', 'Counts', 'Approvals', 'More'], array_column(self::tabs($deskHome), 'label'));
        self::assertSame(['/ui/', '/ui/receiving', '/ui/stock/counts', '/ui/approvals', '#menu'], array_column(self::tabs($deskHome), 'href'));
    }

    public function testPagesOutsideTheMenuAreRefusedNotOnlyHidden(): void
    {
        $site = $this->site('vpg');
        $l = $this->queued($site, 'M1', 'Key', $this->item('legacy', 0, 'Menu item'), ['product_title' => 'Menu item']);
        $u = $this->uiUser('buyer');
        $buyer = $this->signIn($u);
        foreach (['/ui/review' => [['queue' => 'Key'], 'linking.view', null], '/ui/review/listing/' . $l => [[], 'linking.view', null],
            '/ui/people' => [[], 'staff.view', 'Users'], '/ui/people/' . $u['id'] => [[], 'staff.view', 'Users'], '/ui/system/audit' => [[], 'audit.view', null]]
            as $path => [$query, $perm, $tab]) {
            $page = $buyer->get($path, $query);
            self::assertSame(403, $page->status, "{$path}: " . $page->describe());
            self::assertSame('role_not_allowed', $page->errorCode());
            self::assertStringContainsString(Words::ERROR_TITLE['403'], $page->text());
            self::assertStringContainsString('This page is for ' . Words::whoCan($perm) . '. You work as: Buyer. If you need it for your work, ask '
                . Words::ASK_ROLE . '.', $page->text());
            self::assertStringNotContainsString('role_not_allowed', $page->text(), 'the code is not printed (plan F041)');
            self::assertSame(['Home', 'Counts', 'Approvals', 'Purchase orders', 'All products', 'Stock', 'Purchasing', 'Sales', 'Reports', 'Settings'], array_keys(self::nav($page)),
                'the error page keeps the menu');
            self::assertSame($tab, self::currentTab($page), "{$path}: the tab marked is one of theirs, or none");
        }
        self::assertSame('Purchasing', self::currentSection($buyer->get('/ui/receiving/bench')), 'a refused page stays in its section');
        self::assertNull(self::currentSection($buyer->get('/ui/review', ['queue' => 'Key'])), 'a page of a section the person does not have marks none');
        self::assertSame([Words::MENU['stock_by_store'], Words::MENU['sales_by_store'], Words::MENU['low_stock'], Words::MENU['slow_stock']],
            array_column(self::sectionTabs($buyer->get('/ui/system/audit')), 'label'), 'pack E: a buyer has Reports, without the audit log and the money');
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
        self::assertSame(['Home', 'Goods in', 'Counts', 'Approvals', 'Purchase orders', 'All products', 'Stock', 'Purchasing', 'Sales', 'Reports', 'Settings'], array_keys(self::nav($both->get('/ui/'))),
            'pack E: the store reports; the stock controller\'s Goods In');
        self::assertSame(['Reorder', 'Purchase Orders', 'Goods In', 'Invoices', 'Returns', 'Suppliers'], self::tabLabels($both->get('/ui/purchasing/orders')),
            'the stock controller reads the receipts and the issues (IM6, I141)');
        $cards = $both->get('/ui/items/cards');
        self::assertSame([['All Products', null], ['Barcodes', '/ui/items/barcodes']], array_map(static fn (array $t): array => [$t['label'], $t['href']], self::sectionTabs($cards)));
        self::assertSame([['Add Item', '/ui/items/new'], ['Families', '/ui/items/families'], ['Multipacks', '/ui/items/packs']],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::moreTabs($cards)), 'the set-up pages under More (HV3)');

        // A stock controller alone: no purchase orders; the phone bar of HM_Phone.
        $stock = $this->signIn($this->uiUser('stock_controller'));
        $home = $stock->get('/ui/');
        self::assertSame(['Home', 'Goods in', 'Counts', 'Approvals', 'All products', 'Stock', 'Purchasing', 'Sales', 'Reports', 'Settings'], array_keys(self::nav($home)), 'pack E: the store reports');
        self::assertSame(['Home', 'Goods in', 'Counts', 'Approvals', 'More'], array_column(self::tabs($home), 'label'));
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
        self::assertSame(1, $xp->query('//main//form[@class="hm-find" and @action="/ui/search" and @method="get"]//input[@name="q"]')->length, 'ONE Find box: catalogue.view');
        self::assertSame(1, $xp->query('//header[@class="appbar"]//a[@href="/ui/search"]')->length, '... and a find button in the app bar on every page');
        $current = $xp->query('//nav[@aria-label="Main"]//a[@aria-current="page"]');
        self::assertSame(1, $current->length);
        self::assertSame('Home', trim((string) $current->item(0)?->textContent));
        self::assertSame(1, $xp->query('//nav[@aria-label="Main tasks"]//a[@aria-current="page" and @href="/ui/"]')->length, 'the Home tab is the current one');

        $mapper = $this->signIn($this->uiUser('mapper'));
        $dash = $mapper->get('/ui/');
        self::assertSame(200, $dash->status);
        self::assertStringNotContainsString(Words::HOME['coverage'], $dash->text(), 'the matching progress is in Matching (HV5)');
        self::assertSame(['Home', 'All products', 'Stock', 'Matching', 'Settings'], array_keys(self::nav($dash)));
        $queue = $mapper->get('/ui/review', ['queue' => 'Key']);
        self::assertSame('Matching', self::currentSection($queue));
        self::assertSame([['Simple matching', '/ui/match'], ['Mapping', null], ['Store Products', '/ui/review/store']],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::sectionTabs($queue)));
        self::assertSame([['By Item', '/ui/review/products'], ['New Variants', '/ui/review/new-variants'], ['Duplicates', '/ui/review/duplicates']],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::moreTabs($queue)), 'the specialist matching pages under More (HV3)');
        self::assertSame([['To review', null, true], ['Spot check', '/ui/review/samples', false], ['Second approval', '/ui/review?queue=pending', false]],
            array_map(static fn (array $s): array => [$s['label'], $s['href'], $s['current']], self::segments($queue)));
        $overview = $mapper->get('/ui/review');
        self::assertStringContainsString(Words::HOME['coverage'], $overview->text(), 'Matching › Mapping › To review: how much of what we sell is matched');
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
        self::assertSame(['Home', 'All products', 'Stock', 'Matching', 'Reports', 'Settings'], array_keys($nav), 'no Purchasing and no Approvals for the admin (I12)');
        self::assertSame('/ui/people', array_column(self::sectionTabs($admin->get('/ui/reference/company')), 'href', 'label')['Users']);
        $pending = $admin->get('/ui/review', ['queue' => 'pending']);
        self::assertSame([0, 0], [array_column(self::sectionTabs($pending), 'count', 'label')['Mapping'], self::segments($pending)[2]['count']],
            'no count: admin cannot give the second OK (plan F007)');
        self::assertSame(0, array_sum(array_column($nav, 'count')));
        self::assertSame(['Home', 'More'], array_column(self::tabs($page), 'label'));

        // A matching lead who did not make the decision: the count, on Matching, on Mapping and on Second approval, with its words.
        $lead = $this->uiUser('mapping_lead');
        $leadWeb = $this->signIn($lead);
        $page = $leadWeb->get('/ui/review', ['queue' => 'pending']);
        self::assertSame(1, self::nav($page)['Matching']['count']);
        self::assertSame(1, array_column(self::sectionTabs($page), 'count', 'label')['Mapping']);
        self::assertSame(['Second approval', 1, true], [self::segments($page)[2]['label'], self::segments($page)[2]['count'], self::segments($page)[2]['current']]);
        self::assertStringContainsString('<span class="visually-hidden"> ' . Words::BADGE['linking_pending'] . '</span>', $page->body);
        // ... and not their own decision: a second one, made by this lead, waits for another lead.
        $l2 = $this->queued($site, 'B2', 'Key', $this->item('legacy', 0, 'Badge item 2'), ['product_title' => 'Badge item 2']);
        $this->decide(Caller::staff($lead['id']), 'link', $l2, ['sku_id' => $this->item('legacy', 0, 'Other 2'), 'units_per_item' => 2,
            'proposal_id' => (int) self::$db->value("SELECT id FROM match_proposal WHERE listing_id = ? AND status = 'open'", [$l2])]);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE state = 'pending_second'"));
        self::assertSame(1, self::segments($leadWeb->get('/ui/review', ['queue' => 'pending']))[2]['count'], 'the lead\'s own decision is not counted');
        self::assertSame(2, self::segments($this->signIn($this->uiUser('mapping_lead'))->get('/ui/review', ['queue' => 'pending']))[2]['count']);
        // The mapper sees the list but no count: a mapper cannot give the second OK.
        $mapperWeb = $this->signIn($this->uiUser('mapper'));
        self::assertSame(0, self::segments($mapperWeb->get('/ui/review', ['queue' => 'pending']))[2]['count']);

        $reviewer = $this->signIn($this->uiUser('reviewer'));
        self::assertSame(0, array_sum(array_column(self::nav($reviewer->get('/ui/')), 'count')), 'no linking.view, no linking count');
        $auditor = $this->signIn($this->uiUser('auditor'));
        self::assertSame(['Home', 'Goods in', 'Counts', 'Approvals', 'Purchase orders', 'All products', 'Stock', 'Purchasing', 'Sales', 'Matching', 'Reports', 'Settings'], array_keys(self::nav($auditor->get('/ui/'))),
            'the auditor reads everything');
        $duplicates = $auditor->get('/ui/review/duplicates');
        self::assertSame(['Simple matching', 'Mapping', 'Store Products', 'Duplicates'], self::tabLabels($duplicates), 'the page shown in the row');
        self::assertSame(['By Item', 'New Variants'], array_column(self::moreTabs($duplicates), 'label'));
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
        self::assertSame(['Home', 'All products', 'Stock', 'Matching', 'Reports', 'Settings'], array_keys(self::nav($page)));

        $refused = $web->get('/ui/documents/reviews');
        self::assertSame(403, $refused->status);
        self::assertStringContainsString('You cannot open this page while this account has Admin: Admin switches off your Reviewer job. '
            . 'Ask Fazil to take Admin off this account.', $refused->text());
        self::assertSame(1, (new \DOMXPath($refused->dom()))->query('//main//div[contains(@class, "admin-off")]')->length, 'the strip on the error page too');

        // Without admin, nothing is switched off and there is no strip.
        $lead = $this->signIn($this->uiUser(['mapping_lead', 'reviewer']));
        $page = $lead->get('/ui/');
        self::assertSame(0, (new \DOMXPath($page->dom()))->query('//*[contains(@class, "admin-off")]')->length);
        self::assertSame(['Home', 'Goods in', 'Counts', 'Approvals', 'Purchase orders', 'All products', 'Stock', 'Purchasing', 'Sales', 'Matching', 'Reports', 'Settings'], array_keys(self::nav($page)),
            'the owner\'s daily view: twelve entries');
        self::assertSame(['Home', 'Goods in', 'Counts', 'Approvals', 'More'], array_column(self::tabs($page), 'label'), 'the phone bar the owner approved (HM_Phone)');
    }

    public function testARoleTakenAwayStopsWorkingOnTheNextRequest(): void
    {
        $u = $this->uiUser(['mapper', 'buyer']);
        $web = $this->signIn($u);
        self::assertSame(200, $web->get('/ui/review', ['queue' => 'Key'])->status);
        $admin = $this->uiUser('admin');
        (new StaffAdmin(self::$db))->setRoles(Caller::staff($admin['id']), $u['id'], ['buyer'], null);
        self::assertSame(403, $web->get('/ui/review', ['queue' => 'Key'])->status, 'the same session, the next request');
        self::assertArrayNotHasKey('Matching', self::nav($web->get('/ui/items/cards')), 'Matching gone');
        self::assertSame(['Families', 'Multipacks'], array_column(self::moreTabs($web->get('/ui/items/cards')), 'label'));
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
