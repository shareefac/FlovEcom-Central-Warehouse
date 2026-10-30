<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Settings;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * Reports, settings and system on the approved patterns (wave w13, docs/decisions.md W13), as the pages are served: the five reports and the
 * audit log are TP_Report (Download (Excel) at the head's right, the figures strip, one toolbar with one Show row, one board with its blue
 * edge; no tab row, no tiles); Settings' pages are TP_Settings (cards of rows; a setting's row saves that setting alone through the setting's
 * own route and fields, and the change is in its History); Users is a board with one search and one Show; a user a record page whose account
 * actions are under the head's More; Stores a board. Layout only: every form posts where and what it did.
 */
final class ReportsSettingsPatternsScreensTest extends KernelUiTestCase
{
    private const KEY = 'reorder.default_safety_days';

    /** @var list<array<string, mixed>> the settings as they were (a row's Save changes one; the schema keeps app_setting between tests) */
    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, provisional, updated_actor, updated_at FROM app_setting');
    }

    protected function tearDown(): void
    {
        foreach ($this->settings as $r) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ?, updated_at = ? WHERE setting_key = ?',
                [$r['v'], (int) $r['provisional'], $r['updated_actor'], $r['updated_at'], $r['setting_key']]);
        }
        parent::tearDown();
    }

    private static function xp(UiResponse $page): \DOMXPath
    {
        return new \DOMXPath($page->dom());
    }

    /** The report pattern (5): the head's file, the strip, one Show row, the board's blue edge; no tab row and no tiles. */
    private static function assertReport(UiResponse $page, string $csv, bool $strip = true, bool $board = true): void
    {
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $xp = self::xp($page);
        self::assertSame(1, $xp->query('//main//header[contains(@class, "page-head")]//a[@download and starts-with(@href, "' . $csv . '")]')->length,
            "{$csv}: Download at the head's right");
        self::assertSame(Words::REPORTS['download'], trim((string) $xp->evaluate('string(//main//header//a[@download])')));
        self::assertSame(0, $xp->query('//main//form//a[@download]')->length, "{$csv}: not in the toolbar");
        self::assertSame($strip ? 1 : 0, $xp->query('//main//section[contains(@class, "rp-figs")]')->length, "{$csv}: the figures strip");
        self::assertSame(1, $xp->query('//main//form[contains(@class, "toolbar") and @method="get"]//div[@class="tb-state"]')->length, "{$csv}: one Show row");
        if ($strip) {
            self::assertSame(1, $xp->query('//main//section[contains(@class, "rp-figs")]/following::form[contains(@class, "toolbar")]')->length, "{$csv}: the strip before the toolbar");
        }
        if ($board) {
            self::assertGreaterThan(0, $xp->query('//main//div[contains(@class, "doc-board") and contains(@class, "info")]/table[contains(@class, "board")]')->length, "{$csv}: the board");
        }
        foreach (['//main//nav[contains(@class, "tabs")]', '//main//*[contains(@class, "kpis")]', '//main//p[@class="lede"]', '//main//details[contains(@class, "tb-pop")]'] as $gone) {
            self::assertSame(0, $xp->query($gone)->length, "{$csv}: {$gone} is the old layout");
        }
    }

    public function testTheReportsAndTheAuditLogAreTheApprovedReport(): void
    {
        $vpg = $this->site('vpg');
        $a = $this->item('strict', 3, 'Alpha 10ml');
        $this->listing($vpg, 'VA', $a);
        $rev = $this->signIn($this->uiUser('reviewer'));
        self::assertReport($rev->get('/ui/reports/stock-by-store'), '/ui/reports/stock-by-store.csv');
        self::assertReport($rev->get('/ui/reports/low-stock'), '/ui/reports/low-stock.csv');
        self::assertReport($rev->get('/ui/reports/slow-stock'), '/ui/reports/slow-stock.csv', true, false);
        self::assertReport($rev->get('/ui/system/audit'), '/ui/system/audit.csv', false);
        $value = $this->signIn($this->uiUser('accountant'))->get('/ui/reports/stock-value');
        self::assertReport($value, '/ui/reports/stock-value.csv');
        // Group by: Item, Brand, Warehouse (the tab row went); the search finds a brand or an item.
        self::assertSame([Words::REPORTS['group_product'], Words::REPORTS['group_brand'], Words::REPORTS['group_warehouse']],
            array_map(static fn (\DOMNode $o): string => trim((string) $o->textContent), iterator_to_array(self::xp($value)->query('//select[@name="by"]/option'))));
        self::assertSame(Words::REPORTS['find_value'], self::xp($value)->evaluate('string(//main//input[@type="search"]/@placeholder)'));
        // The store is chosen in the Show row, its figures are the strip.
        $store = $rev->get('/ui/reports/stock-by-store', ['store' => (string) $vpg->channelId]);
        self::assertSame(['VPG test site'], array_map(static fn (\DOMNode $o): string => trim((string) $o->textContent),
            iterator_to_array(self::xp($store)->query('//select[@name="store"]/option[@selected]'))));
        self::assertSame(Words::REPORTS['in_stock'], trim((string) self::xp($store)->evaluate('string(//table[contains(@class, "report-store")]//td[contains(@class, "doc-st")])')),
            'the status cell filled solid');
        self::assertStringContainsString(' done', (string) self::xp($store)->evaluate('string(//table[contains(@class, "report-store")]//td[contains(@class, "doc-st")]/@class)'));
    }

    public function testASettingsRowSavesThatSettingAloneAndItsHistoryKeepsIt(): void
    {
        $viewer = $this->signIn($this->uiUser('viewer'));
        $look = $viewer->get('/ui/reference/settings');
        self::assertSame(200, $look->status, $look->describe());
        self::assertSame(Words::SETTINGS_PAGE['title'], trim((string) self::xp($look)->evaluate('string(//main//h1)')), 'TP_Settings\' title');
        self::assertSame(0, self::xp($look)->query('//main//form[@method="post"]')->length, 'a viewer only looks');
        self::assertSame(0, self::xp($look)->query('//main//nav[contains(@class, "tabs")] | //main//table')->length, 'cards of rows; the sections are the sidebar\'s');
        self::assertStringContainsString(Words::SETTINGS_PAGE['foot'], $look->text());

        $rev = $this->signIn($this->uiUser('reviewer'));
        $page = $rev->get('/ui/reference/settings');
        $xp = self::xp($page);
        $row = $xp->query('//form[@data-key="' . self::KEY . '"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $row, 'a number is changed in its row');
        self::assertSame(['post', '/ui/reference/settings/setting'], [$row->getAttribute('method'), $row->getAttribute('action')], 'the setting\'s own route');
        $fields = [];
        foreach ($xp->query('.//input', $row) ?: [] as $in) {
            /** @var \DOMElement $in */
            $fields[$in->getAttribute('name')] = $in->getAttribute('value');
        }
        self::assertSame(['csrf', 'key', 'seen', 'value', 'reason'], array_keys($fields), 'the setting page\'s fields; not agreed yet: no tick sent');
        self::assertSame([self::KEY, '1', '5', ''], [$fields['key'], $fields['seen'], $fields['value'], $fields['reason']]);
        self::assertSame(Words::SETTINGS_PAGE['unit_days'], trim((string) $xp->evaluate('string(.//span[@class="muted"])', $row)));
        self::assertSame(1, $xp->query('.//input[@name="reason" and @required and @minlength="3"]', $row)->length, 'the reason, as ever');
        self::assertSame(0, $xp->query('//form[@data-key]//button[contains(@class, "primary")]')->length, 'a row\'s Save is plain');
        self::assertSame(1, $xp->query('//form[@data-key="' . self::KEY . '" and @data-unsaved="1" and @data-leaves="1"]')->length, 'typed values are guarded (W9.15)');

        // Saved alone: that setting changes, with its reason, and the row did not agree it.
        $before = (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'reorder.default_lead_days'");
        $audits = (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'setting.change'");
        $ok = $rev->post('/ui/reference/settings/setting', ['value' => '6', 'reason' => 'six days, from the row'] + $fields);
        self::assertSame(303, $ok->status, $ok->describe());
        $after = $rev->follow($ok);
        self::assertSame(Words::SETTING_NOTICE['saved'], trim((string) self::xp($after)->evaluate('string(//p[contains(@class, "notice")])')));
        self::assertStringContainsString('six days, from the row', (string) self::xp($after)->evaluate('string(//section[contains(@class, "history")])'), 'in its History');
        self::assertSame(6, (new Settings(self::$db))->get(self::KEY));
        self::assertSame(1, (int) self::$db->value('SELECT provisional FROM app_setting WHERE setting_key = ?', [self::KEY]), 'a row never agrees a value');
        self::assertSame($before, (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'reorder.default_lead_days'"), 'nothing else');
        self::assertSame($audits + 1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'setting.change'"), 'the audit as before: one entry');
        $stale = $rev->post('/ui/reference/settings/setting', ['value' => '7', 'reason' => 'from an old page'] + $fields);
        self::assertSame([409, 'changed_meanwhile'], [$stale->status, $stale->errorCode()], 'the row was drawn with its version');
        $list = $rev->get('/ui/reference/settings');
        self::assertStringContainsString('Reviewer', (string) self::xp($list)->evaluate('string(//*[@data-key="' . self::KEY . '"]//p[@class="set-kh"])'), 'who changed it last');

        // An agreed value stays agreed when its row saves.
        self::$db->exec('UPDATE app_setting SET provisional = 0 WHERE setting_key = ?', ['reorder.default_review_days']);
        $agreed = self::xp($rev->get('/ui/reference/settings'));
        self::assertSame('1', $agreed->evaluate('string(//form[@data-key="reorder.default_review_days"]//input[@name="agreed"]/@value)'));
        // Find a setting: only the rows whose words hold it.
        $found = $rev->get('/ui/reference/settings', ['q' => Words::settingName(self::KEY)]);
        $keys = array_map(static fn (\DOMElement $r): string => $r->getAttribute('data-key'),
            iterator_to_array(self::xp($found)->query('//*[contains(@class, "set-row") and @data-key]')));
        self::assertContains(self::KEY, $keys);
        self::assertLessThan(5, count($keys), 'only the rows whose words hold it');
        self::assertSame(0, self::xp($found)->query('//section[contains(@class, "company-summary")]')->length);
        self::assertStringContainsString(Words::SETTINGS_PAGE['none_found'], $rev->get('/ui/reference/settings', ['q' => 'nothing like this'])->text());
    }

    /**
     * The final fixes of w12 (docs/decisions.md W13.21; the Codex review of b12544a, LOW): a settings row is drawn with a value and the version
     * of that same value, read together. Another person's save between two separate reads drew the new value with the old version, and the
     * row's valid save was refused as "changed meanwhile". Here that save comes exactly between the page's reads: the row shows the value
     * and version as they were together, never one of each.
     */
    public function testASettingsRowsValueAndVersionAreReadTogether(): void
    {
        $other = $this->uiUser('reviewer');
        $rev = $this->signIn($this->uiUser('reviewer'));
        $pdo = self::$appDb->pdo();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\HookedStatement::class, []]);
        try {
            // Another person saves 6 while the page is being read (between its read of the versions and its read of the names before).
            \CW\Tests\Support\HookedStatement::$before['SELECT id, display_name FROM staff_user WHERE id IN'] = static function () use ($other): void {
                (new Settings(self::$db))->set(\CW\Caller::staff($other['id']), self::KEY, '6', 'another reviewer, meanwhile');
            };
            $page = $rev->get('/ui/reference/settings');
        } finally {
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
            \CW\Tests\Support\HookedStatement::$before = [];
        }
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(6, (new Settings(self::$db))->get(self::KEY), 'the other save happened while the page was read');
        $xp = self::xp($page);
        $row = '//form[@data-key="' . self::KEY . '"]';
        $drawn = [(string) $xp->evaluate('string(' . $row . '//input[@name="value"]/@value)'), (string) $xp->evaluate('string(' . $row . '//input[@name="seen"]/@value)')];
        self::assertContains($drawn, [['5', '1'], ['6', '2']], 'a value with its own version');
        // A row drawn with the value before the other save and its version: its save is refused (it did not see 6); one drawn after it is saved.
        $fields = [];
        foreach ($xp->query($row . '//input') ?: [] as $in) {
            /** @var \DOMElement $in */
            $fields[$in->getAttribute('name')] = $in->getAttribute('value');
        }
        $r = $rev->post('/ui/reference/settings/setting', ['value' => '7', 'reason' => 'seven, from the row'] + $fields);
        self::assertSame($drawn === ['6', '2'] ? 303 : 409, $r->status, $r->describe());
    }

    public function testTheOtherSettingsPagesAreCardsOfRows(): void
    {
        $rev = $this->signIn($this->uiUser('reviewer'));
        foreach (['/ui/reference/series', '/ui/reference/reasons', '/ui/reference/warehouses', '/ui/reference/approvals', '/ui/reference/access', '/ui/reference/company',
            '/ui/system/checks', '/ui/reference/reasons/reason?code=damaged', '/ui/reference/access?perm=reorder.view',
            '/ui/reference/settings/setting?key=' . self::KEY] as $path) {
            [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
            parse_str($q, $query);
            $page = $rev->get($p, $query);
            self::assertSame(200, $page->status, $path . ' ' . $page->describe());
            $xp = self::xp($page);
            if ($p !== '/ui/system/checks') {
                self::assertGreaterThan(0, $xp->query('//main//div[@class="set-list"]/section[contains(@class, "set-card")]')->length, "{$path}: cards");
            }
            self::assertSame(0, $xp->query('//main//nav[contains(@class, "tabs")] | //main//p[@class="crumbs"] | //main//p[@class="see-also"]')->length, "{$path}: no tab row, crumbs or links strip");
        }
        // A record of the shell: its list is its eyebrow (DC3); each change is under its row's Change, saved on its own.
        $reason = $rev->get('/ui/reference/reasons/reason', ['code' => 'damaged']);
        self::assertSame('/ui/reference/reasons', self::xp($reason)->evaluate('string(//main//p[contains(@class, "ph-back")]/a/@href)'));
        self::assertSame(['rename', 'uses', 'rules', 'off'], array_map(static fn (\DOMElement $i): string => $i->getAttribute('value'),
            iterator_to_array(self::xp($reason)->query('//section[contains(@class, "reason-changes")]//form//input[@name="do"]'))));
        self::assertSame(3, self::xp($reason)->query('//section[contains(@class, "reason-changes")]//details[contains(@class, "set-fold")]')->length);
        // Approval rules: a row per rule, its form and its history under its Change.
        $rules = self::xp($rev->get('/ui/reference/approvals'));
        self::assertNotNull($rules->query('//article[@id="rule-PO" and contains(@class, "set-row")]//details[contains(@class, "set-fold")]//form[@action="/ui/reference/approvals"]')->item(0));
        // Stores: a board, and still no form.
        $this->site('vpg', 'shadow');
        $sites = self::xp($rev->get('/ui/system/sites'));
        self::assertSame(1, $sites->query('//main//div[contains(@class, "doc-group")]//table[contains(@class, "sites")]//td[contains(@class, "doc-st")]')->length);
        self::assertSame(0, $sites->query('//main//form')->length);
    }

    public function testUsersIsABoardAndAUserARecordPage(): void
    {
        $admin = $this->signIn($this->uiUser('admin'));
        $buyer = $this->uiUser('buyer');
        $off = $this->uiUser('viewer');
        self::$db->exec('UPDATE staff_user SET is_active = 0 WHERE id = ?', [$off['id']]);
        $list = $admin->get('/ui/people');
        self::assertSame(200, $list->status, $list->describe());
        $xp = self::xp($list);
        self::assertSame([Words::STAFF['group_active'], Words::STAFF['group_off']],
            array_map(static fn (\DOMNode $b): string => trim((string) $b->textContent), iterator_to_array($xp->query('//main//div[contains(@class, "doc-group")]//button[@class="grp-toggle"]'))));
        self::assertSame(1, $xp->query('//main//form[contains(@class, "toolbar")]//input[@type="search" and @name="q"]')->length);
        self::assertSame(['', 'on', 'off'], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'), iterator_to_array($xp->query('//select[@name="show"]/option'))));
        self::assertContains('/ui/people.csv', $list->hrefs(), 'the file under the toolbar\'s More');
        self::assertSame(1, $xp->query('//main//details[contains(@class, "tb-more")]//a[@href="/ui/people.csv"]')->length);
        $found = $admin->get('/ui/people', ['q' => $buyer['email']]);
        self::assertSame(['/ui/people/' . $buyer['id']], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array(self::xp($found)->query('//table[contains(@class, "people")]//a[contains(@class, "row-link")]'))));
        self::assertSame(['/ui/people/' . $off['id']], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array(self::xp($admin->get('/ui/people', ['show' => 'off']))->query('//table[contains(@class, "people")]//a[contains(@class, "row-link")]'))));

        $page = $admin->get('/ui/people/' . $buyer['id']);
        self::assertSame(200, $page->status, $page->describe());
        $px = self::xp($page);
        self::assertSame('/ui/people', $px->evaluate('string(//main//p[contains(@class, "ph-back")]/a/@href)'), 'Users as the eyebrow');
        self::assertSame(1, $px->query('//main//header//div[@class="doc-actions"]//details[@class="more"]//form[contains(@action, "/active")]')->length,
            'the account\'s switch under the head\'s More');
        self::assertSame(1, $px->query('//main//section[contains(@class, "rp-figs")]')->length);
        self::assertSame(1, $px->query('//main//article[@class="doc-view"]')->length);
        self::assertSame(1, $px->query('//main//section[@class="doc-related"]//form[contains(@action, "/roles")]//button[contains(@class, "primary")]')->length, 'Save the jobs: the one main button');
    }
}
