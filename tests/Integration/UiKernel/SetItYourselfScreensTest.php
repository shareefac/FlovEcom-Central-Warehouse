<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Admin\ApprovalRules;
use CW\Caller;
use CW\Ops\IntegrityRuns;
use CW\Settings;
use CW\Staff\StaffRoles;
use CW\Staff\Totp;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The set-it-yourself screens (0019; docs/decisions.md Y1-Y40, the owner's rule of 8 Oct 2026), through the real kernel as the app
 * login: a setting, an approval rule, a reason, a warehouse and a place changed by an admin or a reviewer with a reason and seen in
 * their history; everyone else looks; the websites, the safety checks and the audit log read only; who can do what; a person added
 * with their QR code shown once who sets their own password at /ui/enrol; a new code, a new password, signing out a device; a
 * grant of Reviewer waiting for a reviewer's OK while the owner has that rule on. Refusals in words; every table cards on a phone.
 */
final class SetItYourselfScreensTest extends KernelUiTestCase
{
    /** @var list<array<string, mixed>> */
    private array $settings = [];
    /** @var list<array<string, mixed>> */
    private array $rules = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, provisional, updated_actor FROM app_setting');
        $this->rules = self::$db->all('SELECT * FROM document_type');
    }

    protected function tearDown(): void
    {
        foreach ($this->settings as $r) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ? WHERE setting_key = ?',
                [$r['v'], (int) $r['provisional'], $r['updated_actor'], $r['setting_key']]);
        }
        foreach ($this->rules as $r) {
            self::$db->exec('UPDATE document_type SET review_rule = ?, review_limit_units = ?, review_due_days = ?, approval_rule = ?, approval_limit_units = ?, '
                . 'reject_action = ? WHERE code = ?', [$r['review_rule'], $r['review_limit_units'], $r['review_due_days'], $r['approval_rule'],
                    $r['approval_limit_units'], $r['reject_action'], $r['code']]);
        }
        self::$db->exec("UPDATE warehouse SET name = 'Main warehouse', note = NULL WHERE code = 'MAIN'");
        self::$db->exec("DELETE FROM reason_code WHERE code = 'damp'");
        parent::tearDown();
    }

    private static function h1(UiResponse $r): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) (new \DOMXPath($r->dom()))->evaluate('string(//main//h1)')));
    }

    private static function notice(UiResponse $r): string
    {
        return trim((string) (new \DOMXPath($r->dom()))->evaluate('string(//p[contains(@class, "notice")])'));
    }

    /** The text before the first %s of a word with a placeholder. */
    private static function lead(string $word): string
    {
        return (string) strstr($word, '%s', true);
    }

    /** Every table of a page is cards on a phone (writing rule 14); no UTC on a page. */
    private static function assertPhoneTables(UiResponse $r): void
    {
        foreach ((new \DOMXPath($r->dom()))->query('//main//table') ?: [] as $t) {
            /** @var \DOMElement $t */
            self::assertStringContainsString('stack', $t->getAttribute('class'), 'every table is cards on a phone');
        }
        self::assertStringNotContainsString('UTC', $r->text());
    }

    public function testASettingIsChangedOnItsPageWithAReasonAndKeptInItsHistory(): void
    {
        $key = 'reorder.default_safety_days';
        $buyer = $this->signIn($this->uiUser('buyer'));
        $list = $buyer->get('/ui/reference/settings');
        self::assertSame(200, $list->status, $list->describe());
        self::assertContains('/ui/reference/settings/setting?key=' . $key, $list->hrefs());
        self::assertNotContains('/ui/reference/settings/setting?key=approvals.staff_grant', $list->hrefs(), 'the approval rules have their own page');
        self::assertContains('/ui/reference/approvals', $list->hrefs());
        self::assertContains('/ui/reference/access', $list->hrefs());
        $page = $buyer->get('/ui/reference/settings/setting', ['key' => $key]);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(Words::settingName($key), self::h1($page));
        self::assertFalse($page->hasForm('/ui/reference/settings/setting'), 'a buyer looks');
        self::assertStringContainsString(Words::SETTING_EDIT['look_only'], $page->text());
        self::assertStringContainsString(Words::CONFIG_ACTION['baseline'], $page->text(), 'the history starts at the set-up');
        self::assertSame(403, $buyer->post('/ui/reference/settings/setting', ['csrf' => $this->token($buyer), 'key' => $key, 'seen' => '1', 'value' => '6',
            'reason' => 'buyers may not'])->status);
        self::assertSame(404, $buyer->get('/ui/reference/settings/setting', ['key' => 'no.such'])->status);

        $rev = $this->uiUser('reviewer');
        $web = $this->signIn($rev);
        $page = $web->get('/ui/reference/settings/setting', ['key' => $key]);
        $form = $page->form('/ui/reference/settings/setting', true);
        self::assertSame([$key, '1', '5', ''], [$form['key'], $form['seen'], $form['value'], $form['reason']]);
        self::assertArrayNotHasKey('agreed', $form, 'not agreed yet: the tick is empty');
        self::assertStringContainsString(sprintf(Words::SETTING_EDIT['range'], '0', '120'), $page->text());
        $bad = $web->post('/ui/reference/settings/setting', ['value' => '121', 'reason' => 'too many'] + $form);
        self::assertSame([400, 'bad_value'], [$bad->status, $bad->errorCode()]);
        self::assertStringContainsString(Words::CONFIG_ERROR['bad_value'], $bad->text());
        self::assertSame('121', $bad->form('/ui/reference/settings/setting')['value'], 'what was typed is kept');
        $noWhy = $web->post('/ui/reference/settings/setting', ['value' => '6', 'reason' => ' '] + $form);
        self::assertSame([400, 'bad_reason'], [$noWhy->status, $noWhy->errorCode()]);
        $ok = $web->post('/ui/reference/settings/setting', ['value' => '6', 'agreed' => '1', 'reason' => 'the owner wants six days'] + $form);
        self::assertSame(303, $ok->status, $ok->describe());
        $after = $web->follow($ok);
        self::assertSame(Words::SETTING_NOTICE['saved'], self::notice($after));
        self::assertStringContainsString('the owner wants six days', $after->text());
        self::assertStringContainsString(Words::CONFIG_ACTION['change'], $after->text());
        self::assertSame(6, (new Settings(self::$db))->get($key));
        self::assertSame(0, (int) self::$db->value('SELECT provisional FROM app_setting WHERE setting_key = ?', [$key]), 'ticked: agreed');
        self::assertSame(2, (int) self::$db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'setting' AND subject_key = ?", [$key]));
        self::assertPhoneTables($after);
        $stale = $web->post('/ui/reference/settings/setting', ['value' => '7', 'reason' => 'from an old page'] + $form);
        self::assertSame([409, 'changed_meanwhile'], [$stale->status, $stale->errorCode()]);
        self::assertStringContainsString(self::lead(Words::CONFIG_ERROR['changed_meanwhile']), $stale->text());
        self::assertSame(6, (new Settings(self::$db))->get($key), 'nothing saved');
        self::assertStringContainsString('Reviewer', $web->get('/ui/reference/settings')->text(), 'the list says who changed it');
        // An approval rule is changed on the Approval rules page: no form here, and a POST is refused.
        $sw = $web->get('/ui/reference/settings/setting', ['key' => 'approvals.staff_grant']);
        self::assertSame(200, $sw->status);
        self::assertFalse($sw->hasForm('/ui/reference/settings/setting'));
        self::assertStringContainsString(Words::SETTING_EDIT['approvals'], $sw->text());
        self::assertSame(409, $web->post('/ui/reference/settings/setting', ['csrf' => $this->token($web), 'key' => 'approvals.staff_grant', 'seen' => '1',
            'value' => 'true', 'reason' => 'not here'])->status);
        self::assertFalse(ApprovalRules::on(self::$db, 'approvals.staff_grant'));
        // The admin (Fazil) changes settings too: the owner's decision of 8 Oct 2026.
        $admin = $this->signIn($this->uiUser('admin'));
        self::assertTrue($admin->get('/ui/reference/settings/setting', ['key' => $key])->hasForm('/ui/reference/settings/setting'));
    }

    public function testTheApprovalRulesPageListsEveryRuleAndSwitchesThem(): void
    {
        $buyer = $this->signIn($this->uiUser('buyer'));
        $page = $buyer->get('/ui/reference/approvals');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(Words::MENU['approvals'], self::h1($page));
        foreach ([Words::RULE['approvals.supplier_activation']['title'], Words::RULE['approvals.staff_grant']['title'], Words::RULE['approvals.match_multiple']['title'],
            Words::RULE['approvals.match_counted']['title'], Words::RULE['approvals.spot_check_size']['title'], Words::RULE['approvals.company_own_change']['title'],
            Words::RULE['approvals.staff_grant']['off'], Words::RULE['approvals.supplier_activation']['on'], Words::APPROVALS['others']] as $text) {
            self::assertStringContainsString($text, $page->text());
        }
        self::assertNotNull((new \DOMXPath($page->dom()))->query('//article[@id="rule-PO"]')->item(0), 'the purchase orders\' rule');
        self::assertFalse($page->hasForm('/ui/reference/approvals'), 'a buyer looks');
        self::assertSame(['/ui/reference/company', '/ui/reference/warehouses', '/ui/reference/access', '/ui/reference/approvals', '/ui/reference/reasons',
            '/ui/reference/settings'], array_column(self::sectionTabs($page), 'href'), 'the Settings tabs a buyer may open');
        self::assertSame(['Settings', 'Approval Rules'], [self::currentSection($page), self::currentTab($page)]);

        $rev = $this->signIn($this->uiUser('reviewer'));
        self::assertTrue($rev->get('/ui/reference/approvals')->hasForm('/ui/reference/approvals'));
        $csrf = $this->token($rev);
        $po = ['csrf' => $csrf, 'kind' => 'document', 'key' => 'PO', 'seen' => '1', 'review_rule' => 'all', 'review_limit' => '', 'review_due_days' => '7',
            'approval' => '0', 'approval_limit' => '10000', 'reject_action' => 'record', 'reason' => 'leave it for now'];
        $r = $rev->post('/ui/reference/approvals', $po);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringEndsWith('#rule-PO', (string) $r->location());
        $after = $rev->follow($r);
        self::assertSame(Words::APPROVALS['saved'], self::notice($after));
        self::assertStringContainsString(Words::APPROVALS['no_ok'], $after->text());
        self::assertSame(['none', 10000], array_values((array) self::$db->one("SELECT approval_rule, approval_limit_units FROM document_type WHERE code = 'PO'")),
            'switched off, the limit kept');
        // A refusal comes back on its rule's card, in words, open.
        $bad = $rev->post('/ui/reference/approvals', ['seen' => '2', 'review_due_days' => '0', 'reason' => 'zero days'] + $po);
        self::assertSame(400, $bad->status, $bad->describe());
        $xp = new \DOMXPath($bad->dom());
        self::assertSame(Words::CONFIG_ERROR['bad_days'], trim((string) $xp->evaluate('string(//article[@id="rule-PO"]//p[contains(@class, "error")])')));
        self::assertSame('0', (string) $xp->evaluate('string(//article[@id="rule-PO"]//input[@name="review_due_days"]/@value)'), 'what was typed is kept');
        // The staff rule switched on, the spot check size changed, a stale form refused, a buyer refused.
        $r = $rev->post('/ui/reference/approvals', ['csrf' => $csrf, 'kind' => 'switch', 'key' => 'approvals.staff_grant', 'seen' => '1', 'value' => 'true',
            'reason' => 'two people for Admin and Reviewer']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringEndsWith('#rule-approvals-staff-grant', (string) $r->location());
        self::assertTrue(ApprovalRules::on(self::$db, 'approvals.staff_grant'));
        $r = $rev->post('/ui/reference/approvals', ['csrf' => $csrf, 'kind' => 'number', 'key' => 'approvals.spot_check_size', 'seen' => '1', 'value' => '25',
            'reason' => 'a bigger spot check']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(25, ApprovalRules::number(self::$db, 'approvals.spot_check_size'));
        $tooBig = $rev->post('/ui/reference/approvals', ['csrf' => $csrf, 'kind' => 'number', 'key' => 'approvals.spot_check_size', 'seen' => '2', 'value' => '500',
            'reason' => 'far too big']);
        self::assertSame([400, 'bad_value'], [$tooBig->status, $tooBig->errorCode()]);
        $stale = $rev->post('/ui/reference/approvals', ['csrf' => $csrf, 'kind' => 'switch', 'key' => 'approvals.staff_grant', 'seen' => '1', 'value' => 'false',
            'reason' => 'from an old page']);
        self::assertSame([409, 'changed_meanwhile'], [$stale->status, $stale->errorCode()]);
        self::assertSame(403, $buyer->post('/ui/reference/approvals', ['csrf' => $this->token($buyer), 'kind' => 'switch', 'key' => 'approvals.staff_grant',
            'seen' => '2', 'value' => 'false', 'reason' => 'buyers may not'])->status);
        // The admin (Fazil) makes rules stricter, never looser (I1): the page says so, and switching the staff rule off is refused on its card.
        $admin = $this->signIn($this->uiUser('admin'));
        $adminPage = $admin->get('/ui/reference/approvals');
        self::assertStringContainsString(Words::APPROVALS['loosen_admin'], $adminPage->text());
        self::assertStringContainsString(Words::APPROVALS['not_release'], $adminPage->text());
        $off = $admin->post('/ui/reference/approvals', ['csrf' => $this->token($admin), 'kind' => 'switch', 'key' => 'approvals.staff_grant', 'seen' => '2',
            'value' => 'false', 'reason' => 'switch the check off']);
        self::assertSame([403, 'loosen_needs_reviewer'], [$off->status, $off->errorCode()]);
        self::assertSame(Words::CONFIG_ERROR['loosen_needs_reviewer'],
            trim((string) (new \DOMXPath($off->dom()))->evaluate('string(//article[@id="rule-approvals-staff-grant"]//p[contains(@class, "error")])')));
        self::assertTrue(ApprovalRules::on(self::$db, 'approvals.staff_grant'), 'nothing saved');
        // The owner marks a provisional rule agreed on this page (M5); the PO card's Not OK only records (M7).
        self::assertSame(1, (int) self::$db->value("SELECT provisional FROM app_setting WHERE setting_key = 'approvals.staff_grant'"));
        $r = $rev->post('/ui/reference/approvals', ['csrf' => $csrf, 'kind' => 'switch', 'key' => 'approvals.staff_grant', 'seen' => '2', 'value' => 'true',
            'agreed' => '1', 'reason' => 'the owner agrees']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(0, (int) self::$db->value("SELECT provisional FROM app_setting WHERE setting_key = 'approvals.staff_grant'"));
        self::assertSame('agree', self::$db->value("SELECT action FROM config_change WHERE subject_key = 'approvals.staff_grant' ORDER BY version DESC LIMIT 1"));
        $poCard = (new \DOMXPath($rev->get('/ui/reference/approvals')->dom()))->query('//article[@id="rule-PO"]')->item(0);
        self::assertNotNull($poCard);
        self::assertStringContainsString(Words::APPROVALS['reject_record_only'], (string) $poCard->textContent);
        self::assertSame(0, (new \DOMXPath($rev->get('/ui/reference/approvals')->dom()))->query('//article[@id="rule-PO"]//select[@name="reject_action"]')->length);
        $page = $rev->get('/ui/reference/approvals');
        self::assertStringContainsString(Words::RULE['approvals.staff_grant']['on'], $page->text());
        self::assertStringContainsString(sprintf(Words::RULE['approvals.spot_check_size']['now'], '25'), $page->text());
        self::assertStringContainsString(Words::RULE['approvals.spot_check_size']['note'], $page->text(), 'a change works for new spot checks only');
        self::assertStringContainsString(Words::RULE['approvals.staff_reset']['title'], $page->text());
        self::assertStringContainsString('two people for Admin and Reviewer', $page->text(), 'each rule\'s history');
        self::assertPhoneTables($page);
    }

    public function testReasonsAreAddedRenamedAndSwitchedOffOnTheScreens(): void
    {
        $buyer = $this->signIn($this->uiUser('buyer'));
        $list = $buyer->get('/ui/reference/reasons');
        self::assertSame(200, $list->status, $list->describe());
        self::assertFalse($list->hasForm('/ui/reference/reasons'), 'a buyer looks');
        $admin = $this->signIn($this->uiUser('admin'));
        $csrf = $this->token($admin);
        $bad = $admin->post('/ui/reference/reasons', ['csrf' => $csrf, 'code' => '9x', 'label' => 'Damp', 'use_write_off' => '1', 'direction' => 'decrease',
            'reason' => 'found damp']);
        self::assertSame(400, $bad->status, $bad->describe());
        self::assertStringContainsString(Words::CONFIG_ERROR['bad_code_reason'], $bad->text());
        self::assertSame('Damp', $bad->form('/ui/reference/reasons')['label'], 'what was typed is kept');
        $r = $admin->post('/ui/reference/reasons', ['csrf' => $csrf, 'code' => 'damp', 'label' => 'Damp stock', 'use_write_off' => '1', 'use_adjustment' => '1',
            'direction' => 'decrease', 'needs_note' => '1', 'reason' => 'found damp boxes']);
        self::assertSame(303, $r->status, $r->describe());
        $page = $admin->follow($r);
        self::assertSame(Words::REASON_NOTICE['added'], self::notice($page));
        self::assertStringStartsWith('Damp stock', self::h1($page));
        self::assertSame(409, $admin->post('/ui/reference/reasons', ['csrf' => $csrf, 'code' => 'damp', 'label' => 'Damp again', 'use_write_off' => '1',
            'direction' => 'decrease', 'reason' => 'twice'])->status);
        $form = $page->form('/ui/reference/reasons/reason');
        self::assertSame(['damp', '1', 'rename'], [$form['code'], $form['seen'], $form['do']]);
        $r = $admin->post('/ui/reference/reasons/reason', ['label' => 'Damp or wet stock', 'reason' => 'clearer'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        $page = $admin->follow($r);
        self::assertSame(Words::REASON_NOTICE['renamed'], self::notice($page));
        self::assertStringStartsWith('Damp or wet stock', self::h1($page));
        $r = $admin->post('/ui/reference/reasons/reason', ['csrf' => $csrf, 'code' => 'damp', 'seen' => '2', 'do' => 'off', 'reason' => 'not used']);
        self::assertSame(303, $r->status, $r->describe());
        $page = $admin->follow($r);
        self::assertSame(Words::REASON_NOTICE['off'], self::notice($page));
        self::assertSame(0, (int) self::$db->value("SELECT is_active FROM reason_code WHERE code = 'damp'"));
        self::assertStringContainsString(Words::CONFIG_ACTION['switch_off'], $page->text());
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM reason_code WHERE code = 'damp'"), 'never deleted');
        $locked = $admin->get('/ui/reference/reasons/reason', ['code' => 'review_rejected']);
        self::assertSame(200, $locked->status, $locked->describe());
        self::assertStringContainsString(Words::REASONS_EDIT['locked'], $locked->text());
        self::assertFalse($locked->hasForm('/ui/reference/reasons/reason'));
        self::assertSame(404, $admin->get('/ui/reference/reasons/reason', ['code' => 'nothing'])->status);
        self::assertPhoneTables($admin->get('/ui/reference/reasons'));
        self::assertPhoneTables($page);
    }

    public function testWarehousesAndPlacesOnTheScreens(): void
    {
        $buyer = $this->signIn($this->uiUser('buyer'));
        $list = $buyer->get('/ui/reference/warehouses');
        self::assertSame(200, $list->status, $list->describe());
        self::assertStringContainsString('Main warehouse', $list->text());
        self::assertFalse($list->hasForm('/ui/reference/warehouses'), 'a buyer looks');
        $admin = $this->signIn($this->uiUser('admin'));
        $csrf = $this->token($admin);
        $r = $admin->post('/ui/reference/warehouses', ['csrf' => $csrf, 'code' => 'VPG2', 'name' => 'VPG 2 room', 'owner' => 'other', 'owner_name' => 'VPG 2',
            'reason' => 'stock of the other account']);
        self::assertSame(303, $r->status, $r->describe());
        $page = $admin->follow($r);
        self::assertSame(Words::WAREHOUSE_NOTICE['added'], self::notice($page));
        self::assertStringContainsString(sprintf(Words::WAREHOUSES['theirs'], 'VPG 2'), $page->text());
        self::assertSame(0, (new \DOMXPath($page->dom()))->query('//input[@name="do" and @value="sellable"]')->length, 'another account\'s stock: never sold from');
        $bad = $admin->post('/ui/reference/warehouses', ['csrf' => $csrf, 'code' => 'ROOM3', 'name' => 'Room 3', 'owner' => 'own', 'sellable' => '1',
            'reason' => 'no tick']);
        self::assertSame([422, 'confirm_needed'], [$bad->status, $bad->errorCode()]);
        self::assertSame('Room 3', $bad->form('/ui/reference/warehouses')['name'], 'what was typed is kept');
        $main = self::warehouseId('MAIN');
        $mainPage = $admin->get('/ui/reference/warehouses/' . $main);
        self::assertStringContainsString(Words::WAREHOUSES['built_in'], $mainPage->text());
        self::assertSame(0, (new \DOMXPath($mainPage->dom()))->query('//input[@name="do" and @value="switch_off"]')->length, 'a system warehouse never goes off');
        // A place inside the main warehouse (the owner's overflow room), optional.
        $r = $admin->post('/ui/reference/warehouses/' . $main, ['csrf' => $csrf, 'do' => 'place_add', 'code' => 'OVERFLOW', 'name' => 'Overflow room', 'note' => '',
            'reason' => 'part of the main stock']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringEndsWith('#places', (string) $r->location());
        $mainPage = $admin->follow($r);
        self::assertSame(Words::WAREHOUSE_NOTICE['place_added'], self::notice($mainPage));
        self::assertStringContainsString('Overflow room', $mainPage->text());
        // Renamed with a reason; a stale form is refused.
        $r = $admin->post('/ui/reference/warehouses/' . $main, ['csrf' => $csrf, 'do' => 'rename', 'seen' => '1', 'name' => 'Main warehouse (Vape and Go)', 'note' => '',
            'reason' => 'a clearer name']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringStartsWith('Main warehouse (Vape and Go)', self::h1($admin->follow($r)));
        $stale = $admin->post('/ui/reference/warehouses/' . $main, ['csrf' => $csrf, 'do' => 'rename', 'seen' => '1', 'name' => 'Old page', 'note' => '',
            'reason' => 'from an old page']);
        self::assertSame([409, 'changed_meanwhile'], [$stale->status, $stale->errorCode()]);
        // Only an empty warehouse goes off: the VPG 2 room holds stock.
        $vpg2 = self::warehouseId('VPG2');
        $this->book('goods_in', $this->item(), 4, 'VPG2');
        $full = $admin->get('/ui/reference/warehouses/' . $vpg2);
        self::assertStringContainsString(sprintf(Words::WAREHOUSES['not_empty'], Words::WHY_NOT_EMPTY['stock']), $full->text());
        self::assertSame(0, (new \DOMXPath($full->dom()))->query('//input[@name="do" and @value="switch_off"]')->length);
        $refused = $admin->post('/ui/reference/warehouses/' . $vpg2, ['csrf' => $csrf, 'do' => 'switch_off', 'seen' => '1', 'confirm' => '1', 'reason' => 'not yet']);
        self::assertSame([409, 'warehouse_not_empty'], [$refused->status, $refused->errorCode()]);
        self::assertStringContainsString(sprintf(Words::CONFIG_ERROR['warehouse_not_empty'], Words::WHY_NOT_EMPTY['stock']), $refused->text());
        // Whose stock never changes while it holds stock (I4): no form, and a POST is refused in words; nothing becomes ours.
        self::assertStringContainsString(sprintf(Words::WAREHOUSES['owner_not_empty'], Words::WHY_NOT_EMPTY['stock']), $full->text());
        self::assertSame(0, (new \DOMXPath($full->dom()))->query('//input[@name="do" and @value="owner"]')->length);
        $ours = $admin->post('/ui/reference/warehouses/' . $vpg2, ['csrf' => $csrf, 'do' => 'owner', 'seen' => '1', 'owner' => 'own', 'owner_name' => '',
            'confirm' => '1', 'reason' => 'make it ours']);
        self::assertSame([409, 'owner_not_empty'], [$ours->status, $ours->errorCode()]);
        self::assertStringContainsString(sprintf(Words::CONFIG_ERROR['owner_not_empty'], Words::WHY_NOT_EMPTY['stock']), $ours->text());
        self::assertSame('other', self::$db->value('SELECT stock_owner FROM warehouse WHERE id = ?', [$vpg2]));
        // An empty room goes off with its tick, and on again.
        $r = $admin->post('/ui/reference/warehouses', ['csrf' => $csrf, 'code' => 'ROOM3', 'name' => 'Room 3', 'owner' => 'own', 'reason' => 'a spare room']);
        self::assertSame(303, $r->status, $r->describe());
        $room = self::warehouseId('ROOM3');
        $noTick = $admin->post('/ui/reference/warehouses/' . $room, ['csrf' => $csrf, 'do' => 'switch_off', 'seen' => '1', 'reason' => 'not used']);
        self::assertSame([422, 'unconfirmed'], [$noTick->status, $noTick->errorCode()]);
        $r = $admin->post('/ui/reference/warehouses/' . $room, ['csrf' => $csrf, 'do' => 'switch_off', 'seen' => '1', 'confirm' => '1', 'reason' => 'not used']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(Words::WAREHOUSE_NOTICE['off'], self::notice($admin->follow($r)));
        self::assertSame(403, $buyer->post('/ui/reference/warehouses/' . $room, ['csrf' => $this->token($buyer), 'do' => 'switch_on', 'seen' => '2',
            'reason' => 'buyers may not'])->status);
        $r = $admin->post('/ui/reference/warehouses/' . $room, ['csrf' => $csrf, 'do' => 'switch_on', 'seen' => '2', 'reason' => 'in use again']);
        self::assertSame(Words::WAREHOUSE_NOTICE['on'], self::notice($admin->follow($r)));
        // An empty room's owner changes with its tick only.
        $emptyRoom = $admin->get('/ui/reference/warehouses/' . $room);
        self::assertTrue($emptyRoom->hasForm('/ui/reference/warehouses/' . $room));
        self::assertStringContainsString(Words::WAREHOUSES['owner_confirm'], $emptyRoom->text());
        $noTick = $admin->post('/ui/reference/warehouses/' . $room, ['csrf' => $csrf, 'do' => 'owner', 'seen' => '3', 'owner' => 'other', 'owner_name' => 'VPG 2',
            'reason' => 'their room now']);
        self::assertSame([422, 'unconfirmed'], [$noTick->status, $noTick->errorCode()]);
        $r = $admin->post('/ui/reference/warehouses/' . $room, ['csrf' => $csrf, 'do' => 'owner', 'seen' => '3', 'owner' => 'other', 'owner_name' => 'VPG 2',
            'confirm' => '1', 'reason' => 'their room now']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('other', self::$db->value('SELECT stock_owner FROM warehouse WHERE id = ?', [$room]));
        self::assertPhoneTables($admin->get('/ui/reference/warehouses'));
        self::assertPhoneTables($admin->get('/ui/reference/warehouses/' . $main));
    }

    public function testWebsitesSafetyChecksTheAuditLogAndWhoCanDoWhat(): void
    {
        $this->site('vpg', 'shadow');
        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertSame(403, $buyer->get('/ui/system/sites')->status);
        self::assertSame(403, $buyer->get('/ui/system/checks')->status);
        self::assertSame(403, $buyer->get('/ui/system/audit')->status);
        self::assertSame(403, $buyer->get('/ui/system/audit.csv')->status);
        $access = $buyer->get('/ui/reference/access');
        self::assertSame(200, $access->status, $access->describe());
        self::assertStringContainsString(Words::PERMISSION['settings.manage'], $access->text());
        self::assertStringContainsString(Words::ACCESS['rule_own'], $access->text());
        self::assertPhoneTables($access);

        $rev = $this->uiUser('reviewer');
        $web = $this->signIn($rev);
        $sites = $web->get('/ui/system/sites');
        self::assertSame(200, $sites->status, $sites->describe());
        self::assertStringContainsString('VPG test site', $sites->text());
        self::assertStringContainsString(Words::MODE['shadow'], $sites->text());
        self::assertStringContainsString(Words::SITES['no_contact_ever'], $sites->text(), 'a website watching that never called');
        $xp = new \DOMXPath($sites->dom());
        self::assertSame(0, $xp->query('//main//form')->length, 'the page changes nothing');
        self::assertSame(0, $xp->query('//main//code[contains(., "bin/channel_set.php")][not(ancestor::details)]')->length, 'commands only in the folded details');
        self::assertGreaterThan(0, $xp->query('//main//details//code[contains(., "php bin/channel_set.php --code=vpg --warehouse=")]')->length);

        // The safety checks: none ran yet; a failed run shows on the page and as a Home card (not a buyer's).
        self::assertStringContainsString(Words::INTEGRITY['none'], $web->get('/ui/system/checks')->text());
        self::assertSame(0, (new \DOMXPath($web->get('/ui/')->dom()))->query('//*[@data-card="integrity"]')->length);
        (new IntegrityRuns(self::$db))->record(gmdate('Y-m-d H:i:s'), ['balance 1:5 on_hand=7 but its ledger sums to 5', 'unit 1:a is held but has no ledger rows'],
            ['ms' => 1200], 'system:invariants');
        $checks = $web->get('/ui/system/checks');
        self::assertSame(200, $checks->status, $checks->describe());
        self::assertStringContainsString(sprintf(Words::INTEGRITY['result_bad'], '2'), $checks->text());
        self::assertStringContainsString('balance 1:5 on_hand=7 but its ledger sums to 5', $checks->text());
        self::assertSame(1, (new \DOMXPath($web->get('/ui/')->dom()))->query('//*[@data-card="integrity"]')->length);
        self::assertSame(0, (new \DOMXPath($buyer->get('/ui/')->dom()))->query('//*[@data-card="integrity"]')->length, 'not a buyer\'s');

        // The audit log: a search, a refused search, the CSV file.
        (new Settings(self::$db))->change(Caller::staff($rev['id']), 'reorder.default_safety_days', '6', 'audit me');
        $log = $web->get('/ui/system/audit', ['action' => 'setting']);
        self::assertSame(200, $log->status, $log->describe());
        self::assertStringContainsString(Words::AUDIT_ACTION['setting.change'], $log->text());
        self::assertStringContainsString('Reviewer', $log->text());
        $csvLinks = array_values(array_filter($log->hrefs(), static fn (string $h): bool => str_starts_with($h, '/ui/system/audit.csv?')));
        self::assertCount(1, $csvLinks);
        self::assertStringContainsString('action=setting', $csvLinks[0], 'the file holds the same search');
        self::assertPhoneTables($log);
        $bad = $web->get('/ui/system/audit', ['from' => 'yesterday']);
        self::assertSame(400, $bad->status);
        $csv = $web->get('/ui/system/audit.csv', ['action' => 'setting']);
        self::assertSame(200, $csv->status, $csv->describe());
        self::assertStringStartsWith('attachment', (string) $csv->header('content-disposition'));
        self::assertStringContainsString('setting.change', $csv->body);
        self::assertStringContainsString('audit me', $csv->body);
    }

    /**
     * A person added on the screen (Y40-Y43): the sheet (QR code, key, set-up code, the configured sign-in address) once; /ui/enrol
     * takes the e-mail, the set-up code and the sheet's code and leads to the person's OWN page with a fresh code (only that browser,
     * no-store), which they confirm while choosing their password. A new password and a new sign-in code work the same way; each of
     * those forms runs once (M4).
     */
    public function testAPersonIsAddedWithASheetAndFinishesOnTheirOwnPage(): void
    {
        $admin = $this->uiUser('admin');
        (new Settings(self::$db))->change(Caller::system('settings'), 'staff.sign_in_address', 'https://warehouse.example.com', 'the address staff open');
        $web = $this->signIn($admin);
        self::assertTrue($web->get('/ui/people')->hasForm('/ui/people'));
        $csrf = $this->token($web);
        $sheet = $web->post('/ui/people', ['csrf' => $csrf, 'name' => 'Sam Sales', 'email' => 'sam@test.example', 'role_buyer' => '1']);
        self::assertSame(200, $sheet->status, $sheet->describe());
        self::assertSame('no-store', $sheet->header('cache-control'), 'the secret is never kept by a browser or a proxy');
        self::assertSame(sprintf(Words::SHEET['title_signup'], 'Sam Sales'), self::h1($sheet));
        $xp = new \DOMXPath($sheet->dom());
        self::assertGreaterThan(200, $xp->query('//div[@class="qr"]//i[@class="d"]')->length, 'the QR code: one element per dark module');
        self::assertSame(0, $xp->query('//img | //svg')->length);
        $sam = (int) self::$db->value("SELECT id FROM staff_user WHERE email = 'sam@test.example'");
        $secret = self::$box->decrypt((string) self::$db->value('SELECT totp_secret_enc FROM staff_user WHERE id = ?', [$sam]));
        self::assertSame($secret, str_replace(' ', '', trim((string) $xp->evaluate('string(//p[@class="setup-key"])'))), 'the setup key, shown this once');
        $setupCode = trim((string) $xp->evaluate('string(//p[contains(@class, "setup-code")])'));
        self::assertMatchesRegularExpression('/^[0-9A-Z]{5}-[0-9A-Z]{5}-[0-9A-Z]{5}$/', $setupCode);
        self::assertStringContainsString('https://warehouse.example.com/ui/enrol', $sheet->text(), 'the configured address, never the Host header');
        self::assertStringNotContainsString('cw-ui.review.invalid', $sheet->text());
        self::assertStringNotContainsString($secret, $web->get('/ui/people')->body, 'never shown again');
        $person = $web->get('/ui/people/' . $sam);
        self::assertStringNotContainsString($secret, $person->body);
        self::assertStringNotContainsString($setupCode, $person->body);
        self::assertStringContainsString(self::lead(Words::STAFF['setup_open']), $person->text());
        self::assertTrue($person->hasForm('/ui/people/' . $sam . '/sheet'), 'never finished: a new sign-up sheet, not a reset');
        self::assertFalse($person->hasForm('/ui/people/' . $sam . '/authenticator'));
        $again = $web->post('/ui/people', ['csrf' => $csrf, 'name' => 'Sam Sales', 'email' => 'sam@test.example', 'role_buyer' => '1']);
        self::assertSame(409, $again->status, 'a form sent twice adds nobody twice');
        self::assertStringContainsString(Words::STAFF['staff_exists'], $again->text());

        // Sam opens /ui/enrol on their phone: a wrong code first, then the right answer leads to Sam's own page (no session yet).
        $phone = $this->browser('198.51.100.90');
        $form = $phone->get('/ui/enrol');
        self::assertSame(200, $form->status, $form->describe());
        $fields = $form->form('/ui/enrol');
        self::assertNotSame('', $fields['csrf'] ?? '');
        $wrong = $phone->post('/ui/enrol', ['email' => 'sam@test.example', 'setup_code' => $setupCode, 'code' => '000000'] + $fields);
        self::assertSame(401, $wrong->status);
        self::assertStringContainsString(Words::ENROL['failed'], $wrong->text());
        $next = $phone->post('/ui/enrol', ['email' => 'sam@test.example', 'setup_code' => strtolower($setupCode), 'code' => Totp::code($secret)] + $fields);
        self::assertSame([303, '/ui/new-code'], [$next->status, $next->location()], $next->describe());
        self::assertArrayHasKey('cw_setup', $phone->cookies);
        self::assertArrayNotHasKey('cw_session', $phone->cookies, 'no session before the fresh code is confirmed');
        $own = $phone->get('/ui/new-code');
        self::assertSame(200, $own->status, $own->describe());
        self::assertSame('no-store', $own->header('cache-control'));
        self::assertSame(Words::PAGE_TITLE['new_code'], self::h1($own));
        $fresh = str_replace(' ', '', trim((string) (new \DOMXPath($own->dom()))->evaluate('string(//p[@class="setup-key"])')));
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $fresh);
        self::assertNotSame($secret, $fresh, 'a FRESH code, which the admin never saw');
        self::assertSame(410, $this->browser('198.51.100.92')->get('/ui/new-code')->status, 'no other browser has this page');
        $nf = $own->form('/ui/new-code');
        self::assertSame(422, $phone->post('/ui/new-code', ['code' => Totp::code($fresh), 'new' => 'short', 'again' => 'short'] + $nf)->status);
        $bad = $phone->post('/ui/new-code', ['code' => Totp::code($secret), 'new' => 'a-good-long-password', 'again' => 'a-good-long-password'] + $nf);
        self::assertSame(401, $bad->status, 'the sheet\'s code is not the fresh one');
        self::assertStringContainsString(Words::NEW_CODE['failed'], $bad->text());
        $ok = $phone->post('/ui/new-code', ['code' => Totp::code($fresh), 'new' => 'a-good-long-password', 'again' => 'a-good-long-password'] + $nf);
        self::assertSame([303, '/ui/?notice=signed_in_new_code'], [$ok->status, $ok->location()], $ok->describe());
        self::assertArrayNotHasKey('cw_setup', $phone->cookies);
        self::assertSame(Words::NEW_CODE['done'], self::notice($phone->follow($ok)));
        self::assertNull(self::$db->value('SELECT totp_next_token FROM staff_user WHERE id = ?', [$sam]), 'the step is used once');

        // A new password for Sam (a tick first; the form runs once): a sheet with a set-up code only; Sam finishes with their phone.
        $person = $web->get('/ui/people/' . $sam);
        self::assertStringContainsString(self::lead(Words::STAFF['set_up_at']), $person->text(), 'when and from where Sam set up');
        $pwForm = $person->form('/ui/people/' . $sam . '/password');
        self::assertSame(422, $web->post('/ui/people/' . $sam . '/password', ['confirm' => ''] + $pwForm)->status);
        $pwSheet = $web->post('/ui/people/' . $sam . '/password', ['confirm' => '1'] + $pwForm);
        self::assertSame(200, $pwSheet->status, $pwSheet->describe());
        self::assertSame(sprintf(Words::SHEET['title_password'], 'Sam Sales'), self::h1($pwSheet));
        self::assertSame(0, (new \DOMXPath($pwSheet->dom()))->query('//div[@class="qr"]')->length, 'no QR code: Sam keeps their phone');
        $pwCode = trim((string) (new \DOMXPath($pwSheet->dom()))->evaluate('string(//p[contains(@class, "setup-code")])'));
        $twice = $web->post('/ui/people/' . $sam . '/password', ['confirm' => '1'] + $pwForm);
        self::assertSame('/ui/people/' . $sam . '?notice=sheet_shown', $twice->location(), 'sent again: nothing new is made');
        self::assertSame(Words::STAFF_NOTICE['sheet_shown'], self::notice($web->follow($twice)));
        self::assertSame(hash('sha256', str_replace('-', '', $pwCode)), self::$db->value('SELECT setup_code_hash FROM staff_user WHERE id = ?', [$sam]),
            'the first sheet\'s code is still the one');
        self::assertSame('/ui/login?why=signed_out', $phone->get('/ui/')->location(), 'signed out everywhere');
        // While Sam may choose a password, no new sign-in code (the admin would hold both).
        self::assertStringContainsString(self::lead(Words::STAFF['code_blocked']), $web->get('/ui/people/' . $sam)->text());
        self::$db->exec('UPDATE staff_user SET totp_last_step = NULL WHERE id = ?', [$sam]);
        $phone = $this->browser('198.51.100.91');
        $fields = $phone->get('/ui/enrol')->form('/ui/enrol');
        $next = $phone->post('/ui/enrol', ['email' => 'sam@test.example', 'setup_code' => $pwCode, 'code' => Totp::code($fresh)] + $fields);
        self::assertSame('/ui/new-code', $next->location(), $next->describe());
        $own = $phone->get('/ui/new-code');
        $fresh2 = str_replace(' ', '', trim((string) (new \DOMXPath($own->dom()))->evaluate('string(//p[@class="setup-key"])')));
        $ok = $phone->post('/ui/new-code', ['code' => Totp::code($fresh2), 'new' => 'another-long-password', 'again' => 'another-long-password']
            + $own->form('/ui/new-code'));
        self::assertSame(303, $ok->status, $ok->describe());

        // A device signed out from the list.
        $devices = $web->get('/ui/people');
        $handle = (string) (new \DOMXPath($devices->dom()))->evaluate('string(//form[contains(@action, "/ui/people/' . $sam . '/sign-out")]//input[@name="session"]/@value)');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $handle);
        self::assertPhoneTables($devices);
        $r = $web->post('/ui/people/' . $sam . '/sign-out', ['csrf' => $csrf, 'session' => $handle, 'back' => 'list']);
        self::assertSame('/ui/people?notice=signed_out', $r->location());
        self::assertSame('/ui/login?why=signed_out', $phone->get('/ui/')->location());
        $r = $web->post('/ui/people/' . $sam . '/sign-out', ['csrf' => $csrf, 'session' => $handle]);
        self::assertSame('/ui/people/' . $sam . '?notice=not_signed_in', $r->location(), 'once is enough');

        // A new sign-in code (a tick first, then the sheet once; sent again: nothing new); it works only with Sam's password, at the sign-in.
        $codeForm = $web->get('/ui/people/' . $sam)->form('/ui/people/' . $sam . '/authenticator');
        self::assertSame(422, $web->post('/ui/people/' . $sam . '/authenticator', ['confirm' => ''] + $codeForm)->status);
        $sheet = $web->post('/ui/people/' . $sam . '/authenticator', ['confirm' => '1'] + $codeForm);
        self::assertSame(200, $sheet->status, $sheet->describe());
        self::assertSame(sprintf(Words::SHEET['title_code'], 'Sam Sales'), self::h1($sheet));
        self::assertStringContainsString(sprintf(Words::SHEET['step_signin'], 'https://warehouse.example.com/ui/login'), $sheet->text());
        $newSecret = self::$box->decrypt((string) self::$db->value('SELECT totp_secret_enc FROM staff_user WHERE id = ?', [$sam]));
        self::assertNotSame($fresh2, $newSecret);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.reset' AND entity_id = ? AND JSON_EXTRACT(detail, '$.new_totp') = true",
            [(string) $sam]));
        $twice = $web->post('/ui/people/' . $sam . '/authenticator', ['confirm' => '1'] + $codeForm);
        self::assertSame('/ui/people/' . $sam . '?notice=sheet_shown', $twice->location(), 'M4: the same form again makes no second secret');
        self::assertSame($newSecret, self::$box->decrypt((string) self::$db->value('SELECT totp_secret_enc FROM staff_user WHERE id = ?', [$sam])));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.reset' AND entity_id = ? AND JSON_EXTRACT(detail, '$.new_totp') = true",
            [(string) $sam]));
        $phone = $this->browser('198.51.100.93');
        $login = $phone->get('/ui/login')->form('/ui/login');
        $in = $phone->post('/ui/login', ['email' => 'sam@test.example', 'password' => 'another-long-password', 'code' => Totp::code($newSecret)] + $login);
        self::assertSame([303, '/ui/new-code'], [$in->status, $in->location()], $in->describe());
        self::assertArrayNotHasKey('cw_session', $phone->cookies);
        $own = $phone->get('/ui/new-code');
        self::assertSame(0, (new \DOMXPath($own->dom()))->query('//input[@name="new"]')->length, 'no password here: Sam keeps theirs');
        $fresh3 = str_replace(' ', '', trim((string) (new \DOMXPath($own->dom()))->evaluate('string(//p[@class="setup-key"])')));
        $ok = $phone->post('/ui/new-code', ['code' => Totp::code($fresh3)] + $own->form('/ui/new-code'));
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame(200, $phone->get('/ui/')->status);
        self::assertSame(403, $web->post('/ui/people/' . $admin['id'] . '/authenticator', ['csrf' => $csrf, 'confirm' => '1', 'form_key' => str_repeat('a', 32)])->status,
            'never one\'s own');
        self::assertPhoneTables($web->get('/ui/people/' . $sam));
        self::assertPhoneTables($own);
    }

    public function testAGrantOfReviewerWaitsForAReviewersOkWhileTheRuleIsOn(): void
    {
        $admin = $this->uiUser('admin');
        (new Settings(self::$db))->change(Caller::staff($admin['id']), 'approvals.staff_grant', 'true', 'the owner wants a second person');
        $p = $this->uiUser('buyer');
        $rev = $this->uiUser('reviewer');
        $web = $this->signIn($admin);
        $form = $web->get('/ui/people/' . $p['id'])->form('/ui/people/' . $p['id'] . '/roles');
        $r = $web->post('/ui/people/' . $p['id'] . '/roles', ['role_reviewer' => '1'] + $form);
        self::assertSame('/ui/people/' . $p['id'] . '?notice=requested', $r->location(), $r->describe());
        $page = $web->follow($r);
        self::assertSame(Words::STAFF_NOTICE['requested'], self::notice($page));
        self::assertTrue($page->hasForm('/ui/people/' . $p['id'] . '/request/withdraw'));
        self::assertSame(['buyer'], StaffRoles::active(self::$db, $p['id']), 'nothing changes until a reviewer says OK');
        self::assertSame(403, $web->get('/ui/staff-requests')->status, 'the admin never gives the OK');
        $reviewer = $this->signIn($rev);
        self::assertSame(1, (new \DOMXPath($reviewer->get('/ui/')->dom()))->query('//*[@data-card="staff_requests"]')->length);
        $list = $reviewer->get('/ui/staff-requests');
        self::assertSame(200, $list->status, $list->describe());
        // Who the person is (I1): their e-mail, when the account was made, whether and how they set up their sign-in.
        foreach ([Words::STAFF_REQUESTS['email'], $p['email'], Words::STAFF_REQUESTS['made'], Words::STAFF_REQUESTS['set_up'], Words::STAFF_REQUESTS['set_up_server']] as $text) {
            self::assertStringContainsString($text, $list->text());
        }
        $id = (int) self::$db->value("SELECT id FROM staff_role_request WHERE state = 'open'");
        self::assertTrue($list->hasForm('/ui/staff-requests/' . $id . '/approve'));
        self::assertPhoneTables($list);
        $r = $reviewer->post('/ui/staff-requests/' . $id . '/approve', ['csrf' => $this->token($reviewer)]);
        self::assertSame('/ui/staff-requests?notice=approved', $r->location(), $r->describe());
        $after = $reviewer->follow($r);
        self::assertSame(Words::STAFF_NOTICE['request_approved'], self::notice($after));
        self::assertStringContainsString(Words::STAFF_REQUESTS['none'], $after->text());
        $roles = StaffRoles::active(self::$db, $p['id']);
        sort($roles);
        self::assertSame(['buyer', 'reviewer'], $roles);
    }

    /**
     * Review finding I1 (Y45): a reviewer's Home lists what the admins did to staff access and the approval rules lately: every person
     * added, every sign-in reset and every rule made looser, one line each, with who and when.
     */
    public function testAReviewersHomeListsTheStaffAndRuleChangesOfTheLastDays(): void
    {
        $admin = $this->uiUser('admin');
        $owner = $this->uiUser('reviewer');
        $svc = new \CW\Staff\StaffAdmin(self::$appDb);
        $made = $svc->enrol(Caller::staff($admin['id']), 'kim@test.example', ['buyer'], self::$box, 'Kim Stock');
        $svc->newSheet(Caller::staff($admin['id']), $made['id'], self::$box);
        $settings = new Settings(self::$db);
        $settings->change(Caller::staff($owner['id']), 'approvals.match_counted', 'false', 'one lead is enough'); // looser: a reviewer
        $settings->change(Caller::staff($admin['id']), 'approvals.staff_grant', 'true', 'two people for Admin'); // stricter: not listed
        $home = $this->signIn($owner)->get('/ui/');
        $card = (new \DOMXPath($home->dom()))->query('//*[@data-card="watch"]')->item(0);
        self::assertNotNull($card, $home->describe());
        $text = (string) preg_replace('/\s+/u', ' ', (string) $card->textContent);
        self::assertStringContainsString(Words::TASK['watch']['title'], $text);
        self::assertStringContainsString('Kim Stock', $text, 'the person added and reset');
        self::assertStringContainsString(Words::RULE['approvals.match_counted']['title'], $text, 'the rule made looser');
        self::assertStringNotContainsString(Words::RULE['approvals.staff_grant']['title'], $text, 'a stricter rule is not listed');
        self::assertSame(5, (new \DOMXPath($home->dom()))->query('//*[@data-card="watch"]//ul[@class="task-lines"]/li')->length,
            'Kim added and reset, the rule loosened, and the two accounts this test made on the server ("the server" added them)');
        self::assertStringContainsString(Words::WATCH['server'], $text);
        // The admin's Home has no such card (it is about them).
        self::assertSame(0, (new \DOMXPath($this->signIn($admin)->get('/ui/')->dom()))->query('//*[@data-card="watch"]')->length);
    }

    /** A reviewer's menu (the owner's job): every new Settings page, each with its heading and an intro without code words. */
    public function testTheReviewersMenuHasEveryNewPage(): void
    {
        $web = $this->signIn($this->uiUser('reviewer'));
        self::assertSame(['/ui/reference/company', '/ui/reference/warehouses', '/ui/system/sites', '/ui/reference/access', '/ui/reference/approvals',
            '/ui/reference/reasons', '/ui/reference/settings'], array_column(self::sectionTabs($web->get('/ui/reference/company')), 'href'));
        self::assertSame(['Settings', 'Numbering', 'System checks'], array_column(self::segments($web->get('/ui/system/checks')), 'label'));
        self::assertSame([['Audit Log', '/ui/system/audit', true]], array_values(array_filter(array_map(static fn (array $t): array => [$t['label'], $t['href'], $t['current']],
            self::sectionTabs($web->get('/ui/system/audit'))), static fn (array $t): bool => $t[1] !== null)), 'Reports › Audit Log; the store reports are "Soon"');
        foreach (['/ui/reference/approvals', '/ui/reference/warehouses', '/ui/system/sites', '/ui/system/checks', '/ui/system/audit', '/ui/reference/access',
            '/ui/reference/reasons'] as $path) {
            $r = $web->get($path);
            self::assertSame(200, $r->status, $path . ' ' . $r->describe());
            self::assertNotSame('', self::h1($r), $path);
            self::assertDoesNotMatchRegularExpression('/\b[a-z]+_[a-z_]+\b/', (string) (new \DOMXPath($r->dom()))->evaluate('string(//main//p[contains(@class, "lede")])'),
                $path . ': no code words in the intro');
        }
    }
}
