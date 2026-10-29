<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Clock;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Caller;
use CW\Movements;
use CW\StockOps\StockOps;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\CountsController;
use CW\Ui\Controller\HandoversController;
use CW\Ui\Controller\StockOpsController;
use CW\Ui\Controller\StoreQueuesController;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * "Final uses what is on the screen" (docs/decisions.md OV1-OV8; the owner's UX review of 29 Oct 2026): a stock record's and a hand-over's
 * lines and their final button are ONE form, so a unit or a price changed on the screen and not saved first is what is made final (the
 * save, then the posting, each in its own transaction as before); a refused posting after the save keeps the saved draft and says so;
 * a submit with nothing changed just posts; a record too big for one form falls back to "save, then make final"; the counting sheet's
 * "Before you make it final" saves the quantities typed first; a refused answer on Lines to place keeps what was typed in its row.
 */
final class OpFinalScreensTest extends KernelUiTestCase
{
    /** @var array<string, string> setting key => its value_json to put back */
    private array $savedSettings = [];

    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSettings as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        $this->savedSettings = [];
        parent::tearDown();
    }

    private function setting(string $key, string $json): void
    {
        $this->savedSettings[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    /** Starts a stock record of $kind through its create form and returns its page's path. @param array<string, string> $over */
    private function start(KernelBrowser $web, string $kind, array $over = []): string
    {
        $form = $web->get(StockOps::PATHS[$kind])->form(StockOps::PATHS[$kind], true);
        $r = $web->post(StockOps::PATHS[$kind], $over + $form);
        self::assertSame(303, $r->status, $r->describe());
        return (string) parse_url((string) $r->location(), PHP_URL_PATH);
    }

    /** Starts a hand-over for a new customer (VAT code S) and returns its page's path. */
    private function startHandover(KernelBrowser $web): string
    {
        $cp = (int) self::$db->insert("INSERT INTO counterparty (kind, name, vat_code, created_actor) VALUES ('trade', ?, 'S', 'test')", ['OV Trade ' . bin2hex(random_bytes(3))]);
        $r = $web->post('/ui/stock/handovers', ['counterparty' => (string) $cp] + $web->get('/ui/stock/handovers')->form('/ui/stock/handovers', true));
        self::assertSame(303, $r->status, $r->describe());
        return (string) parse_url((string) $r->location(), PHP_URL_PATH);
    }

    /** Posts a page's form (its fields as drawn, with $over). @param array<string, string> $over */
    private function submit(KernelBrowser $web, string $page, string $action, array $over = []): UiResponse
    {
        $p = $web->get($page);
        self::assertSame(200, $p->status, $p->describe() . ' ' . implode(' | ', self::$log));
        $form = $p->form($action, true);
        self::assertNotSame([], $form, "no {$action} form: " . $p->describe());
        return $web->post($action, $over + $form);
    }

    private static function xp(UiResponse $page): \DOMXPath
    {
        return new \DOMXPath($page->dom());
    }

    private static function docStatus(int $id): string
    {
        return (string) self::$db->value('SELECT status FROM document WHERE id = ?', [$id]);
    }

    private static function lineQty(int $id, int $line = 1): int
    {
        return (int) self::$db->value('SELECT qty FROM document_line WHERE document_id = ? AND line_no = ?', [$id, $line]);
    }

    private static function audits(int $id, string $action): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_type = 'document' AND entity_id = ?", [$action, (string) $id]);
    }

    // ------------------------------------------------------------------------------------------
    // Stock records
    // ------------------------------------------------------------------------------------------

    public function testTheFinalButtonMakesFinalTheUnitsOnTheScreen(): void
    {
        $sku = $this->item('strict', 4, 'Omega pod 2ml');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $this->start($web, 'in', ['reason_code' => 'found']);
        $id = (int) basename($page);
        self::assertSame(303, $this->submit($web, $page, $page . '/add', ['q' => 'CW-' . $sku, 'qty' => '5', 'cost' => '1.50'])->status);

        // One form: the lines and both buttons, Save first (Enter in a box saves), the record's own final words the main button.
        $draft = $web->get($page);
        $xp = self::xp($draft);
        $buttons = $xp->query('//form[contains(@action, "' . $page . '/lines")]//button[@type="submit"]');
        self::assertSame(2, $buttons->length, 'Save and the final button are buttons of the lines form');
        self::assertSame(['save', 'post'], [$buttons->item(0)?->getAttribute('value'), $buttons->item(1)?->getAttribute('value')]);
        self::assertSame(['action', 'action'], [$buttons->item(0)?->getAttribute('name'), $buttons->item(1)?->getAttribute('name')]);
        self::assertSame(Words::OP_FINAL['save'], trim((string) $buttons->item(0)?->textContent));
        self::assertSame(Words::STOCK_KIND['in']['final_button'], trim((string) $buttons->item(1)?->textContent));
        self::assertStringContainsString('primary', (string) $buttons->item(1)?->getAttribute('class'));
        self::assertSame(1, $xp->query('//form[contains(@action, "' . $page . '/lines")]//*[contains(@class, "actions") and contains(@class, "sticky")]//button[@value="post"]')->length,
            'the final button is in the sticky bar');
        self::assertFalse($draft->hasForm($page . '/post'), 'no second form that would make final what is stored');

        // 5 saved, 7 on the screen, the final button: 7 is made final.
        $r = $web->post($page . '/lines', ['u_1' => '7', 'action' => 'post'] + $draft->form($page . '/lines', true));
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringContainsString(Words::say('STOCK_NOTICE', 'posted', 'SIN-000001'), $web->follow($r)->text());
        self::assertSame('posted', self::docStatus($id));
        self::assertSame(7, (int) self::$db->value('SELECT SUM(qty_delta) FROM stock_ledger WHERE document_id = ?', [$id]));
        self::assertSame(11, (int) self::$db->value("SELECT on_hand FROM stock_balance WHERE sku_id = ? AND warehouse_id = (SELECT id FROM warehouse WHERE code = 'MAIN')", [$sku]));
    }

    public function testSaveKeepsADraftAndAFinalWithNothingChangedJustPosts(): void
    {
        $sku = $this->item('strict', 10, 'Sigma liquid');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $this->start($web, 'out', ['reason_code' => 'trade_sale']);
        $id = (int) basename($page);
        self::assertSame(303, $this->submit($web, $page, $page . '/add', ['q' => (string) $sku, 'qty' => '2'])->status);

        $r = $this->submit($web, $page, $page . '/lines', ['u_1' => '3', 'action' => 'save']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString(Words::STOCK_NOTICE['lines'], $web->follow($r)->text());
        self::assertSame(['draft', 3], [self::docStatus($id), self::lineQty($id)]);

        $lines = self::audits($id, 'document.lines');
        $version = (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$id]);
        $r = $this->submit($web, $page, $page . '/lines', ['action' => 'post']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('posted', self::docStatus($id));
        self::assertSame($lines, self::audits($id, 'document.lines'), 'nothing changed: nothing saved, it was just made final');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'document.post' AND entity_id = ?", [(string) $id]));
        self::assertGreaterThan($version, (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$id]));
        self::assertSame(7, (int) self::$db->value("SELECT on_hand FROM stock_balance WHERE sku_id = ? AND warehouse_id = (SELECT id FROM warehouse WHERE code = 'MAIN')", [$sku]));
    }

    public function testARefusedFinalAfterASaveKeepsTheSavedLinesAndSaysWhy(): void
    {
        $sku = $this->item('strict', 10, 'Tau liquid');
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $this->start($web, 'out', ['reason_code' => 'sample']);
        $id = (int) basename($page);
        self::assertSame(303, $this->submit($web, $page, $page . '/add', ['q' => (string) $sku, 'qty' => '2'])->status);

        // Saved (3 units), then refused (nobody named): the draft keeps 3 and the page says so in plain words.
        $r = $this->submit($web, $page, $page . '/lines', ['u_1' => '3', 'action' => 'post']);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('OP_FINAL', 'not_final', Words::STOCK_ERROR['given_to_required']), $r->text());
        self::assertSame(['draft', 3], [self::docStatus($id), self::lineQty($id)]);
        $again = $r->form($page . '/lines', true);
        self::assertSame('3', $again['u_1'] ?? null, 'the redraw shows what was saved');
        self::assertSame((string) self::$db->value('SELECT version FROM document WHERE id = ?', [$id]), $again['version'] ?? null, 'and carries the saved version');

        // Nothing changed now: the refusal is said as before ("not saved" would be wrong words for nothing done).
        $r = $web->post($page . '/lines', ['action' => 'post'] + $again);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::STOCK_ERROR['given_to_required'], $r->text());
        self::assertStringNotContainsString(Words::say('OP_FINAL', 'not_final', ''), $r->text());

        // A save refused (units not a number): nothing saved, nothing made final, the typed value still in its box.
        $r = $web->post($page . '/lines', ['u_1' => 'three', 'action' => 'post'] + $again);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::STOCK_ERROR['qty_positive'], $r->text());
        self::assertSame(['draft', 3], [self::docStatus($id), self::lineQty($id)]);
        self::assertSame('three', $r->form($page . '/lines', true)['u_1'] ?? null, 'what was typed is still in its box');

        // Named on the details, the same one form makes it final with what is on the screen.
        self::assertSame(303, $this->submit($web, $page, $page . '/details', ['given_to' => 'Sam at the shop'])->status);
        $r = $this->submit($web, $page, $page . '/lines', ['u_1' => '4', 'action' => 'post']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['posted', 4], [self::docStatus($id), self::lineQty($id)]);
        self::assertSame(-4, (int) self::$db->value('SELECT SUM(qty_delta) FROM stock_ledger WHERE document_id = ?', [$id]));
    }

    public function testAnAdjustmentOverTheLimitWaitsForAReviewerWithTheUnitsOnTheScreen(): void
    {
        $sku = $this->item('strict', 3, 'Upsilon kit');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $this->start($web, 'adjust');
        $id = (int) basename($page);
        self::assertSame(303, $this->submit($web, $page, $page . '/add', ['q' => (string) $sku, 'qty' => '5', 'reason' => 'found'])->status);
        // +5 is under the "stock put back" limit (10); +12 typed on the screen is over it: the OK first, with 12.
        $r = $this->submit($web, $page, $page . '/lines', ['u_1' => '12', 'action' => 'post']);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringContainsString(Words::STOCK_NOTICE['submitted'], $web->follow($r)->text());
        self::assertSame(['awaiting_approval', 12], [self::docStatus($id), self::lineQty($id)]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE document_id = ?', [$id]), 'nothing booked before the OK');
    }

    public function testTheOneFormCountsEveryFieldItCanSend(): void
    {
        // The budget against max_input_vars (OV4) is worked out from the fields the form really has: every named control of the lines
        // form (a ticked "remove" included) plus the one button sent.
        $web = $this->signIn($this->uiUser(['stock_controller', 'purchasing_desk']));
        $other = (int) self::$db->insert("INSERT INTO warehouse (code, name, is_sellable, is_active, stock_owner, owner_entity, sort_order) VALUES ('OVTWO', 'OV room', 0, 1, 'other', 'OV Two Ltd', 40)");
        $a = $this->item('strict', 20, 'Phi pod');
        $b = $this->item('strict', 20, 'Chi pod');
        $shop = (int) self::$db->insert("INSERT INTO warehouse (code, name, is_sellable, is_active, stock_owner, sort_order) VALUES ('OVSHOP', 'OV shop room', 0, 1, 'own', 45)");
        foreach (['in' => ['reason_code' => 'found'], 'out' => ['reason_code' => 'trade_sale'], 'adjust' => [], 'transfer' => ['to_warehouse' => (string) $shop],
            'release' => ['warehouse' => (string) $other]] as $kind => $over) {
            $page = $this->start($web, $kind, $over);
            $id = (int) basename($page);
            foreach ([$a, $b] as $sku) {
                self::$db->exec('INSERT INTO document_line (document_id, line_no, sku_id, qty, reason_code) VALUES (?, ?, ?, 1, ?)',
                    [$id, (int) self::$db->value('SELECT COUNT(*) + 1 FROM document_line WHERE document_id = ?', [$id]), $sku, $kind === 'adjust' ? 'found' : null]);
            }
            $draft = $web->get($page);
            self::assertSame(200, $draft->status, $kind . ' ' . $draft->describe() . ' ' . implode(' | ', self::$log));
            $named = self::xp($draft)->query('//form[contains(@action, "' . $page . '/lines")]//*[(self::input or self::select or self::textarea) and @name]');
            self::assertSame(StockOpsController::oneFormFields($kind, 2), $named->length + 1, "{$kind}: every field of the one form is counted");
        }
        $page = $this->startHandover($web);
        $id = (int) basename($page);
        self::$db->exec('INSERT INTO document_line (document_id, line_no, sku_id, qty, unit_cost) VALUES (?, 1, ?, 1, 2.00), (?, 2, ?, 1, 2.00)', [$id, $a, $id, $b]);
        $named = self::xp($web->get($page))->query('//form[contains(@action, "' . $page . '/lines")]//*[(self::input or self::select or self::textarea) and @name]');
        self::assertSame(HandoversController::oneFormFields(2), $named->length + 1, 'hand-over: every field of the one form is counted');
    }

    public function testARecordTooBigForOneFormIsSavedFirstThenMadeFinal(): void
    {
        $max = UiRequest::maxInputVars();
        $n = intdiv($max - StockOpsController::oneFormFields('in', 0), StockOpsController::oneFormFields('in', 1) - StockOpsController::oneFormFields('in', 0)) + 1;
        if ($n > StockOps::MAX_LINES) {
            self::markTestSkipped("max_input_vars is {$max} here: every stock record fits one form");
        }
        $sku = $this->item('strict', 0, 'Psi refill');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $this->start($web, 'in', ['reason_code' => 'found']);
        $id = (int) basename($page);
        $rows = [];
        $params = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = '(?, ?, ?, 1)';
            array_push($params, $id, $i, $sku);
        }
        self::$db->exec('INSERT INTO document_line (document_id, line_no, sku_id, qty) VALUES ' . implode(', ', $rows), $params);
        $draft = $web->get($page);
        self::assertSame(200, $draft->status, $draft->describe());
        $xp = self::xp($draft);
        self::assertSame(0, $xp->query('//form[contains(@action, "' . $page . '/lines")]//button[@value="post"]')->length, 'no final button in a form PHP would cut');
        self::assertTrue($draft->hasForm($page . '/post'), 'the final box\'s own form makes it final');
        self::assertStringContainsString(Words::say('OP_FINAL', 'too_many', Words::STOCK_OPS['save_lines']), $draft->text());
        $r = $web->post($page . '/post', $draft->form($page . '/post'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('posted', self::docStatus($id));
        self::assertSame($n, (int) self::$db->value('SELECT SUM(qty_delta) FROM stock_ledger WHERE document_id = ?', [$id]));
    }

    // ------------------------------------------------------------------------------------------
    // Hand-overs
    // ------------------------------------------------------------------------------------------

    public function testAHandOverMadeFinalTakesThePriceOnTheScreen(): void
    {
        $sku = $this->item('legacy', 10, 'Kappa liquid 10ml');
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $this->startHandover($web);
        $id = (int) basename($page);
        self::assertSame(303, $this->submit($web, $page, $page . '/add', ['q' => 'CW-' . $sku, 'qty' => '5', 'price' => '2.40'])->status);
        $draft = $web->get($page);
        $buttons = self::xp($draft)->query('//form[contains(@action, "' . $page . '/lines")]//button[@type="submit"]');
        self::assertSame(['save', 'post'], [$buttons->item(0)?->getAttribute('value'), $buttons->item(1)?->getAttribute('value')]);
        self::assertSame(Words::HANDOVER['final_button'], trim((string) $buttons->item(1)?->textContent));
        self::assertFalse($draft->hasForm($page . '/post'));

        // £2.40 saved, £3.10 on the screen, the final button: £3.10 is made final (5 x 3.10 = 15.50 before VAT).
        $r = $web->post($page . '/lines', ['p_1' => '3.10', 'action' => 'post'] + $draft->form($page . '/lines', true));
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringContainsString(Words::say('HANDOVER_NOTICE', 'posted', 'TRD-000001'), $web->follow($r)->text());
        self::assertSame('posted', self::docStatus($id));
        self::assertSame(0, bccomp('3.10', (string) self::$db->value('SELECT unit_cost FROM document_line WHERE document_id = ? AND line_no = 1', [$id]), 6));
        self::assertSame('15.50', (string) self::$db->value('SELECT net_amount FROM handover WHERE document_id = ?', [$id]));
        self::assertSame(5, (int) self::$db->value("SELECT on_hand FROM stock_balance WHERE sku_id = ? AND warehouse_id = (SELECT id FROM warehouse WHERE code = 'MAIN')", [$sku]));
        // The same form sent again: nothing is done twice.
        $again = $web->post($page . '/lines', ['p_1' => '3.10', 'action' => 'post'] + $draft->form($page . '/lines', true));
        self::assertSame(409, $again->status, $again->describe());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE document_id = ?', [$id]));
    }

    public function testAHandOverRefusedAfterItsSaveKeepsThePrices(): void
    {
        $sku = $this->item('strict', 2, 'Lambda kit');
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $this->startHandover($web);
        $id = (int) basename($page);
        self::assertSame(303, $this->submit($web, $page, $page . '/add', ['q' => 'CW-' . $sku, 'qty' => '1', 'price' => '5.00'])->status);
        // 3 units at £6.00 typed: saved, then refused (only 2 on the shelf of a protected item).
        $r = $this->submit($web, $page, $page . '/lines', ['u_1' => '3', 'p_1' => '6.00', 'action' => 'post']);
        self::assertSame(422, $r->status, $r->describe());
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        self::assertStringContainsString(Words::say('OP_FINAL', 'not_final', Words::say('HANDOVER_ERROR', 'below_zero', 1, $code, 2, 3)), $r->text());
        self::assertSame(['draft', 3], [self::docStatus($id), self::lineQty($id)]);
        self::assertSame(0, bccomp('6.00', (string) self::$db->value('SELECT unit_cost FROM document_line WHERE document_id = ? AND line_no = 1', [$id]), 6));
        $again = $r->form($page . '/lines', true);
        self::assertSame(['3', 0], [$again['u_1'] ?? null, bccomp('6', (string) ($again['p_1'] ?? ''), 6)], 'the redraw shows what was saved');
    }

    // ------------------------------------------------------------------------------------------
    // Counts
    // ------------------------------------------------------------------------------------------

    public function testTheCountReviewButtonSavesTheQuantitiesTypedFirst(): void
    {
        $a = $this->item('strict', 10, 'Alpha pod 2ml');
        $b = $this->item('strict', 5, 'Beta liquid');
        $this->setting('counts.sheet_shows_stock', 'false');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $list = $web->get(CountsController::PATH);
        $r = $web->post(CountsController::PATH, ['mode' => 'ticked', 'pick_' . $a => '1', 'pick_' . $b => '1'] + $list->form(CountsController::PATH));
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $sheet = (string) parse_url((string) $r->location(), PHP_URL_PATH);
        $id = (int) basename($sheet);
        self::$db->exec('UPDATE count_sheet_line SET added_at = added_at - INTERVAL 10 MINUTE WHERE document_id = ?', [$id]);

        $page = $web->get($sheet);
        $xp = self::xp($page);
        // A blind count stays blind (docs/decisions.md CV8: no stock column at all).
        self::assertSame(0, $xp->query('//table[contains(@class, "count-sheet")]//td[@data-label="' . Words::COUNTS['stock_now'] . '"]')->length);
        // "Save and review" is a button of the quantity form (not a link that leaves what was typed behind); Enter still saves.
        $formId = (string) $xp->evaluate('string(//form[contains(@action, "' . $sheet . '/lines")]/@id)');
        self::assertNotSame('', $formId, 'the quantity form has an id its buttons name');
        $review = $xp->query('//button[@type="submit" and @name="then" and @value="review"]');
        self::assertSame(1, $review->length);
        self::assertSame($formId, $review->item(0)?->getAttribute('form'));
        self::assertSame(Words::COUNTS['save_review'], trim((string) $review->item(0)?->textContent));
        self::assertSame(0, $xp->query('//main//a[contains(@href, "' . $sheet . '/differences")]')->length, 'no link that leaves typed quantities behind');
        $first = $xp->query('//form[contains(@action, "' . $sheet . '/lines")]//button[@type="submit"]');
        self::assertSame(Words::COUNTS['save'], trim((string) $first->item(0)?->textContent), 'the first button of the form (Enter) saves the page');
        self::assertSame('', $first->item(0)?->getAttribute('name'), 'it sends no "then": Enter never opens the review');

        // Line 2 was counted in another tab after this page was drawn: the review button saves line 1 typed here, and never empties line 2.
        $drawn = $page->form($sheet . '/lines');
        self::assertSame(303, $web->post($sheet . '/lines', ['q_2' => '4'] + $drawn)->status);
        $ms = (int) Clock::now()->modify('-2 minutes')->format('Uv');
        $r = $web->post($sheet . '/lines', ['q_1' => '8', 'at_1' => (string) $ms, 'then' => 'review'] + $drawn);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame($sheet . '/differences', parse_url((string) $r->location(), PHP_URL_PATH), 'then the review page opens');
        self::assertSame([8, 4], [(int) self::$db->value('SELECT counted_qty FROM count_sheet_line WHERE document_id = ? AND line_no = 1', [$id]),
            (int) self::$db->value('SELECT counted_qty FROM count_sheet_line WHERE document_id = ? AND line_no = 2', [$id])]);
        self::assertSame(Clock::db(\DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($ms, 1000), ($ms % 1000) * 1000))),
            self::$db->value('SELECT counted_at FROM count_sheet_line WHERE document_id = ? AND line_no = 1', [$id]), 'with the moment its box was filled in');
        $before = $web->follow($r);
        self::assertSame(200, $before->status, $before->describe());
        self::assertStringContainsString('Alpha pod 2ml', $before->text());
        self::assertStringContainsString(Words::say('COUNT_NOTICE', 'lines', 1), $before->text());
        self::assertSame(10, (int) self::$db->value('SELECT on_hand FROM stock_balance WHERE warehouse_id = 1 AND sku_id = ?', [$a]), 'nothing booked yet');

        // A refused quantity: nothing saved, the sheet again with what was typed (not the review page).
        $r = $web->post($sheet . '/lines', ['q_1' => 'five', 'then' => 'review'] + $web->get($sheet)->form($sheet . '/lines'));
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::say('COUNT_ERROR', 'bad_quantity', 1), $r->text());
        self::assertSame('five', $r->form($sheet . '/lines')['q_1'] ?? null);
        self::assertSame(8, (int) self::$db->value('SELECT counted_qty FROM count_sheet_line WHERE document_id = ? AND line_no = 1', [$id]));
    }

    // ------------------------------------------------------------------------------------------
    // Lines to place
    // ------------------------------------------------------------------------------------------

    public function testARefusedAnswerOnLinesToPlaceKeepsWhatWasTypedInItsRow(): void
    {
        $site = self::makeChannel('vpg', 'live');
        self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$site, self::warehouseId('MAIN')]);
        self::$db->exec('UPDATE channel SET movement_types = ? WHERE id = ?', [json_encode(['goods_in']), $site]);
        $caller = Caller::channel($site, 'vpg');
        $moves = new Movements(self::$db);
        $moves->record($caller, ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-7', 'lines' => [['variant_id' => 'NEW1', 'qty' => 2, 'description' => 'Mango 10ml'],
            ['variant_id' => 'NEW2', 'qty' => 3, 'description' => 'Lime 10ml']]], 'relay-ov');
        $ids = array_map('intval', self::$db->column('SELECT id FROM goods_in_suspense ORDER BY id'));
        self::assertCount(2, $ids);
        $sku = self::makeSku('Mango 10ml');
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        $web = $this->signIn($this->uiUser('stock_controller'));
        $path = StoreQueuesController::PATH . '/suspense';
        $page = $web->get($path);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));

        // An item code CW does not know: refused, and the row's form keeps the code, the units and the note; the other row stays empty.
        $r = $web->post($path . '/' . $ids[0] . '/pick', ['item' => 'CW-999999', 'units' => '2', 'note' => 'the new mango'] + $page->form($path . '/' . $ids[0] . '/pick'));
        self::assertGreaterThanOrEqual(400, $r->status, $r->describe());
        self::assertSame(['CW-999999', '2', 'the new mango'], [$r->form($path . '/' . $ids[0] . '/pick')['item'] ?? null, $r->form($path . '/' . $ids[0] . '/pick')['units'] ?? null,
            $r->form($path . '/' . $ids[0] . '/pick')['note'] ?? null]);
        self::assertSame(['', '1', ''], [$r->form($path . '/' . $ids[1] . '/pick')['item'] ?? null, $r->form($path . '/' . $ids[1] . '/pick')['units'] ?? null,
            $r->form($path . '/' . $ids[1] . '/pick')['note'] ?? null], 'the other row is as it was drawn');
        // Corrected from the kept values: booked.
        $r = $web->post($path . '/' . $ids[0] . '/pick', ['item' => $code] + $r->form($path . '/' . $ids[0] . '/pick'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(4, (int) self::$db->value('SELECT on_hand FROM stock_balance WHERE sku_id = ? AND warehouse_id = ?', [$sku, self::warehouseId('MAIN')]));

        // Closing with a note too short: refused, the note kept in its fold, which is open.
        $r = $web->post($path . '/' . $ids[1] . '/dismiss', ['note' => 'x'] + $web->get($path)->form($path . '/' . $ids[1] . '/dismiss'));
        self::assertSame(400, $r->status, $r->describe());
        self::assertSame('x', $r->form($path . '/' . $ids[1] . '/dismiss')['note'] ?? null);
        self::assertSame(1, self::xp($r)->query('//details[@open]//form[contains(@action, "/suspense/' . $ids[1] . '/dismiss")]')->length);
    }

    // ------------------------------------------------------------------------------------------
    // The Codex review of b6b6512 (docs/decisions.md OV9-OV12)
    // ------------------------------------------------------------------------------------------

    public function testAStaleTabsRefusedEditIsNeverLaidOnAnotherProduct(): void
    {
        // Finding 1 (HIGH): tab 1 shows product A; tab 2 removes A and adds B (the same line count); tab 1 sends a bad quantity and A's
        // price. Before: refused for the quantity (422), A's typed values drawn over B with the NEW version, so a retry made B final at
        // A's price. Now: the form's version is checked first: 409, nothing typed drawn back.
        $a = $this->item('strict', 0, 'Alef pod');
        $b = $this->item('strict', 0, 'Bet pod');
        $web = $this->signIn($this->uiUser(['stock_controller', 'purchasing_desk']));
        $page = $this->start($web, 'in', ['reason_code' => 'found']);
        $id = (int) basename($page);
        self::assertSame(303, $this->submit($web, $page, $page . '/add', ['q' => 'CW-' . $a, 'qty' => '5', 'cost' => '1.50'])->status);
        $stale = $web->get($page)->form($page . '/lines', true);
        self::assertSame(303, $this->submit($web, $page, $page . '/lines', ['x_1' => '1', 'action' => 'save'])->status);
        self::assertSame(303, $this->submit($web, $page, $page . '/add', ['q' => 'CW-' . $b, 'qty' => '2', 'cost' => '4.00'])->status);
        $r = $web->post($page . '/lines', ['u_1' => 'three', 'c_1' => '9.99', 'action' => 'post'] + $stale);
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString(Words::STOCK_ERROR['version_conflict'], $r->text());
        $form = $r->form($page . '/lines', true);
        self::assertSame(['2', 0], [$form['u_1'] ?? null, bccomp('4', (string) ($form['c_1'] ?? ''), 6)], 'B as stored, never A\'s typed values');
        self::assertNull(self::xp($r)->query('//form[contains(@action, "/lines")]/@data-dirty')->item(0), 'nothing typed is drawn back, so nothing is unsaved');
        // Sent again with a good quantity: still the old version, still refused; B is unchanged and not final.
        $r = $web->post($page . '/lines', ['u_1' => '3', 'c_1' => '9.99', 'action' => 'post'] + $stale);
        self::assertSame(409, $r->status);
        self::assertSame(['draft', 2], [self::docStatus($id), self::lineQty($id)]);
        self::assertSame(0, bccomp('4', (string) self::$db->value('SELECT unit_cost FROM document_line WHERE document_id = ? AND line_no = 1', [$id]), 6));

        // The same on a hand-over.
        $h = $this->startHandover($web);
        $hid = (int) basename($h);
        self::assertSame(303, $this->submit($web, $h, $h . '/add', ['q' => 'CW-' . $a, 'qty' => '1', 'price' => '1.50'])->status);
        $staleH = $web->get($h)->form($h . '/lines', true);
        self::assertSame(303, $this->submit($web, $h, $h . '/lines', ['x_1' => '1', 'action' => 'save'])->status);
        self::assertSame(303, $this->submit($web, $h, $h . '/add', ['q' => 'CW-' . $b, 'qty' => '1', 'price' => '8.00'])->status);
        $r = $web->post($h . '/lines', ['u_1' => 'x', 'p_1' => '1.50', 'action' => 'post'] + $staleH);
        self::assertSame(409, $r->status, $r->describe());
        $form = $r->form($h . '/lines', true);
        self::assertSame(['1', 0], [$form['u_1'] ?? null, bccomp('8', (string) ($form['p_1'] ?? ''), 6)]);
        self::assertSame('draft', self::docStatus($hid));
    }

    public function testTheSaveFormOfABigRecordComesInPagesPhpTakesWhole(): void
    {
        // Finding 2: a record too big for the one form kept ONE "Save the lines" form of every line: a 500-line stock in sent 1,003 fields
        // (units and cost a line), over max_input_vars, so Kernel refused it (400) and the lines could not be saved. Now the lines come in
        // pages that each fit, with the version and the line count checked as before.
        $max = UiRequest::maxInputVars();
        $n = StockOps::MAX_LINES;
        if (3 + 2 * $n < $max) {
            self::markTestSkipped("max_input_vars is {$max} here: a {$n}-line record's save form fits");
        }
        $sku = $this->item('strict', 0, 'Gimel refill');
        $web = $this->signIn($this->uiUser(['stock_controller', 'purchasing_desk']));
        $page = $this->start($web, 'in', ['reason_code' => 'found']);
        $id = (int) basename($page);
        $rows = [];
        $params = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = '(?, ?, ?, 1, 1.00)';
            array_push($params, $id, $i, $sku);
        }
        self::$db->exec('INSERT INTO document_line (document_id, line_no, sku_id, qty, unit_cost) VALUES ' . implode(', ', $rows), $params);
        $first = $web->get($page);
        $r = $web->post($page . '/lines', ['u_1' => '2'] + $first->form($page . '/lines', true));
        self::assertSame(303, $r->status, $r->describe() . ' (the save form as drawn, line 1 changed)');
        $per = StockOpsController::linesPerPage('in');
        self::assertLessThan($max, StockOpsController::oneFormFields('in', $per), 'a page of the save form fits');
        $named = self::xp($first)->query('//form[contains(@action, "' . $page . '/lines")]//*[(self::input or self::select) and @name]');
        self::assertLessThan($max, $named->length, 'every field of the page\'s form, the remove boxes counted ticked');
        self::assertSame(StockOpsController::oneFormFields('in', $per), $named->length, 'and the budget counts each of them');
        self::assertSame($per, self::xp($first)->query('//form[contains(@action, "' . $page . '/lines")]//input[starts-with(@name, "u_")]')->length);
        self::assertStringContainsString(Words::say('OP_FINAL', 'page', 1, $per, $n), $first->text());
        parse_str((string) parse_url((string) $r->location(), PHP_URL_QUERY), $q);
        self::assertSame(['lines', '1'], [$q['notice'] ?? null, $q['lines_page'] ?? null], 'saved, back on the same page of lines');
        self::assertSame(2, self::lineQty($id, 1));
        $second = $web->get($page, ['lines_page' => '2']);
        self::assertSame($n - $per, self::xp($second)->query('//form[contains(@action, "' . $page . '/lines")]//input[starts-with(@name, "u_")]')->length);
        $r = $web->post($page . '/lines', ['u_' . $n => '7'] + $second->form($page . '/lines', true));
        self::assertSame(303, $r->status, $r->describe());
        parse_str((string) parse_url((string) $r->location(), PHP_URL_QUERY), $q);
        self::assertSame('2', $q['lines_page'] ?? null, 'back on the same page of lines');
        self::assertSame([2, 7, 1], [self::lineQty($id, 1), self::lineQty($id, $n), self::lineQty($id, 2)], 'each page saves its own lines, the others as stored');
        // Then the post-only form makes it final with what was saved.
        $r = $web->post($page . '/post', $web->get($page)->form($page . '/post'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($n + 7, (int) self::$db->value('SELECT SUM(qty_delta) FROM stock_ledger WHERE document_id = ?', [$id]), 'every line as saved: 2 and 7 for 1');

        // A hand-over of as many lines: its pages fit too.
        $h = $this->startHandover($web);
        $hid = (int) basename($h);
        $rows = [];
        $params = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = '(?, ?, ?, 1, 2.00)';
            array_push($params, $hid, $i, $sku);
        }
        self::$db->exec('INSERT INTO document_line (document_id, line_no, sku_id, qty, unit_cost) VALUES ' . implode(', ', $rows), $params);
        $hp = $web->get($h);
        self::assertSame(HandoversController::linesPerPage(), self::xp($hp)->query('//form[contains(@action, "' . $h . '/lines")]//input[starts-with(@name, "u_")]')->length);
        $r = $web->post($h . '/lines', ['p_1' => '2.50'] + $hp->form($h . '/lines', true));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(0, bccomp('2.5', (string) self::$db->value('SELECT unit_cost FROM document_line WHERE document_id = ? AND line_no = 1', [$hid]), 6));
    }

    public function testValuesDrawnBackAfterARefusalCountAsUnsaved(): void
    {
        // Finding 3: a refused save draws back what was typed, but the form was not marked unsaved, so the next Add (a scan) left the page
        // without asking and lost them. Now the form drawn with typed values carries the unsaved mark app.js asks about.
        $sku = $this->item('strict', 0, 'Dalet pod');
        $web = $this->signIn($this->uiUser(['stock_controller', 'purchasing_desk']));
        $page = $this->start($web, 'in', ['reason_code' => 'found']);
        self::assertSame(303, $this->submit($web, $page, $page . '/add', ['q' => 'CW-' . $sku, 'qty' => '5', 'cost' => '1.50'])->status);
        self::assertNull(self::xp($web->get($page))->query('//form[contains(@action, "/lines")]/@data-dirty')->item(0), 'a page as stored: nothing unsaved');
        $r = $this->submit($web, $page, $page . '/lines', ['u_1' => '7', 'c_1' => 'abc', 'action' => 'post']);
        self::assertSame(400, $r->status, $r->describe());
        self::assertStringContainsString(Words::STOCK_ERROR['bad_cost'], $r->text());
        self::assertSame('1', (string) self::xp($r)->evaluate('string(//form[contains(@action, "/lines")]/@data-dirty)'), 'what was typed is drawn back unsaved');
        self::assertSame(['7', 'abc'], [$r->form($page . '/lines', true)['u_1'] ?? null, $r->form($page . '/lines', true)['c_1'] ?? null]);
        // A refused details form: the same.
        $r = $this->submit($web, $page, $page . '/details', ['warehouse' => '999999']);
        self::assertGreaterThanOrEqual(400, $r->status, $r->describe());
        self::assertSame('1', (string) self::xp($r)->evaluate('string(//form[contains(@action, "/details")]/@data-dirty)'));
        // A hand-over's lines.
        $h = $this->startHandover($web);
        self::assertSame(303, $this->submit($web, $h, $h . '/add', ['q' => 'CW-' . $sku, 'qty' => '1', 'price' => '1.00'])->status);
        $r = $this->submit($web, $h, $h . '/lines', ['u_1' => 'two', 'action' => 'post']);
        self::assertSame(422, $r->status);
        self::assertSame('1', (string) self::xp($r)->evaluate('string(//form[contains(@action, "/lines")]/@data-dirty)'));
    }

    public function testARefusedLinesToPlaceAnswerIsKeptExactlyAsTyped(): void
    {
        // Finding 4: "000012" was refused, then cut to "00001", which a second press booked as 1 instead of 12. Now what was refused comes
        // back exactly as typed (refused again until it is put right), and a note too long is never cut into one that passes.
        $site = self::makeChannel('vpg', 'live');
        self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$site, self::warehouseId('MAIN')]);
        self::$db->exec('UPDATE channel SET movement_types = ? WHERE id = ?', [json_encode(['goods_in']), $site]);
        (new Movements(self::$db))->record(Caller::channel($site, 'vpg'), ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-8', 'lines' => [['variant_id' => 'N8', 'qty' => 2,
            'description' => 'Peach 10ml']]], 'relay-ov8');
        $id = (int) self::$db->value('SELECT id FROM goods_in_suspense');
        $sku = self::makeSku('Peach 10ml');
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        $web = $this->signIn($this->uiUser('stock_controller'));
        $path = StoreQueuesController::PATH . '/suspense';
        $pick = $path . '/' . $id . '/pick';
        $r = $web->post($pick, ['item' => $code, 'units' => '000012', 'note' => 'twelve a line'] + $web->get($path)->form($pick));
        self::assertSame(400, $r->status, $r->describe());
        self::assertSame('000012', $r->form($pick)['units'] ?? null, 'never cut into another number');
        $again = $web->post($pick, $r->form($pick));
        self::assertSame(400, $again->status, 'refused again, nothing booked');
        self::assertSame(0, (int) (self::$db->value('SELECT on_hand FROM stock_balance WHERE sku_id = ?', [$sku]) ?? 0));
        $long = str_repeat('n', 501);
        $r = $web->post($pick, ['item' => $code, 'units' => '1', 'note' => $long] + $web->get($path)->form($pick));
        self::assertSame(400, $r->status);
        self::assertSame($long, $r->form($pick)['note'] ?? null, 'a note too long is not cut into one that passes');
        self::assertSame(400, $web->post($pick, $r->form($pick))->status);
        self::assertSame('open', (string) self::$db->value('SELECT CAST(status AS CHAR) FROM goods_in_suspense WHERE id = ?', [$id]));
    }
}
