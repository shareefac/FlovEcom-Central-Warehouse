<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Clock;
use CW\Settings;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * CW-1180 (0081; docs/decisions.md NR1, NR7) through the real /ui kernel as the app login: a cancelled paid unit the store marked "don't
 * restock" and written off at once says so in plain words on Sales returns (with what it was worth), on its order's page (the step, and
 * its take-back) and on Stock movements; one parked the way before keeps its old words. The setting is a choice in words under Stock
 * records, changed with a reason and kept in its History by its words.
 */
final class NoRestockScreensTest extends KernelUiTestCase
{
    private const KEY = 'stock.cancel_not_restockable';
    private const PAGE = '/ui/reference/settings/setting';
    private const LIST = '/ui/reference/settings';

    /** @var array<string, mixed> */
    private array $row = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The sales screens read the real clock ("today"), so the engine works at the real time here (as SalesScreensTest).
        $this->now = Clock::now();
        $this->row = (array) self::$db->one('SELECT CAST(value_json AS CHAR) AS v, provisional, updated_actor FROM app_setting WHERE setting_key = ?', [self::KEY]);
    }

    protected function tearDown(): void
    {
        // a seed row TestDb::clean keeps: put back as it was read (its history rows of this test go with the next clean)
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ? WHERE setting_key = ?',
            [$this->row['v'], (int) $this->row['provisional'], $this->row['updated_actor'], self::KEY]);
        parent::tearDown();
    }

    private static function text(UiResponse $r): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $r->text()));
    }

    /** @return array<string, string> value => words of the select at $at */
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

    public function testAWrittenOffUnitIsSaidInPlainWordsAndOneParkedTheWayBeforeKeepsItsWords(): void
    {
        $site = $this->site('vpg');
        $sku = $this->item('strict', 0, 'Pod written off');
        $this->book('goods_in', $sku, 10, 'MAIN', null, '2.04');
        $this->listing($site, 'W1', $sku);
        $this->listing($site, 'W2', $sku, 2);
        $this->ok($this->commit($site, 'W100', [self::line('W1', 'w1', 'w2'), self::line('W2', 'w3')]));
        $this->ok($this->res->cancel($site, 'W100', ['w1', 'w3'], false, $this->key('cancel'))); // written off: £2.04, and £4.08 (a pack of two)
        self::notRestockable('verify');
        $this->ok($this->res->cancel($site, 'W100', ['w2'], false, $this->key('cancel')));       // parked, the way before
        self::notRestockable('write_off');
        $main = (string) self::$db->value("SELECT name FROM warehouse WHERE code = 'MAIN'");
        $verify = (string) self::$db->value("SELECT name FROM warehouse WHERE code = 'VERIFY'");

        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $returns = $web->get('/ui/stock/sales/returns');
        self::assertSame(200, $returns->status, $returns->describe());
        $t = self::text($returns);
        self::assertStringContainsString(sprintf(Words::SALE_RESTOCK_TEXT['written_off'], $main, '£2.04'), $t);
        self::assertStringContainsString(sprintf(Words::SALE_RESTOCK_TEXT['written_off'], $main, '£4.08'), $t);
        self::assertStringContainsString(sprintf(Words::SALE_RESTOCK_TEXT['verify_open'], $verify), $t, 'the one parked the way before keeps its words');
        $csv = $web->get('/ui/stock/sales/returns.csv');
        self::assertSame(200, $csv->status);
        self::assertStringContainsString(sprintf(Words::SALE_RESTOCK_TEXT['written_off'], $main, '£2.04'), $csv->body);

        $id = (int) self::$db->value("SELECT id FROM reservation WHERE order_ref = 'W100'");
        $order = $web->get('/ui/stock/sales/' . $id);
        self::assertSame(200, $order->status, $order->describe());
        self::assertStringContainsString(sprintf(Words::SALE_ORDER['step_written_off'], '£2.04'), self::text($order));
        self::assertStringContainsString(sprintf(Words::SALE_ORDER['step_written_off'], '£4.08'), self::text($order));

        // taken back: the order's page says so, and the unit is no longer on Sales returns
        $this->ok($this->res->uncancel($site, 'W100', ['w1'], $this->key('uncancel')));
        self::assertStringContainsString(Words::SALE_ORDER['step_write_off_back'], self::text($web->get('/ui/stock/sales/' . $id)));
        self::assertStringNotContainsString(sprintf(Words::SALE_RESTOCK_TEXT['written_off'], $main, '£2.04'), self::text($web->get('/ui/stock/sales/returns')));

        // Stock movements: the system note in words, never its code
        $moves = $web->get('/ui/stock/movements', ['q' => sprintf('CW-%06d', $sku)]);
        self::assertSame(200, $moves->status, $moves->describe());
        self::assertStringContainsString(Words::LEDGER_NOTE['cancel_not_restockable'], self::text($moves));
        self::assertStringContainsString(Words::LEDGER_NOTE['uncancel_from_write_off'], self::text($moves));
        self::assertStringNotContainsString('cancel_not_restockable', $moves->body);
    }

    public function testTheSettingIsAChoiceInWordsUnderStockRecordsAndAChangeIsKeptInItsHistoryByItsWords(): void
    {
        $choices = Words::SETTING_CHOICE[self::KEY];
        self::assertSame(['write_off', 'verify'], array_keys($choices));
        $web = $this->signIn($this->uiUser('reviewer'));
        $list = $web->get(self::LIST);
        self::assertSame(200, $list->status, $list->describe());
        $row = '//form[@data-key="' . self::KEY . '"]';
        self::assertSame($choices, self::options($list, $row . '//select[@name="value"]'), 'a choice in words in its row, not a code to type');
        self::assertSame('write_off', self::chosen($list, $row . '//select[@name="value"]'));
        self::assertSame(Words::SETTING_TOPIC['stock'], trim((string) (new \DOMXPath($list->dom()))->evaluate('string(' . $row . '/ancestor::section[1]/h2)')),
            'listed under Stock records');
        self::assertStringContainsString(Words::SETTING[self::KEY], self::text($list));

        $select = '//form[@action="' . self::PAGE . '"]//select[@name="value"]';
        $page = $web->get(self::PAGE, ['key' => self::KEY]);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame($choices, self::options($page, $select));
        $form = $page->form(self::PAGE, true);
        $bad = $web->post(self::PAGE, ['value' => 'bin_it', 'reason' => 'no such way'] + $form);
        self::assertSame([400, 'bad_value'], [$bad->status, $bad->errorCode()]);
        self::assertSame('write_off', (new Settings(self::$db))->get(self::KEY), 'nothing saved');

        $ok = $web->post(self::PAGE, ['value' => 'verify', 'reason' => 'back to the recount for a week'] + $web->get(self::PAGE, ['key' => self::KEY])->form(self::PAGE, true));
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame('verify', (new Settings(self::$db))->get(self::KEY));
        $after = $web->follow($ok);
        $history = trim((string) preg_replace('/\s+/u', ' ', (string) (new \DOMXPath($after->dom()))->evaluate('string(//section[contains(@class, "history")])')));
        self::assertStringContainsString(Words::SETTING_EDIT['now'] . ': ' . $choices['write_off'] . ' → ' . $choices['verify'], $history);
        self::assertStringContainsString('back to the recount for a week', $history);
        self::assertStringNotContainsString('write_off', $history, 'the History names the ways, not their codes');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'setting.change' AND entity_id = ?", [self::KEY]));
    }
}
