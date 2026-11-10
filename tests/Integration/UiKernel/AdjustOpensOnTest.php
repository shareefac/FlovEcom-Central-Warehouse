<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Admin\ApprovalRules;
use CW\Admin\ReasonCodes;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Settings;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The reason a new adjustment opens on is a setting (0065; docs/decisions.md AJ9-AJ13; tracker CW-920 part 2, step 2), through the real
 * kernel as the app login: as set up it is Data correction (as before); with "Count difference" offered on stock corrections (Reasons) and
 * chosen here the page opens on it and an everyday count is made final with no note; a reason switched off or no longer offered opens on
 * Data correction again; "None" chooses nothing; what was typed and a draft's own reason still win. On the Settings screens it is a choice
 * of the reasons offered on stock corrections (never a code to type), shown and kept in its History by the reason's name.
 */
final class AdjustOpensOnTest extends KernelUiTestCase
{
    private const KEY = 'stock.adjust_opens_on';
    private const NEW = '/ui/stock/adjustments/new';
    private const PAGE = '/ui/reference/settings/setting';
    private const LIST = '/ui/reference/settings';

    /** @var list<array<string, mixed>> */
    private array $settings = [];
    /** @var list<array<string, mixed>> */
    private array $reasons = [];

    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, provisional, updated_actor FROM app_setting');
        $this->reasons = self::$db->all("SELECT code, label, applies_to, is_active FROM reason_code WHERE code IN ('count_difference', 'data_correction')");
    }

    protected function tearDown(): void
    {
        // Seed rows TestDb::clean keeps: put back as they were read (their history rows of this test go with the next clean).
        foreach ($this->settings as $r) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ? WHERE setting_key = ?',
                [$r['v'], (int) $r['provisional'], $r['updated_actor'], $r['setting_key']]);
        }
        foreach ($this->reasons as $r) {
            self::$db->exec('UPDATE reason_code SET label = ?, applies_to = ?, is_active = ? WHERE code = ?', [$r['label'], $r['applies_to'], (int) $r['is_active'], $r['code']]);
        }
        parent::tearDown();
    }

    private static function sys(): Caller
    {
        return Caller::system('aor_test');
    }

    /** "Count difference" offered on stock corrections too, the way the Reasons screen does it. */
    private static function offer(): void
    {
        (new ReasonCodes(self::$db))->setUses(self::sys(), 'count_difference', ['adjustment', 'count'], 'a count is put right on Adjustments too');
    }

    private static function opensOn(string $code): void
    {
        (new Settings(self::$db))->change(self::sys(), self::KEY, $code, 'set for this test');
    }

    /** The reason the page's "Why, for every line" box holds: null when nothing is chosen ("Choose…"). */
    private static function top(UiResponse $page): ?string
    {
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $n = (new \DOMXPath($page->dom()))->query('//select[@name="why"]/option[@selected]')->item(0);
        return $n instanceof \DOMElement ? $n->getAttribute('value') : null;
    }

    /** @return array<string, string> value => words, of the options of the select at $at, in their order */
    private static function options(UiResponse $page, string $at): array
    {
        $out = [];
        foreach ((new \DOMXPath($page->dom()))->query($at . '/option') ?: [] as $o) {
            /** @var \DOMElement $o */
            $out[$o->getAttribute('value')] = trim($o->textContent);
        }
        return $out;
    }

    private static function chosen(UiResponse $page, string $at): ?string
    {
        $n = (new \DOMXPath($page->dom()))->query($at . '/option[@selected]')->item(0);
        return $n instanceof \DOMElement ? $n->getAttribute('value') : null;
    }

    private static function str(UiResponse $page, string $xpath): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) (new \DOMXPath($page->dom()))->evaluate('string(' . $xpath . ')')));
    }

    /** "Value now" on the setting's page. */
    private static function now(UiResponse $page): string
    {
        return self::str($page, '//section[contains(@class, "setting-facts")]//div[contains(@class, "pre")]');
    }

    /** @return array<string, string> code => label of the reasons offered on stock corrections, as the screens list them */
    private static function offered(): array
    {
        return array_column(self::$db->all("SELECT code, label FROM reason_code WHERE FIND_IN_SET('adjustment', applies_to) > 0 AND is_active = 1 AND system_only = 0 "
            . 'ORDER BY sort_order, code'), 'label', 'code');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $over
     */
    private function send(KernelBrowser $web, string $page, string $action, array $rows, array $over = []): UiResponse
    {
        $p = $web->get($page);
        self::assertSame(200, $p->status, $p->describe() . ' ' . implode(' | ', self::$log));
        $form = $p->form($action, true);
        self::assertNotSame([], $form, 'no form: ' . $p->describe());
        return $web->post($action, ['lines' => (string) json_encode($rows)] + $over + $form);
    }

    private static function lastDoc(): int
    {
        return (int) self::$db->value("SELECT MAX(id) FROM document WHERE doc_type = 'ADJ'");
    }

    private static function onHand(int $sku): int
    {
        return (int) self::$db->value('SELECT SUM(on_hand) FROM stock_balance WHERE sku_id = ?', [$sku]);
    }

    // ------------------------------------------------------------------------------------------
    // The adjustment page
    // ------------------------------------------------------------------------------------------

    public function testAsSetUpANewAdjustmentOpensOnDataCorrection(): void
    {
        self::assertSame('data_correction', (new Settings(self::$db))->get(self::KEY), 'the set-up value: the page opens as it did before');
        $web = $this->signIn($this->uiUser('stock_controller'));
        self::assertSame('data_correction', self::top($web->get(self::NEW)));
    }

    public function testOfferedAndChosenItOpensOnCountDifferenceAndAnEverydayCountNeedsNoNote(): void
    {
        $a = $this->item('strict', 10, 'Opens pod A');
        self::offer();
        self::opensOn('count_difference');
        $web = $this->signIn($this->uiUser('stock_controller'));
        self::assertSame('count_difference', self::top($web->get(self::NEW)));
        // "Make it final" with the page as it opened: no reason chosen by hand, no note typed.
        $r = $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '-2', 'r' => '']], ['action' => 'post']);
        self::assertSame(303, $r->status, (string) $r->errorCode() . ' ' . self::str($r, '//p[@role="alert"]'));
        $id = self::lastDoc();
        self::assertSame([['qty' => -2, 'reason_code' => 'count_difference']], self::$db->all('SELECT qty, reason_code FROM document_line WHERE document_id = ? ORDER BY line_no', [$id]));
        self::assertSame('posted', (string) self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));
        self::assertSame(8, self::onHand($a));
    }

    public function testAReasonSwitchedOffOrNoLongerOfferedOpensOnDataCorrectionAgain(): void
    {
        self::offer();
        self::opensOn('count_difference');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $reasons = new ReasonCodes(self::$db);
        $reasons->setActive(self::sys(), 'count_difference', false, 'off for a while');
        self::assertSame('data_correction', self::top($web->get(self::NEW)), 'switched off: Data correction, as before');
        $reasons->setActive(self::sys(), 'count_difference', true, 'on again');
        self::assertSame('count_difference', self::top($web->get(self::NEW)), 'on again: the setting works again, nothing to set twice');
        $reasons->setUses(self::sys(), 'count_difference', ['count'], 'for counts only again');
        self::assertSame('data_correction', self::top($web->get(self::NEW)), 'no longer offered on stock corrections');
        $reasons->setActive(self::sys(), 'data_correction', false, 'off too');
        self::assertNull(self::top($web->get(self::NEW)), 'neither is offered: the person chooses');
    }

    public function testNoneMeansThePersonChoosesEachTime(): void
    {
        $a = $this->item('strict', 10, 'Opens pod B');
        self::opensOn('');
        self::assertSame('', (new Settings(self::$db))->get(self::KEY));
        $web = $this->signIn($this->uiUser('stock_controller'));
        self::assertNull(self::top($web->get(self::NEW)), 'nothing chosen: "Choose…", not Data correction');
        $r = $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '+1', 'r' => '']], ['why' => 'data_correction', 'note' => 'Counted', 'action' => 'post']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(11, self::onHand($a), 'a reason chosen by hand works as it did');
    }

    public function testWhatWasTypedAndADraftsOwnReasonStillWin(): void
    {
        $a = $this->item('strict', 10, 'Opens pod C');
        self::offer();
        self::opensOn('count_difference');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $r = $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '-4', 'r' => '']], ['why' => 'data_correction', 'action' => 'save']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('data_correction', self::top($web->get('/ui/stock/adjustments/' . self::lastDoc())), 'a draft opens on its own first reason');
        $bad = $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => 'ten', 'r' => '']], ['why' => 'damaged', 'action' => 'save']);
        self::assertSame(422, $bad->status, $bad->describe());
        $n = (new \DOMXPath($bad->dom()))->query('//select[@name="why"]/option[@selected]')->item(0);
        self::assertSame('damaged', $n instanceof \DOMElement ? $n->getAttribute('value') : null, 'a refused form keeps the reason that was chosen');
    }

    public function testARefusedFormKeepsNoReasonChosenWhenThatIsWhatItSent(): void
    {
        // Codex on ca9de9e (LOW, old): "Choose…" picked by hand as the reason for every line, then a refusal: the page chose a reason again
        // by itself (Data correction before the setting, the setting's reason with it), and the next Save would have used it.
        $a = $this->item('strict', 10, 'Opens pod D');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $why = '//select[@name="why"]';
        $bad = $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => 'ten', 'r' => 'damaged']], ['why' => '', 'action' => 'save']);
        self::assertContains($bad->status, [400, 422], $bad->describe());
        self::assertSame(1, (new \DOMXPath($bad->dom()))->query($why)->length, 'the refused form is the page again');
        self::assertNull(self::chosen($bad, $why), 'a new record: "Choose…" was sent, "Choose…" is kept');
        // The same on a draft's own page.
        $ok = $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '-1', 'r' => '']], ['why' => 'damaged', 'note' => 'Dropped', 'action' => 'save']);
        self::assertSame(303, $ok->status, $ok->describe());
        $page = '/ui/stock/adjustments/' . self::lastDoc();
        self::assertSame('damaged', self::top($web->get($page)));
        $bad = $this->send($web, $page, $page . '/grid', [['sku' => $a, 'u' => 'ten', 'r' => 'damaged']], ['why' => '']);
        self::assertContains($bad->status, [400, 422], $bad->describe());
        self::assertSame(1, (new \DOMXPath($bad->dom()))->query($why)->length, 'the refused form is the draft\'s page again');
        self::assertNull(self::chosen($bad, $why), 'a draft: the same');
    }

    // ------------------------------------------------------------------------------------------
    // The Settings screens
    // ------------------------------------------------------------------------------------------

    public function testOnItsPageTheSettingIsAChoiceOfTheReasonsOfferedOnStockCorrections(): void
    {
        $select = '//form[@action="' . self::PAGE . '"]//select[@name="value"]';
        $hint = sprintf(Words::SETTING_EDIT['reason_use'], Words::REASON_USE['adjustment']);
        $buyer = $this->signIn($this->uiUser('buyer'));
        $look = $buyer->get(self::PAGE, ['key' => self::KEY]);
        self::assertSame(200, $look->status, $look->describe());
        self::assertSame('Data correction', self::now($look), 'the value is shown by the reason\'s name, not its code');
        self::assertFalse($look->hasForm(self::PAGE), 'a buyer looks');

        $web = $this->signIn($this->uiUser('reviewer'));
        $page = $web->get(self::PAGE, ['key' => self::KEY]);
        self::assertSame(['' => Words::SETTING_EDIT['reason_none']] + self::offered(), self::options($page, $select), 'None, then the reasons offered, in the Reasons order');
        self::assertArrayNotHasKey('count_difference', self::options($page, $select), 'offered on counts only: not a choice yet');
        self::assertSame('data_correction', self::chosen($page, $select));
        self::assertSame($hint, self::str($page, $select . '/preceding-sibling::span[contains(@class, "hint")]'), 'the hint says where the choices come from, and nothing about typing');
        $form = $page->form(self::PAGE, true);
        self::assertSame([self::KEY, '1', 'data_correction'], [$form['key'], $form['seen'], $form['value']]);

        // A reason not offered on stock corrections, and a code that is no reason: refused, kept on the page and said.
        foreach (['count_difference' => 'Count difference', 'no_such_reason' => 'no_such_reason'] as $code => $shown) {
            $bad = $web->post(self::PAGE, ['value' => $code, 'reason' => 'for the counters'] + $form);
            self::assertSame([400, 'bad_value'], [$bad->status, $bad->errorCode()], $code);
            self::assertSame(Words::CONFIG_ERROR['bad_value'] . ' ' . $hint, self::str($bad, '//p[@role="alert"]'));
            self::assertSame($code, self::chosen($bad, $select), 'what was sent is kept');
            self::assertSame(sprintf(Words::SETTING_EDIT['reason_gone'], $shown), self::options($bad, $select)[$code] ?? null);
        }
        self::assertSame('data_correction', (new Settings(self::$db))->get(self::KEY), 'nothing saved');

        // Offered on stock corrections (Reasons): now a choice; chosen with a reason, kept in the History by name.
        self::offer();
        $page = $web->get(self::PAGE, ['key' => self::KEY]);
        self::assertSame('Count difference', self::options($page, $select)['count_difference'] ?? null);
        $ok = $web->post(self::PAGE, ['value' => 'count_difference', 'reason' => 'an everyday count needs no note'] + $page->form(self::PAGE, true));
        self::assertSame(303, $ok->status, $ok->describe());
        $after = $web->follow($ok);
        self::assertSame(Words::SETTING_NOTICE['saved'], self::str($after, '//p[contains(@class, "notice")]'));
        self::assertSame('Count difference', self::now($after));
        self::assertSame('count_difference', self::chosen($after, $select));
        $history = self::str($after, '//section[contains(@class, "history")]');
        self::assertStringContainsString(Words::SETTING_EDIT['now'] . ': Data correction → Count difference', $history);
        self::assertStringContainsString('an everyday count needs no note', $history);
        self::assertStringNotContainsString('data_correction', $history, 'the History names the reasons, not their codes');
        self::assertStringNotContainsString('count_difference', $history);
        self::assertSame(2, (int) self::$db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'setting' AND subject_key = ?", [self::KEY]));
        $adj = $this->signIn($this->uiUser('stock_controller'));
        self::assertSame('count_difference', self::top($adj->get(self::NEW)), 'a new adjustment opens on it from now on');

        // None: the person chooses each time.
        $none = $web->post(self::PAGE, ['value' => '', 'reason' => 'each person chooses'] + $after->form(self::PAGE, true));
        self::assertSame(303, $none->status, $none->describe());
        $after = $web->follow($none);
        self::assertSame(Words::CONFIG['not_set'], self::now($after));
        self::assertSame('', self::chosen($after, $select));
        self::assertSame('', (new Settings(self::$db))->get(self::KEY));
        self::assertNull(self::top($adj->get(self::NEW)));
    }

    public function testTheSettingsListHasItUnderStockRecordsAsAChoiceInItsRow(): void
    {
        $row = '//form[@data-key="' . self::KEY . '"]';
        $select = $row . '//select[@name="value"]';
        $look = '//div[@data-key="' . self::KEY . '"]';
        $buyer = $this->signIn($this->uiUser('buyer'));
        $list = $buyer->get(self::LIST);
        self::assertSame(200, $list->status, $list->describe());
        self::assertSame('Data correction', self::str($list, $look . '//span[contains(@class, "set-val")]'), 'who only looks sees the reason\'s name');
        self::assertSame(Words::SETTING_TOPIC['stock'], self::str($list, $look . '/ancestor::section[1]/h2'), 'listed under Stock records');

        $web = $this->signIn($this->uiUser('reviewer'));
        $list = $web->get(self::LIST);
        self::assertSame(0, (new \DOMXPath($list->dom()))->query($row . '//input[@name="value"]')->length, 'a choice, not a box to type a code in');
        self::assertSame(['' => Words::SETTING_EDIT['reason_none']] + self::offered(), self::options($list, $select));
        self::assertSame('data_correction', self::chosen($list, $select));
        self::assertStringContainsString(Words::SETTING['stock.adjust_opens_on'], self::str($web->get(self::LIST, ['q' => 'new adjustment opens']), $row), 'the search finds it');

        self::offer();
        $list = $web->get(self::LIST);
        $fields = [];
        foreach ((new \DOMXPath($list->dom()))->query($row . '//input[@type="hidden"]') ?: [] as $n) {
            /** @var \DOMElement $n */
            $fields[$n->getAttribute('name')] = $n->getAttribute('value');
        }
        self::assertSame(['csrf', 'key', 'seen'], array_keys($fields), 'not agreed yet: the row sends no "agreed"');
        $ok = $web->post(self::PAGE, ['value' => 'count_difference', 'reason' => 'chosen in its row'] + $fields);
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame('count_difference', (new Settings(self::$db))->get(self::KEY));
        self::assertSame('count_difference', self::chosen($web->get(self::LIST), $select));

        // No longer offered on stock corrections: the row and the look-only value say so; the adjustment page is on Data correction again.
        (new ReasonCodes(self::$db))->setUses(self::sys(), 'count_difference', ['count'], 'for counts only again');
        $gone = sprintf(Words::SETTING_EDIT['reason_gone'], 'Count difference');
        $list = $web->get(self::LIST);
        self::assertSame('count_difference', self::chosen($list, $select));
        self::assertSame($gone, self::options($list, $select)['count_difference'] ?? null);
        self::assertSame($gone, self::str($buyer->get(self::LIST), $look . '//span[contains(@class, "set-val")]'));
        self::assertSame($gone, self::now($buyer->get(self::PAGE, ['key' => self::KEY])));
    }

    public function testAKeyWrittenInOtherLettersIsNoSetting(): void
    {
        // Codex on ca9de9e (MEDIUM, old): MySQL finds a setting's row whatever the case of the key's letters, and the rules (and the
        // "changed elsewhere" guards) are looked up by the exact key: a key in capitals was saved with no rule checked. Only the key as it
        // is stored is a setting.
        $switch = (string) array_key_first(ApprovalRules::SWITCHES);
        $was = (new Settings(self::$db))->get($switch);
        foreach (['STOCK.adjust_opens_on' => 'no_such_reason', 'Stock.Adjust_Opens_On' => 'no_such_reason', strtoupper($switch) => $was === true ? 'false' : 'true'] as $key => $value) {
            try {
                (new Settings(self::$db))->change(self::sys(), (string) $key, $value, 'a key in other letters');
                self::fail($key . ': saved, its rule not checked');
            } catch (CwException $e) {
                self::assertSame('unknown_setting', $e->errorCode, (string) $key);
            }
        }
        self::assertSame('data_correction', (new Settings(self::$db))->get(self::KEY));
        self::assertSame($was, (new Settings(self::$db))->get($switch));
        // The Settings form with its key changed by hand.
        $web = $this->signIn($this->uiUser('reviewer'));
        $form = $web->get(self::PAGE, ['key' => self::KEY])->form(self::PAGE, true);
        $bad = $web->post(self::PAGE, ['key' => 'STOCK.adjust_opens_on', 'value' => 'no_such_reason', 'reason' => 'the key changed by hand'] + $form);
        self::assertSame(404, $bad->status, $bad->describe());
        self::assertSame('data_correction', (new Settings(self::$db))->get(self::KEY), 'nothing saved');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = ?", [self::KEY]), 'no history row');
    }

    public function testTwoReasonsOfOneNameAreToldApartInTheHistoryByTheirCodes(): void
    {
        // Codex on ca9de9e (LOW): the History compared the reasons' names, so a change between two reasons of one name (Reasons lets two
        // reasons share a name; a reason renamed since) was a row that said nothing.
        self::offer();
        self::opensOn('count_difference');
        $web = $this->signIn($this->uiUser('reviewer'));
        $history = static fn (): string => self::str($web->get(self::PAGE, ['key' => self::KEY]), '//section[contains(@class, "history")]');
        self::assertStringContainsString(Words::SETTING_EDIT['now'] . ': Data correction → Count difference', $history(), 'two names: no codes');
        (new ReasonCodes(self::$db))->rename(self::sys(), 'count_difference', 'Data correction', 'the same name as another reason');
        self::assertStringContainsString(Words::SETTING_EDIT['now'] . ': Data correction (data_correction) → Data correction (count_difference)', $history(),
            'one name: the change is still said, the codes tell the two apart');
    }
}
