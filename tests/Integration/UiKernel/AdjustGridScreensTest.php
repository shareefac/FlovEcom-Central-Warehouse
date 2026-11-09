<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The quick adjustment screen (docs/decisions.md AG1-AG8; the owner's drawing of 2 Nov 2026) through the real /ui kernel: one page with nothing saved until a
 * button is pressed, the lines sent in one field, the reason for every line, a refusal that keeps what was typed and leaves nothing behind, the same screen on a
 * saved draft, and the product box's fragment with the stock of the warehouse chosen.
 */
final class AdjustGridScreensTest extends KernelUiTestCase
{
    private const NEW = '/ui/stock/adjustments/new';

    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    /** @param array<int, array<string, mixed>> $rows @param array<string, string> $over */
    private function send(KernelBrowser $web, string $page, string $action, array $rows, array $over = []): UiResponse
    {
        $p = $web->get($page);
        self::assertSame(200, $p->status, $p->describe() . ' ' . implode(' | ', self::$log));
        $form = $p->form($action, true);
        self::assertNotSame([], $form, "no form: " . $p->describe());
        return $web->post($action, ['lines' => (string) json_encode($rows)] + $over + $form);
    }

    /** Where a browser's fetch lands after a 303 (it follows the redirect): the GET of the location, its query as parameters. */
    private static function follow(KernelBrowser $web, UiResponse $r): UiResponse
    {
        $to = parse_url((string) $r->location());
        parse_str($to['query'] ?? '', $query);
        return $web->get((string) ($to['path'] ?? ''), array_map('strval', $query));
    }

    private static function docs(): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'ADJ'");
    }

    private static function lastDoc(): int
    {
        return (int) self::$db->value("SELECT MAX(id) FROM document WHERE doc_type = 'ADJ'");
    }

    /** @return list<array{qty: int, reason: ?string}> */
    private static function lines(int $id): array
    {
        return array_map(static fn (array $l): array => ['qty' => (int) $l['qty'], 'reason' => $l['reason_code']],
            self::$db->all('SELECT qty, reason_code FROM document_line WHERE document_id = ? ORDER BY line_no', [$id]));
    }

    private static function onHand(int $sku): int
    {
        return (int) self::$db->value('SELECT SUM(on_hand) FROM stock_balance WHERE sku_id = ?', [$sku]);
    }

    /** @return array<string, string> the data attributes of the grid form's rows, decoded */
    private static function rows(UiResponse $page): array
    {
        $x = new \DOMXPath($page->dom());
        $n = $x->query('//form[@data-aj-grid]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $n, 'the grid is drawn: ' . $page->describe());
        return json_decode($n->getAttribute('data-rows'), true);
    }

    public function testTheListSendsTheNewButtonToTheOnePageAndNothingIsSavedByOpeningIt(): void
    {
        $web = $this->signIn($this->uiUser('stock_controller'));
        $before = self::docs();
        $list = $web->get('/ui/stock/adjustments');
        self::assertContains(self::NEW, $list->hrefs(), 'New adjustment opens the one page');
        self::assertFalse($list->hasForm('/ui/stock/adjustments'), 'no start form on the list');
        $page = $web->get(self::NEW);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::ADJ_GRID['why_all'], $page->text());
        self::assertSame(Words::ADJ_GRID['product_one'], (new \DOMXPath($page->dom()))->evaluate('string(//*[@data-aj-count="products"]/@data-one)'), '1 product, not 1 products');
        self::assertSame($before, self::docs(), 'opening the page makes no record');
    }

    public function testMakeItFinalFromTheNewPageBooksEveryLineInOneGoWithTheReasonForEveryLine(): void
    {
        $a = $this->item('strict', 10, 'Grid pod A');
        $b = $this->item('strict', 10, 'Grid pod B');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $r = $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '+3', 'r' => ''], ['sku' => $b, 'u' => '-2', 'r' => 'damaged']], ['why' => 'data_correction', 'note' => 'Counted', 'action' => 'post']);
        self::assertSame(303, $r->status, (string) $r->errorCode() . ' ' . trim((string) (new \DOMXPath($r->dom()))->evaluate('string(//p[@role="alert"])')));
        $id = self::lastDoc();
        self::assertStringContainsString('/ui/stock/adjustments/' . $id, (string) $r->location());
        self::assertSame([['qty' => 3, 'reason' => 'data_correction'], ['qty' => -2, 'reason' => 'damaged']], self::lines($id));
        self::assertSame('posted', (string) self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));
        self::assertSame(13, self::onHand($a));
        self::assertSame(8, self::onHand($b));
    }

    public function testSaveForLaterKeepsADraftAndItsScreenShowsTheLinesAsTyped(): void
    {
        $a = $this->item('strict', 10, 'Grid pod C');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $r = $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '-4', 'r' => '']], ['why' => 'data_correction', 'action' => 'save']);
        self::assertSame(303, $r->status, $r->describe());
        $id = self::lastDoc();
        self::assertSame('draft', (string) self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));
        self::assertSame(10, self::onHand($a), 'a draft books nothing');
        $page = $web->get('/ui/stock/adjustments/' . $id);
        $rows = self::rows($page);
        self::assertCount(1, $rows);
        self::assertSame(['sku' => $a, 'u' => '-4', 'r' => ''], ['sku' => $rows[0]['sku'], 'u' => $rows[0]['u'], 'r' => $rows[0]['r']], 'its reason is the one for every line: Same as above');
        self::assertSame(10, $rows[0]['hand']);
    }

    public function testADraftIsChangedAndMadeFinalOnTheSameScreen(): void
    {
        $a = $this->item('strict', 10, 'Grid pod D');
        $b = $this->item('strict', 10, 'Grid pod E');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '-4', 'r' => '']], ['why' => 'data_correction', 'action' => 'save']);
        $id = self::lastDoc();
        $page = '/ui/stock/adjustments/' . $id;
        $r = $this->send($web, $page, $page . '/grid', [['sku' => $b, 'u' => '+1', 'r' => '']], ['why' => 'data_correction', 'note' => 'Counted', 'external_ref' => 'REF-9', 'action' => 'post']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([['qty' => 1, 'reason' => 'data_correction']], self::lines($id), 'the lines on the screen replace the saved ones');
        self::assertSame('REF-9', (string) self::$db->value('SELECT external_ref FROM document WHERE id = ?', [$id]));
        self::assertSame(10, self::onHand($a));
        self::assertSame(11, self::onHand($b));
    }

    public function testALineThatStaysKeepsItsStoredCostAndDescription(): void
    {
        $a = $this->item('strict', 10, 'Grid pod K');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '+2', 'r' => '']], ['why' => 'data_correction', 'action' => 'save']);
        $id = self::lastDoc();
        self::$db->exec("UPDATE document_line SET unit_cost = '3.000000', description = 'Kept words' WHERE document_id = ?", [$id]);
        $page = '/ui/stock/adjustments/' . $id;
        $r = $this->send($web, $page, $page . '/grid', [['sku' => $a, 'u' => '+5', 'r' => '', 'l' => 1]], ['why' => 'data_correction', 'action' => 'save']);
        self::assertSame(303, $r->status, $r->describe());
        $l = self::$db->one('SELECT qty, unit_cost, description FROM document_line WHERE document_id = ?', [$id]);
        self::assertSame([5, 3.0, 'Kept words'], [(int) $l['qty'], (float) $l['unit_cost'], (string) $l['description']]);
    }

    public function testRemovingTheFirstOfTwoLinesOfAProductLeavesTheOtherLinesCost(): void
    {
        $a = $this->item('strict', 10, 'Grid pod O');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '+1', 'r' => ''], ['sku' => $a, 'u' => '+2', 'r' => '']], ['why' => 'data_correction', 'action' => 'save']);
        $id = self::lastDoc();
        self::$db->exec("UPDATE document_line SET unit_cost = IF(line_no = 1, '3.000000', '7.000000'), description = IF(line_no = 1, 'First', 'Second') WHERE document_id = ?", [$id]);
        $page = '/ui/stock/adjustments/' . $id;
        $drawn = self::rows($web->get($page));
        self::assertSame([1, 2], [$drawn[0]['l'], $drawn[1]['l']], 'each row says which saved line it stands for');
        $r = $this->send($web, $page, $page . '/grid', [['sku' => $a, 'u' => '+2', 'r' => '', 'l' => 2]], ['why' => 'data_correction', 'action' => 'save']);
        self::assertSame(303, $r->status, $r->describe());
        $l = self::$db->one('SELECT unit_cost, description FROM document_line WHERE document_id = ?', [$id]);
        self::assertSame([7.0, 'Second'], [(float) $l['unit_cost'], (string) $l['description']]);
    }

    public function testTooManyLinesKeepsWhatWasTyped(): void
    {
        $a = $this->item('strict', 10, 'Grid pod L');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $r = $this->send($web, self::NEW, self::NEW, array_fill(0, 501, ['sku' => $a, 'u' => '+1', 'r' => '']), ['why' => 'data_correction', 'action' => 'save']);
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame('too_many_lines', $r->errorCode());
        self::assertCount(501, self::rows($r));
    }

    public function testAProductJoinedMeanwhileStaysOnTheScreenAndIsRefused(): void
    {
        $a = $this->item('strict', 10, 'Grid pod M');
        $b = $this->item('strict', 10, 'Grid pod N');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get(self::NEW);
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$b, $a]);
        $r = $web->post(self::NEW, ['lines' => (string) json_encode([['sku' => $a, 'u' => '+1', 'r' => '']]), 'why' => 'data_correction', 'action' => 'save'] + $page->form(self::NEW, true));
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame($a, self::rows($r)[0]['sku'], 'the line stays until someone removes it');
    }

    public function testARefusedNewFormLeavesNoRecordAndKeepsWhatWasTyped(): void
    {
        $a = $this->item('strict', 10, 'Grid pod F');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $before = self::docs();
        foreach (['', '0', 'ten'] as $units) {
            $r = $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => $units, 'r' => '']], ['why' => 'data_correction', 'external_ref' => 'KEPT', 'action' => 'post']);
            self::assertSame(422, $r->status, $r->describe());
            self::assertStringContainsString('Line 1', $r->text());
            self::assertSame($a, self::rows($r)[0]['sku'], 'the line stays on the screen');
            self::assertSame($units, self::rows($r)[0]['u']);
            self::assertStringContainsString('KEPT', $r->dom()->saveHTML());
        }
        self::assertSame($before, self::docs(), 'a refusal leaves no record behind');
        $none = $this->send($web, self::NEW, self::NEW, [], ['why' => 'data_correction', 'note' => 'Counted', 'action' => 'post']);
        self::assertSame(422, $none->status, $none->describe());
        self::assertSame(Words::STOCK_ERROR['no_lines'], trim((string) (new \DOMXPath($none->dom()))->evaluate('string(//p[@data-code="no_lines"])')));
    }

    public function testAReasonThatGoesTheOtherWayIsSavedAsADraftNotMadeFinalAndSaysWhichLine(): void
    {
        $a = $this->item('strict', 10, 'Grid pod G');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $r = $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '+2', 'r' => 'damaged']], ['why' => 'data_correction', 'note' => 'Counted', 'action' => 'post']);
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame('draft', (string) self::$db->value('SELECT status FROM document WHERE id = ?', [self::lastDoc()]));
        self::assertSame(10, self::onHand($a));
        self::assertSame('reason_direction', $r->errorCode());
    }

    public function testAReasonThatNeedsANoteSaysSoAndTheOptionsCarryIt(): void
    {
        $a = $this->item('strict', 10, 'Grid pod J');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get(self::NEW);
        self::assertSame(2, (new \DOMXPath($page->dom()))->query('//option[@value="data_correction"][@data-note="1"]')->length, 'the box for every line and the line template');
        $r = $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '-1', 'r' => '']], ['why' => 'data_correction', 'action' => 'post']);
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame('note_required', $r->errorCode());
    }

    public function testAFormWhoseLinesAreNotAsDrawnIsRefused(): void
    {
        $web = $this->signIn($this->uiUser('stock_controller'));
        $form = $web->get(self::NEW)->form(self::NEW, true);
        foreach (['not json', '{"sku":1}', '[{"sku":"1","u":"1"}]', '[{"u":"1"}]', '[1]'] as $bad) {
            $r = $web->post(self::NEW, ['lines' => $bad, 'action' => 'save'] + $form);
            self::assertSame(400, $r->status, $bad . ': ' . $r->describe());
        }
    }

    public function testASecondSendMakesNoSecondRecord(): void
    {
        $a = $this->item('strict', 10, 'Grid pod H');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $form = $web->get(self::NEW)->form(self::NEW, true);
        $send = ['lines' => (string) json_encode([['sku' => $a, 'u' => '-1', 'r' => '']]), 'why' => 'data_correction', 'note' => 'Counted', 'action' => 'post'] + $form;
        $before = self::docs();
        self::assertSame(303, $web->post(self::NEW, $send)->status);
        self::assertSame(303, $web->post(self::NEW, $send)->status);
        self::assertSame($before + 1, self::docs());
        self::assertSame(9, self::onHand($a), 'booked once');
    }

    public function testTheItemPagesWayHereMakesTheItemTheFirstLine(): void
    {
        $a = $this->item('strict', 7, 'Grid pod I');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $r = $web->get('/ui/stock/adjustments', ['item' => (string) $a]);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(self::NEW . '?item=' . $a, $r->location());
        $page = $web->get(self::NEW, ['item' => (string) $a]);
        self::assertSame($a, self::rows($page)[0]['sku']);
        self::assertSame(7, self::rows($page)[0]['hand']);
        self::assertSame('', self::rows($page)[0]['u'], 'the units are for the person to type');
    }

    public function testTheProductBoxFragmentSaysTheStockOfTheWarehouse(): void
    {
        $a = $this->item('strict', 12, 'Zebra grid pod');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $wh = (int) self::$db->value("SELECT id FROM warehouse WHERE code = 'MAIN'");
        $f = $web->get('/ui/stock/adjustments/products', ['warehouse' => (string) $wh, 'q' => 'Zebra grid']);
        self::assertSame(200, $f->status, $f->describe());
        $x = new \DOMXPath($f->dom());
        self::assertSame(1, $x->query('//*[@data-aj-fragment]')->length, 'its root says what it is');
        $li = $x->query('//ul[@data-aj-choices]/li')->item(0);
        self::assertInstanceOf(\DOMElement::class, $li, $f->describe());
        self::assertSame((string) $a, $li->getAttribute('data-sku'));
        self::assertSame('12', $li->getAttribute('data-hand'));
        $by = $web->get('/ui/stock/adjustments/products', ['warehouse' => (string) $wh, 'q' => 'CW-' . str_pad((string) $a, 6, '0', STR_PAD_LEFT)]);
        self::assertSame((string) $a, (new \DOMXPath($by->dom()))->query('//ul[@data-aj-choices]/li')->item(0)->getAttribute('data-sku'), 'a CW number finds its product');
        $none = $web->get('/ui/stock/adjustments/products', ['warehouse' => (string) $wh, 'q' => 'nothing like this']);
        self::assertStringContainsString('nothing like this', $none->text());
        $short = $web->get('/ui/stock/adjustments/products', ['warehouse' => (string) $wh, 'q' => 'Z']);
        self::assertSame(0, (new \DOMXPath($short->dom()))->query('//ul[@data-aj-choices]/li')->length, 'one letter asks for nothing');
        $again = $web->get('/ui/stock/adjustments/products', ['warehouse' => (string) $wh, 'skus' => $a . ',0,x']);
        self::assertSame('12', (new \DOMXPath($again->dom()))->query('//ul[@data-aj-choices]/li')->item(0)->getAttribute('data-hand'), 'another warehouse asks again by product');
    }

    public function testOnlySomeoneWhoKeepsAdjustmentsMayUseIt(): void
    {
        $web = $this->signIn($this->uiUser('mapper'));
        self::assertSame(403, $web->get(self::NEW)->status);
        self::assertSame(403, $web->get('/ui/stock/adjustments/products', ['q' => 'pod'])->status);
    }

    // ---- The site's keys and a save without leaving the page (docs/decisions.md AJ; CW-920 part 2) ----

    public function testTheGridHasTheSitesKeysAndIsSavedWithoutLeavingThePage(): void
    {
        $a = $this->item('strict', 10, 'Grid pod P');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $x = new \DOMXPath($web->get(self::NEW)->dom());
        $form = $x->query('//form[@data-aj-grid]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        self::assertSame('adj:new', $form->getAttribute('data-kg-editor'), 'a new adjustment: the editor the one fetch may send (KG24)');
        self::assertFalse($form->hasAttribute('data-kg-address'), 'no address until it is saved');
        foreach (['data-saving' => 'saving', 'data-saved' => 'saved', 'data-save-failed' => 'save_failed', 'data-removed' => 'removed'] as $attr => $w) {
            self::assertSame(Words::DOC_GRID[$w], $form->getAttribute($attr), $attr);
        }
        self::assertSame(1, $x->query('//form[@data-aj-grid]//p[contains(@class,"kg-keys")]//button[@data-kb-open]')->length, 'the hint line under the lines, with All keys');
        $hints = $x->evaluate('string(//form[@data-aj-grid]//p[contains(@class,"kg-keys")])');
        foreach (['kbd_new', 'kbd_del', 'kbd_undo', 'kbd_copy', 'kbd_save'] as $k) {
            self::assertStringContainsString(Words::DOC_GRID[$k], $hints, $k);
        }
        self::assertSame(1, $x->query('//form[@data-aj-grid]//*[@data-kg-undo-line][@hidden]//button[@data-kg-undo]')->length, 'the Undo line, hidden until a line is removed');
        self::assertSame('Control+S', $x->evaluate('string(//form[@data-aj-grid]//button[@name="action"][@value="save"]/@aria-keyshortcuts)'));
        self::assertSame('', $x->evaluate('string(//form[@data-aj-grid]//button[@name="action"][@value="post"]/@aria-keyshortcuts)'), 'no key makes it final');
        $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '+1', 'r' => '']], ['why' => 'data_correction', 'action' => 'save']);
        $id = self::lastDoc();
        $d = new \DOMXPath($web->get('/ui/stock/adjustments/' . $id)->dom());
        self::assertSame('adj:' . $id, $d->evaluate('string(//form[@data-aj-grid]/@data-kg-editor)'), 'the draft: its own editor');
        self::assertSame('/ui/stock/adjustments/' . $id, $d->evaluate('string(//form[@data-aj-grid]/@data-kg-address)'), 'and its address (the address bar after a first save)');
        self::assertSame(1, $d->query('//form[@data-aj-grid]//p[contains(@class,"kg-keys")]')->length);
        self::assertSame(0, $d->query('//form[@data-aj-grid][@data-kg-conflict]')->length);
    }

    public function testEachSaveIsAnsweredWithTheDraftsEditorAndItsNewVersion(): void
    {
        $a = $this->item('strict', 10, 'Grid pod Q');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $before = self::docs();
        $first = $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '+1', 'r' => '']], ['why' => 'data_correction', 'action' => 'save']);
        self::assertSame(303, $first->status, $first->describe());
        $id = self::lastDoc();
        $page = self::follow($web, $first); // where the page's fetch lands (it follows the 303)
        self::assertSame(200, $page->status, $page->describe());
        $x = new \DOMXPath($page->dom());
        self::assertSame('adj:' . $id, $x->evaluate('string(//form[@data-kg-editor]/@data-kg-editor)'), 'the answer is this draft\'s editor');
        self::assertSame('/ui/stock/adjustments/' . $id . '/grid', $x->evaluate('string(//form[@data-kg-editor]/@action)'));
        self::assertSame(0, $x->query('//form[@data-kg-editor]/input[@name="form_key"]')->length, 'a draft is sent with its version, not a FormOnce key');
        $v = (int) $x->evaluate('string(//form[@data-kg-editor]/input[@name="version"]/@value)');
        self::assertGreaterThan(0, $v);
        self::assertSame(Words::STOCK_NOTICE['lines'], $x->evaluate('string(//form[@data-kg-editor]/@data-aj-notice)'), 'the words the page says (the layout\'s notice is a toast outside the part put in place)');
        $second = $this->send($web, '/ui/stock/adjustments/' . $id, '/ui/stock/adjustments/' . $id . '/grid', [['sku' => $a, 'u' => '+2', 'r' => '', 'l' => 1]],
            ['why' => 'data_correction', 'action' => 'save']);
        self::assertSame(303, $second->status, $second->describe());
        $y = new \DOMXPath(self::follow($web, $second)->dom());
        self::assertGreaterThan($v, (int) $y->evaluate('string(//form[@data-kg-editor]/input[@name="version"]/@value)'), 'the new version, for the next save');
        self::assertSame([['qty' => 2, 'reason' => 'data_correction']], self::lines($id));
        self::assertSame($before + 1, self::docs(), 'one record');
    }

    public function testAVersionConflictIsMarkedSoThePageKeepsWhatWasTyped(): void
    {
        $a = $this->item('strict', 10, 'Grid pod S');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $this->send($web, self::NEW, self::NEW, [['sku' => $a, 'u' => '+1', 'r' => '']], ['why' => 'data_correction', 'action' => 'save']);
        $id = self::lastDoc();
        $page = '/ui/stock/adjustments/' . $id;
        $drawn = $web->get($page)->form($page . '/grid', true);
        $ok = $web->post($page . '/grid', ['lines' => (string) json_encode([['sku' => $a, 'u' => '+2', 'r' => '', 'l' => 1]]), 'why' => 'data_correction', 'action' => 'save'] + $drawn);
        self::assertSame(303, $ok->status, $ok->describe());
        // The same form again (another tab, or a second press that got through): changed meanwhile.
        $late = $web->post($page . '/grid', ['lines' => (string) json_encode([['sku' => $a, 'u' => '+5', 'r' => '', 'l' => 1]]), 'why' => 'data_correction', 'action' => 'save'] + $drawn);
        self::assertSame(409, $late->status, $late->describe());
        self::assertSame('version_conflict', $late->errorCode());
        self::assertSame('1', (new \DOMXPath($late->dom()))->evaluate('string(//form[@data-aj-grid]/@data-kg-conflict)'), 'marked: the page keeps the lines typed');
        self::assertSame([['qty' => 2, 'reason' => 'data_correction']], self::lines($id), 'nothing of the late send saved');
        // A refusal that is not a conflict: no mark; the typed lines come back.
        $now = $web->get($page)->form($page . '/grid', true);
        $bad = $web->post($page . '/grid', ['lines' => (string) json_encode([['sku' => $a, 'u' => 'ten', 'r' => '', 'l' => 1]]), 'why' => 'data_correction', 'action' => 'save'] + $now);
        self::assertSame(422, $bad->status, $bad->describe());
        self::assertSame(0, (new \DOMXPath($bad->dom()))->query('//form[@data-aj-grid][@data-kg-conflict]')->length);
        self::assertSame('ten', self::rows($bad)[0]['u']);
    }

    public function testACountOf200And500LinesIsSavedInOneSend(): void
    {
        $a = $this->item('strict', 1000, 'Grid pod R');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $r = $this->send($web, self::NEW, self::NEW, array_fill(0, 200, ['sku' => $a, 'u' => '+1', 'r' => '']), ['why' => 'data_correction', 'action' => 'save']);
        self::assertSame(303, $r->status, $r->describe());
        $id = self::lastDoc();
        self::assertCount(200, self::lines($id));
        $page = '/ui/stock/adjustments/' . $id;
        $rows = [];
        for ($i = 1; $i <= 500; $i++) {
            $rows[] = ['sku' => $a, 'u' => '-1', 'r' => '', 'l' => $i <= 200 ? $i : 0];
        }
        $r2 = $this->send($web, $page, $page . '/grid', $rows, ['why' => 'data_correction', 'action' => 'save']);
        self::assertSame(303, $r2->status, $r2->describe());
        self::assertCount(500, self::lines($id), 'StockOps::MAX_LINES lines in one send (the biggest ERPNext count in 90 days: 162)');
        self::assertCount(500, self::rows($web->get($page)), 'the screen draws all 500');
    }
}
